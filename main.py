import os
import re
import sys
import json
import time
import glob
import math
import shutil
import urllib.parse
import zipfile
import asyncio
import threading
import subprocess
from typing import Optional, List, Dict, Any

import httpx
from fastapi import FastAPI, Request, Form, Query, HTTPException, status
from fastapi.responses import HTMLResponse, JSONResponse, StreamingResponse, FileResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

APP_DIR = os.path.dirname(os.path.abspath(__file__))
TEMP_ROOT = os.path.join(APP_DIR, "storage", "temp")
TEMP_PROGRESS = os.path.join(TEMP_ROOT, "progress")
TEMP_FILES = os.path.join(TEMP_ROOT, "files")
TEMP_CACHE = os.path.join(TEMP_ROOT, "cache")
COOKIES_FILE = os.path.join(APP_DIR, "cookies.txt")

for d in [TEMP_PROGRESS, TEMP_FILES, TEMP_CACHE]:
    os.makedirs(d, exist_ok=True)

YTDLP_BIN = os.path.join(APP_DIR, "venv", "bin", "yt-dlp")
if not os.path.isfile(YTDLP_BIN) or not os.access(YTDLP_BIN, os.X_OK):
    YTDLP_BIN = "/usr/local/bin/yt-dlp-resilient"
if not os.path.isfile(YTDLP_BIN) or not os.access(YTDLP_BIN, os.X_OK):
    YTDLP_BIN = shutil.which("yt-dlp") or "yt-dlp"

app = FastAPI(title="Vid Masamune API", docs_url=None, redoc_url=None)

# Mount static assets
app.mount("/assets", StaticFiles(directory=os.path.join(APP_DIR, "public", "assets")), name="assets")
templates = Jinja2Templates(directory=os.path.join(APP_DIR, "templates"))

# Global job tracking in memory for fast status reads
JOBS: Dict[str, Dict[str, Any]] = {}
JOBS_LOCK = threading.Lock()

def get_history_file() -> str:
    return os.path.join(TEMP_PROGRESS, ".download-history.json")

def get_download_history() -> Dict[str, int]:
    h_file = get_history_file()
    if not os.path.isfile(h_file):
        return {"videos": 0, "audios": 0, "images": 0, "total": 0}
    try:
        with open(h_file, "r", encoding="utf-8") as f:
            data = json.load(f)
            return {
                "videos": int(data.get("videos", 0)),
                "audios": int(data.get("audios", 0)),
                "images": int(data.get("images", 0)),
                "total": int(data.get("total", 0)),
            }
    except Exception:
        return {"videos": 0, "audios": 0, "images": 0, "total": 0}

def log_download(filename: str, ftype: str = "video") -> bool:
    h_file = get_history_file()
    h = get_download_history()
    key_map = {"video": "videos", "audio": "audios", "image": "images"}
    k = key_map.get(ftype, "videos")
    h[k] = h.get(k, 0) + 1
    h["total"] = h.get("total", 0) + 1
    try:
        with open(h_file, "w", encoding="utf-8") as f:
            json.dump(h, f, indent=2)
        return True
    except Exception:
        return False

def format_size(bytes_val: int) -> str:
    if bytes_val <= 0:
        return "0 Bytes"
    k = 1024
    sizes = ["Bytes", "KB", "MB", "GB", "TB"]
    i = int(math.floor(math.log(bytes_val) / math.log(k)))
    i = max(0, min(i, len(sizes) - 1))
    return f"{round(bytes_val / (k ** i), 2)} {sizes[i]}"

def format_speed(bps: float) -> str:
    if bps <= 0:
        return "0 MB/s"
    return f"{format_size(int(bps))}/s"

def format_eta(seconds: int) -> str:
    if seconds <= 0:
        return "--:--"
    h = seconds // 3600
    m = (seconds % 3600) // 60
    s = seconds % 60
    return f"{h}:{m:02d}:{s:02d}" if h > 0 else f"{m:02d}:{s:02d}"

def get_resolution_string(fmt: dict) -> str:
    if fmt.get("resolution"):
        return str(fmt["resolution"])
    h = fmt.get("height")
    if h:
        h = int(h)
        if h >= 2160:
            return "4K"
        if h >= 1440:
            return "2K"
        if h >= 1080:
            return "1080p"
        if h >= 720:
            return "720p"
        if h >= 480:
            return "480p"
        if h >= 360:
            return "360p"
        return f"{h}p"
    return "Unknown"

def tiktok_user_agent() -> str:
    return ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) "
            "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1")

def expand_tiktok_short_url(url: str) -> str:
    parsed = urllib.parse.urlparse(url)
    host = parsed.netloc.lower()
    path = parsed.path
    if "tiktok.com" not in host:
        return url
    is_short = host in ["vt.tiktok.com", "vm.tiktok.com"] or path.startswith("/t/")
    if not is_short:
        return url
    try:
        with httpx.Client(follow_redirects=True, timeout=10.0, headers={"User-Agent": tiktok_user_agent()}) as client:
            resp = client.head(url)
            effective = str(resp.url)
            if "tiktok.com" in urllib.parse.urlparse(effective).netloc.lower():
                return effective
    except Exception:
        pass
    return url

def normalize_tiktok_photo_url(url: str) -> str:
    url = expand_tiktok_short_url(url)
    parsed = urllib.parse.urlparse(url)
    if "tiktok.com" not in parsed.netloc.lower():
        return url
    match = re.match(r"^/(@[\w.-]+)/photo/(\d+)", parsed.path)
    if match:
        return f"{parsed.scheme or 'https'}://{parsed.netloc}/{match.group(1)}/video/{match.group(2)}"
    return url

def extract_tiktok_images(url: str) -> List[str]:
    try:
        headers = {
            "User-Agent": tiktok_user_agent(),
            "Referer": "https://www.tiktok.com/",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
        }
        with httpx.Client(follow_redirects=True, timeout=15.0, headers=headers) as client:
            resp = client.get(url)
            if resp.status_code >= 400:
                return []
            html = resp.text

        candidates = []
        # Check script tags
        for script_id in ["__UNIVERSAL_DATA_FOR_REHYDRATION__", "SIGI_STATE", "__NEXT_DATA__", "api-data"]:
            pattern = rf'<script[^>]+id=["\']{re.escape(script_id)}["\'][^>]*>(.*?)</script>'
            matches = re.findall(pattern, html, re.DOTALL | re.IGNORECASE)
            for m in matches:
                try:
                    data = json.loads(m)
                    candidates.append(data)
                except Exception:
                    pass

        # Helper to traverse JSON node
        img_urls = []
        def traverse(node):
            if isinstance(node, dict):
                if "imagePost" in node and isinstance(node["imagePost"], dict):
                    images = node["imagePost"].get("images") or []
                    for img in images:
                        if isinstance(img, dict):
                            for key in ["imageURL", "image_url", "display_image"]:
                                if key in img and isinstance(img[key], dict):
                                    url_list = img[key].get("urlList") or img[key].get("url_list") or []
                                    if url_list:
                                        img_urls.append(url_list[0])
                if "image_post_info" in node and isinstance(node["image_post_info"], dict):
                    images = node["image_post_info"].get("images") or []
                    for img in images:
                        if isinstance(img, dict):
                            for key in ["display_image", "imageURL", "image_url"]:
                                if key in img and isinstance(img[key], dict):
                                    url_list = img[key].get("urlList") or img[key].get("url_list") or []
                                    if url_list:
                                        img_urls.append(url_list[0])
                for v in node.values():
                    traverse(v)
            elif isinstance(node, list):
                for item in node:
                    traverse(item)

        for c in candidates:
            traverse(c)

        # Deduplicate while preserving order
        seen = set()
        deduped = []
        for u in img_urls:
            if u and u not in seen:
                seen.add(u)
                deduped.append(u)
        return deduped
    except Exception:
        return []

def sanitize_filename(title: str, max_len: int = 100) -> str:
    # Only remove illegal filesystem characters, preserve Unicode letters and digits
    title = re.sub(r'[\\/*?:"<>|]+', '_', title.strip())
    title = re.sub(r'[\s_]+', '_', title)
    title = title.strip("._- ")
    if len(title) > max_len:
        title = title[:max_len]
    return title.rstrip("._-") or "media"

def next_daily_sequence(date_str: str) -> int:
    seq_file = os.path.join(TEMP_PROGRESS, f"download-seq-{date_str}.txt")
    lock_file = os.path.join(TEMP_PROGRESS, ".download-seq.lock")
    seq = 1
    try:
        if os.path.isfile(seq_file):
            with open(seq_file, "r", encoding="utf-8") as f:
                content = f.read().strip()
                if content.isdigit():
                    seq = int(content) + 1
        with open(seq_file, "w", encoding="utf-8") as f:
            f.write(str(seq))
    except Exception:
        pass
    return seq

# Background Cleanup Sweeper
def cleanup_sweeper_task():
    while True:
        try:
            now = time.time()
            # Clean temp files older than 10 mins (600s)
            for fpath in glob.glob(os.path.join(TEMP_FILES, "*")):
                try:
                    if os.path.isfile(fpath) and (now - os.path.getmtime(fpath)) > 600:
                        os.remove(fpath)
                except Exception:
                    pass
            # Clean progress logs older than 30 mins (1800s)
            for fpath in glob.glob(os.path.join(TEMP_PROGRESS, "*")):
                if os.path.basename(fpath).startswith(".download-history"):
                    continue
                try:
                    if os.path.isfile(fpath) and (now - os.path.getmtime(fpath)) > 1800:
                        os.remove(fpath)
                except Exception:
                    pass
        except Exception:
            pass
        time.sleep(60)

sweeper = threading.Thread(target=cleanup_sweeper_task, daemon=True)
sweeper.start()

# --- ROUTES ---

@app.get("/", response_class=HTMLResponse)
@app.head("/")
async def index_page(request: Request):
    history = get_download_history()
    css_path = os.path.join(APP_DIR, "public", "assets", "css", "glass.css")
    js_path = os.path.join(APP_DIR, "public", "assets", "js", "app.js")
    css_ver = int(os.path.getmtime(css_path)) if os.path.isfile(css_path) else int(time.time())
    js_ver = int(os.path.getmtime(js_path)) if os.path.isfile(js_path) else int(time.time())
    
    base_url = str(request.base_url).rstrip("/")
    # If behind Cloudflare proxy, prefer forwarded host
    proto = request.headers.get("x-forwarded-proto", "https")
    host = request.headers.get("x-forwarded-host") or request.headers.get("host")
    if host:
        base_url = f"{proto}://{host}"

    return templates.TemplateResponse(
        request=request,
        name="index.html",
        context={
            "base_url": base_url,
            "history": history,
            "css_version": css_ver,
            "js_version": js_ver,
        },
    )

@app.post("/api/index.php")
@app.post("/api/analyze")
@app.post("/api/download")
async def api_router(
    request: Request,
    action: Optional[str] = Query(None),
    url: Optional[str] = Form(None),
    format_id: Optional[str] = Form(None),
    format_type: Optional[str] = Form("video"),
    quality: Optional[str] = Form(""),
    format_data: Optional[str] = Form(""),
    container: Optional[str] = Form("mp4"),
    audio_format: Optional[str] = Form("mp3"),
):
    path = request.url.path
    if action == "analyze" or path.endswith("/analyze"):
        return await handle_analyze(url)
    elif action == "download" or path.endswith("/download"):
        return await handle_download(
            request=request,
            url=url,
            format_id=format_id,
            format_type=format_type,
            quality=quality,
            format_data=format_data,
            container=container,
            audio_format=audio_format,
        )
    return JSONResponse(status_code=400, content={"status": "error", "message": "Invalid request action"})

def fetch_tiktok_direct_api(url: str) -> Optional[dict]:
    try:
        resolved_url = expand_tiktok_short_url(url)
        headers = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
        }
        with httpx.Client(follow_redirects=True, headers=headers, timeout=12.0) as client:
            r = client.post("https://www.tikwm.com/api/", data={"url": resolved_url, "hd": 1})
            if r.status_code == 200:
                res = r.json()
                if res.get("code") == 0 and res.get("data"):
                    return res["data"]
    except Exception:
        pass
    return None

async def handle_analyze(url_raw: Optional[str]):
    if not url_raw or not url_raw.strip():
        return JSONResponse(status_code=400, content={"status": "error", "message": "URL tidak boleh kosong"})
    
    url_raw = url_raw.strip()
    parsed = urllib.parse.urlparse(url_raw)
    if parsed.scheme not in ["http", "https"]:
        return JSONResponse(status_code=400, content={"status": "error", "message": "URL tidak valid"})

    source_url = expand_tiktok_short_url(url_raw)
    analyze_url = normalize_tiktok_photo_url(url_raw)

    # TikTok Direct API Fallback Check
    if "tiktok.com" in source_url.lower():
        tt_data = fetch_tiktok_direct_api(source_url)
        if tt_data:
            title = tt_data.get("title") or "TikTok Video"
            cover = tt_data.get("cover") or tt_data.get("origin_cover") or ""
            author = tt_data.get("author", {}).get("nickname") or tt_data.get("author", {}).get("unique_id") or "TikTok Creator"
            duration_s = int(tt_data.get("duration") or 0)
            views = int(tt_data.get("play_count") or 0)
            
            available_formats = []
            audio_formats = []
            image_formats = []

            # Images
            images = tt_data.get("images") or []
            if images:
                for idx, img_url in enumerate(images):
                    image_formats.append({
                        "format_id": f"image_{str(idx + 1).zfill(3)}",
                        "ext": "jpg",
                        "type": "image",
                        "image_index": idx,
                        "image_number": idx + 1,
                        "image_count": len(images),
                        "image_url": img_url,
                        "filesize": "Image",
                        "filesize_bytes": 0,
                        "note": "Image only",
                    })

            # Video HD and Standard, or Slideshow video if images present
            play_url = tt_data.get("hdplay") or tt_data.get("play")
            if images:
                available_formats.append({
                    "format_id": "tiktok_slideshow",
                    "ext": "mp4",
                    "resolution": "1080p",
                    "height": 1080,
                    "width": 1920,
                    "filesize": "Video Slideshow",
                    "filesize_bytes": 0,
                    "vcodec": "h264",
                    "acodec": "aac",
                    "note": "Slideshow MP4",
                    "type": "muxed",
                    "bitrate": 0,
                })
            elif play_url:
                available_formats.append({
                    "format_id": "tiktok_hd",
                    "ext": "mp4",
                    "resolution": "1080p",
                    "height": 1080,
                    "width": 1920,
                    "filesize": format_size(int(tt_data.get("hd_size") or tt_data.get("size") or 0)),
                    "filesize_bytes": int(tt_data.get("hd_size") or tt_data.get("size") or 0),
                    "vcodec": "h264",
                    "acodec": "aac",
                    "note": "HD No Watermark",
                    "type": "muxed",
                    "bitrate": 0,
                    "direct_url": play_url,
                })
                available_formats.append({
                    "format_id": "tiktok_sd",
                    "ext": "mp4",
                    "resolution": "720p",
                    "height": 720,
                    "width": 1280,
                    "filesize": format_size(int(tt_data.get("size") or 0)),
                    "filesize_bytes": int(tt_data.get("size") or 0),
                    "vcodec": "h264",
                    "acodec": "aac",
                    "note": "Standard Quality",
                    "type": "muxed",
                    "bitrate": 0,
                    "direct_url": tt_data.get("play") or play_url,
                })

            music_url = tt_data.get("music_info", {}).get("play") or tt_data.get("music") or tt_data.get("play")
            if music_url:
                audio_formats.append({
                    "format_id": "tiktok_audio",
                    "ext": "mp3",
                    "filesize": "Audio",
                    "bitrate": "320kbps",
                    "acodec": "mp3",
                    "type": "audio",
                    "note": tt_data.get("music_info", {}).get("title") or "Original Sound",
                    "direct_url": music_url,
                })

            return JSONResponse({
                "status": "success",
                "title": title,
                "thumbnail": cover,
                "duration": format_eta(duration_s),
                "duration_seconds": duration_s,
                "uploader": author,
                "view_count": views,
                "available_formats": available_formats,
                "audio_formats": audio_formats,
                "image_formats": image_formats,
                "has_high_quality": True,
            })

    cmd = [
        YTDLP_BIN,
        "--cache-dir", TEMP_CACHE,
        "--no-warnings",
        "--no-playlist",
        "--remote-components", "ejs:github",
        "--extractor-args", "youtube:player_client=android,web",
        "-J",
    ]
    if os.path.isfile(COOKIES_FILE):
        cmd.extend(["--cookies", COOKIES_FILE])
    cmd.append(analyze_url)

    proc = await asyncio.create_subprocess_exec(
        *cmd,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )
    stdout, stderr = await proc.communicate()
    out_str = stdout.decode("utf-8", errors="ignore").strip()
    err_str = stderr.decode("utf-8", errors="ignore").strip()

    if proc.returncode != 0 or not out_str:
        msg = "Gagal menganalisis video"
        if err_str:
            lines = [l.strip() for l in err_str.split("\n") if l.strip()]
            for l in reversed(lines):
                if "ERROR:" in l or "WARNING:" in l:
                    msg = re.sub(r'^(ERROR:|WARNING:)\s*', '', l)
                    break
        return JSONResponse(status_code=400, content={"status": "error", "message": msg})

    # Extract JSON object starting from first '{'
    json_start = out_str.find('{')
    if json_start != -1:
        out_str = out_str[json_start:]

    try:
        data = json.loads(out_str)
        if data.get("_type") == "playlist" and data.get("entries"):
            # Unwrap first valid entry if single video playlist
            entries = [e for e in data["entries"] if e and isinstance(e, dict)]
            if entries:
                data = entries[0]
    except Exception:
        return JSONResponse(status_code=500, content={"status": "error", "message": "Output analyzer tidak valid"})

    available_formats = []
    video_only = []
    audio_only = []

    formats = data.get("formats") or []
    for f in formats:
        if not isinstance(f, dict):
            continue
        vcodec = str(f.get("vcodec") or "none")
        acodec = str(f.get("acodec") or "none")
        has_v = vcodec != "none"
        has_a = acodec != "none"

        if has_v and has_a:
            available_formats.append({
                "format_id": str(f.get("format_id", "")),
                "ext": str(f.get("ext", "")),
                "resolution": get_resolution_string(f),
                "height": int(f.get("height") or 0),
                "width": int(f.get("width") or 0),
                "filesize": format_size(int(f.get("filesize") or 0)) if f.get("filesize") else "Unknown",
                "filesize_bytes": int(f.get("filesize") or 0),
                "vcodec": vcodec,
                "acodec": acodec,
                "note": str(f.get("format_note", "")),
                "type": "muxed",
                "bitrate": float(f.get("abr") or f.get("tbr") or 0),
            })
        elif has_v and not has_a:
            video_only.append({
                "format_id": str(f.get("format_id", "")),
                "ext": str(f.get("ext", "")),
                "resolution": get_resolution_string(f),
                "height": int(f.get("height") or 0),
                "width": int(f.get("width") or 0),
                "filesize": format_size(int(f.get("filesize") or 0)) if f.get("filesize") else "Unknown",
                "filesize_bytes": int(f.get("filesize") or 0),
                "vcodec": vcodec,
                "fps": float(f.get("fps") or 0),
                "dynamic_range": str(f.get("dynamic_range") or "SDR"),
                "type": "video",
                "bitrate": float(f.get("tbr") or 0),
            })
        elif has_a and not has_v:
            audio_only.append({
                "format_id": str(f.get("format_id", "")),
                "ext": str(f.get("ext", "")),
                "acodec": acodec,
                "filesize": format_size(int(f.get("filesize") or 0)) if f.get("filesize") else "Unknown",
                "filesize_bytes": int(f.get("filesize") or 0),
                "type": "audio",
                "bitrate": float(f.get("abr") or 0),
                "quality": int(f.get("quality") or 0),
            })

    # Pick best audio format for merge
    best_audio = None
    if audio_only:
        # Prefer m4a/aac
        pref = [a for a in audio_only if "mp4a" in a["acodec"].lower() or "aac" in a["acodec"].lower() or a["ext"] in ["m4a", "mp4"]]
        if pref:
            pref.sort(key=lambda x: x["bitrate"], reverse=True)
            best_audio = pref[0]
        else:
            audio_only.sort(key=lambda x: x["bitrate"], reverse=True)
            best_audio = audio_only[0]

    if video_only and best_audio:
        video_only.sort(key=lambda x: x["height"], reverse=True)
        for vf in video_only:
            available_formats.append({
                "format_id": f"{vf['format_id']}+{best_audio['format_id']}",
                "ext": "mp4",
                "resolution": vf["resolution"],
                "height": int(vf["height"]),
                "width": int(vf["width"]),
                "filesize": "Auto Merge",
                "filesize_bytes": 0,
                "vcodec": vf["vcodec"],
                "acodec": best_audio["acodec"],
                "note": "High Quality (merged)",
                "type": "combined",
                "video_id": vf["format_id"],
                "audio_id": best_audio["format_id"],
                "dynamic_range": vf.get("dynamic_range", "SDR"),
                "fps": vf.get("fps", 0),
            })

    available_formats.sort(key=lambda x: x.get("height", 0), reverse=True)

    audio_formats = []
    has_any_audio = False
    for f in formats:
        if not isinstance(f, dict):
            continue
        acodec = str(f.get("acodec") or "none")
        vcodec = str(f.get("vcodec") or "none")
        if acodec != "none":
            has_any_audio = True
        if acodec != "none" and vcodec == "none":
            abr = float(f.get("abr") or 0)
            audio_formats.append({
                "format_id": str(f.get("format_id", "")),
                "ext": str(f.get("ext", "")),
                "filesize": format_size(int(f.get("filesize") or 0)) if f.get("filesize") else "Unknown",
                "bitrate": f"{round(abr)}kbps" if abr > 0 else "Unknown",
                "acodec": acodec,
                "type": "audio",
            })
    if not audio_formats and has_any_audio:
        audio_formats.append({
            "format_id": "bestaudio",
            "ext": "mp3",
            "filesize": "Unknown",
            "bitrate": "Best",
            "acodec": "best",
            "type": "audio",
            "note": "Best available audio",
        })

    # TikTok / image extraction
    image_formats = []
    if "tiktok.com" in source_url.lower():
        extracted_imgs = extract_tiktok_images(source_url)
        for idx, i_url in enumerate(extracted_imgs):
            ext = i_url.split("?")[0].split(".")[-1].lower() if "." in i_url.split("?")[0] else "jpg"
            if ext not in ["jpg", "jpeg", "png", "webp"]:
                ext = "jpg"
            image_formats.append({
                "format_id": f"image_{str(idx + 1).zfill(3)}",
                "ext": ext,
                "type": "image",
                "image_index": idx,
                "image_number": idx + 1,
                "image_count": len(extracted_imgs),
                "image_url": i_url,
                "filesize": "Image",
                "filesize_bytes": 0,
                "note": "Image only",
            })
        if extracted_imgs and not available_formats:
            available_formats.append({
                "format_id": "tiktok_slideshow",
                "ext": "mp4",
                "resolution": "1080p",
                "height": 1080,
                "width": 1920,
                "filesize": "Video Slideshow",
                "filesize_bytes": 0,
                "vcodec": "h264",
                "acodec": "aac",
                "note": "Slideshow MP4",
                "type": "muxed",
                "bitrate": 0,
            })
        if extracted_imgs and not audio_formats:
            audio_formats.append({
                "format_id": "tiktok_audio",
                "ext": "mp3",
                "filesize": "Audio",
                "bitrate": "320kbps",
                "acodec": "mp3",
                "type": "audio",
                "note": "Original Sound",
            })

    thumbnail_url = str(data.get("thumbnail") or "")
    if not thumbnail_url and data.get("thumbnails"):
        t_list = [t.get("url") for t in data["thumbnails"] if isinstance(t, dict) and t.get("url")]
        if t_list:
            thumbnail_url = t_list[-1]

    return JSONResponse({
        "status": "success",
        "title": str(data.get("title") or "-"),
        "thumbnail": thumbnail_url,
        "duration": str(data.get("duration_string") or ""),
        "duration_seconds": int(data.get("duration") or 0),
        "uploader": str(data.get("uploader") or ""),
        "view_count": int(data.get("view_count") or 0),
        "available_formats": available_formats,
        "audio_formats": audio_formats[:5],
        "image_formats": image_formats,
        "has_high_quality": any((f.get("height") or 0) >= 1440 for f in available_formats),
    })

async def handle_download(
    request: Request,
    url: Optional[str],
    format_id: Optional[str],
    format_type: Optional[str],
    quality: Optional[str],
    format_data: Optional[str],
    container: Optional[str],
    audio_format: Optional[str],
):
    if not url or not url.strip():
        return JSONResponse(status_code=400, content={"status": "error", "message": "URL tidak boleh kosong"})
    
    raw_url = url.strip()
    download_url = normalize_tiktok_photo_url(raw_url)
    
    format_type = (format_type or "video").lower()
    format_id = (format_id or "").strip()
    container = (container or "mp4").lower()
    audio_format = (audio_format or "mp3").lower()

    format_info = {}
    if format_data:
        try:
            format_info = json.loads(format_data)
        except Exception:
            pass

    import secrets
    job_id = f"vd_{secrets.token_hex(16)}"

    progress_file = os.path.join(TEMP_PROGRESS, f"{job_id}.log")
    pid_file = os.path.join(TEMP_PROGRESS, f"{job_id}.pid")
    meta_file = os.path.join(TEMP_PROGRESS, f"{job_id}.json")
    output_tpl = os.path.join(TEMP_FILES, f"{job_id}_%(title).120s.%(ext)s")

    progress_template = 'download:[vd-progress] {"status":%(progress.status)j,"percent":%(progress._percent|0).1f,"downloaded":%(progress.downloaded_bytes|0)d,"total":%(progress.total_bytes|0)d,"total_est":%(progress.total_bytes_estimate|0)d,"speed":%(progress.speed|0).0f,"eta":%(progress.eta|0).0f}'

    cmd_parts = [
        YTDLP_BIN,
        "--cache-dir", TEMP_CACHE,
        "--no-playlist",
        "--remote-components", "ejs:github",
        "--extractor-args", "youtube:player_client=android,web",
        "--newline",
        "--progress",
        "--progress-template", progress_template,
        "--progress-delta", "1",
        "--restrict-filenames",
        "--retries", "10",
        "--fragment-retries", "10",
        "--socket-timeout", "30",
    ]
    if os.path.isfile(COOKIES_FILE):
        cmd_parts.extend(["--cookies", COOKIES_FILE])

    format_selector = ""
    expects_merge = False

    if "tiktok.com" in raw_url.lower() and (format_type in ["video", "audio"] or format_id.startswith("tiktok_")):
        def run_tiktok_download():
            try:
                title = sanitize_filename(format_info.get("title", "tiktok_media"), 120)
                tt_data = fetch_tiktok_direct_api(raw_url) or {}
                images = tt_data.get("images") or extract_tiktok_images(raw_url) or extract_tiktok_images(download_url) or []

                with open(progress_file, "w", encoding="utf-8") as pf:
                    pf.write('download:[vd-progress] {"status":"downloading","percent":10,"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}\n')

                headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)", "Referer": "https://www.tiktok.com/"}

                if format_type == "audio":
                    music_url = tt_data.get("music_info", {}).get("play") or tt_data.get("music") or tt_data.get("play")
                    if not music_url:
                        try:
                            with httpx.Client(headers=headers, follow_redirects=True, timeout=15.0) as client:
                                r_page = client.get(raw_url)
                                m_matches = re.findall(r'"playUrl":"([^"]+)"', r_page.text)
                                if m_matches:
                                    music_url = m_matches[0].encode().decode("unicode_escape")
                        except Exception:
                            pass

                    if not music_url:
                        raise Exception("Gagal mendapatkan link audio TikTok")

                    audio_fmt = audio_format if audio_format in ["mp3", "m4a", "opus", "flac", "wav"] else "mp3"
                    target_file = os.path.join(TEMP_FILES, f"{job_id}_{title}.{audio_fmt}")
                    temp_audio = os.path.join(TEMP_FILES, f"{job_id}_raw_audio.tmp")

                    with httpx.Client(follow_redirects=True, headers=headers, timeout=60.0) as client:
                        with client.stream("GET", music_url) as resp:
                            total_bytes = int(resp.headers.get("content-length", 0))
                            downloaded_b = 0
                            with open(temp_audio, "wb") as f_out:
                                for chunk in resp.iter_bytes(chunk_size=128 * 1024):
                                    if chunk:
                                        f_out.write(chunk)
                                        downloaded_b += len(chunk)
                                        pct = (downloaded_b / total_bytes * 50.0 + 10.0) if total_bytes > 0 else 50.0
                                        with open(progress_file, "w", encoding="utf-8") as pf:
                                            pf.write(f'download:[vd-progress] {{"status":"downloading","percent":{round(pct,1)},"downloaded":{downloaded_b},"total":{total_bytes},"total_est":{total_bytes},"speed":0,"eta":0}}\n')

                    with open(progress_file, "w", encoding="utf-8") as pf:
                        pf.write('download:[vd-progress] {"status":"processing","percent":85,"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}\n')

                    cmd = ["ffmpeg", "-y", "-i", temp_audio]
                    if audio_fmt == "mp3":
                        cmd.extend(["-c:a", "libmp3lame", "-b:a", "192k"])
                    elif audio_fmt in ["m4a", "aac"]:
                        cmd.extend(["-c:a", "aac", "-b:a", "192k"])
                    elif audio_fmt == "opus":
                        cmd.extend(["-c:a", "libopus", "-b:a", "128k"])
                    elif audio_fmt == "flac":
                        cmd.extend(["-c:a", "flac"])
                    elif audio_fmt == "wav":
                        cmd.extend(["-c:a", "pcm_s16le"])
                    cmd.append(target_file)

                    res = subprocess.run(cmd, capture_output=True)
                    if os.path.isfile(temp_audio):
                        try:
                            os.remove(temp_audio)
                        except Exception:
                            pass

                    if res.returncode != 0 or not os.path.isfile(target_file):
                        raise Exception("Gagal memproses file audio")

                    with open(progress_file, "w", encoding="utf-8") as pf:
                        pf.write('download:[vd-progress] {"status":"finished","percent":100,"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}\n')

                elif images:
                    # Video Slideshow from images + music
                    target_ext = container if container in ["mp4", "mkv", "webm"] else "mp4"
                    target_file = os.path.join(TEMP_FILES, f"{job_id}_{title}.{target_ext}")

                    downloaded_imgs = []
                    with httpx.Client(follow_redirects=True, headers=headers, timeout=30.0) as client:
                        for s_idx, img_url in enumerate(images):
                            img_target = os.path.join(TEMP_FILES, f"{job_id}_slide_{str(s_idx).zfill(3)}.jpg")
                            r = client.get(img_url)
                            if r.status_code == 200:
                                with open(img_target, "wb") as f:
                                    f.write(r.content)
                                downloaded_imgs.append(img_target)
                            pct = 10.0 + ((s_idx + 1) / len(images) * 45.0)
                            with open(progress_file, "w", encoding="utf-8") as pf:
                                pf.write(f'download:[vd-progress] {{"status":"downloading","percent":{round(pct,1)},"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}}\n')

                    if not downloaded_imgs:
                        raise Exception("Gagal mengunduh gambar untuk slideshow")

                    music_url = tt_data.get("music_info", {}).get("play") or tt_data.get("music") or tt_data.get("play")
                    if not music_url:
                        try:
                            with httpx.Client(headers=headers, follow_redirects=True, timeout=15.0) as client:
                                r_page = client.get(raw_url)
                                m_matches = re.findall(r'"playUrl":"([^"]+)"', r_page.text)
                                if m_matches:
                                    music_url = m_matches[0].encode().decode("unicode_escape")
                        except Exception:
                            pass

                    audio_tmp = os.path.join(TEMP_FILES, f"{job_id}_slideshow_audio.tmp")
                    has_audio = False
                    audio_duration = 0.0
                    if music_url:
                        try:
                            with httpx.Client(follow_redirects=True, headers=headers, timeout=30.0) as client:
                                r_aud = client.get(music_url)
                                if r_aud.status_code == 200 and len(r_aud.content) > 1000:
                                    with open(audio_tmp, "wb") as f:
                                        f.write(r_aud.content)
                                    has_audio = True
                        except Exception:
                            pass
                    if has_audio:
                        try:
                            probe = subprocess.run(
                                ["ffprobe", "-v", "error", "-show_entries", "format=duration",
                                 "-of", "default=noprint_wrappers=1:nokey=1", audio_tmp],
                                capture_output=True, text=True, timeout=30,
                            )
                            audio_duration = float((probe.stdout or "").strip() or 0)
                        except Exception:
                            audio_duration = 0.0

                    with open(progress_file, "w", encoding="utf-8") as pf:
                        pf.write('download:[vd-progress] {"status":"processing","percent":70,"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}\n')

                    concat_txt = os.path.join(TEMP_FILES, f"{job_id}_slideshow_concat.txt")
                    n_imgs = len(downloaded_imgs)
                    # Durasi video mengikuti panjang musik agar tidak "mentok" beberapa detik.
                    # Tanpa audio, pakai 3 detik per gambar.
                    if has_audio and audio_duration > 0:
                        duration_per_img = max(audio_duration / n_imgs, 1.5)
                    else:
                        duration_per_img = 3.0
                    total_duration = min(duration_per_img * n_imgs, 300.0)

                    with open(concat_txt, "w", encoding="utf-8") as f:
                        for ip in downloaded_imgs:
                            f.write(f"file '{ip}'\n")
                            f.write(f"duration {duration_per_img:.3f}\n")
                        f.write(f"file '{downloaded_imgs[-1]}'\n")

                    cmd = ["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", concat_txt]
                    if has_audio:
                        cmd.extend(["-stream_loop", "-1", "-i", audio_tmp])
                    cmd.extend(["-t", f"{total_duration:.3f}"])
                    cmd.extend([
                        "-vf", "scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:black,setsar=1,fps=30,format=yuv420p",
                        "-r", "30",
                        "-c:v", "libx264", "-preset", "veryfast", "-crf", "22",
                    ])
                    if has_audio:
                        cmd.extend(["-c:a", "aac", "-b:a", "192k"])
                    cmd.extend(["-pix_fmt", "yuv420p"])
                    if target_ext == "mp4":
                        cmd.extend(["-movflags", "+faststart"])
                    cmd.append(target_file)

                    res = subprocess.run(cmd, capture_output=True)

                    for ip in downloaded_imgs:
                        try:
                            os.remove(ip)
                        except Exception:
                            pass
                    for fp in [concat_txt, audio_tmp]:
                        try:
                            if os.path.exists(fp):
                                os.remove(fp)
                        except Exception:
                            pass

                    if res.returncode != 0 or not os.path.isfile(target_file):
                        raise Exception("Gagal membuat video slideshow TikTok")

                    with open(progress_file, "w", encoding="utf-8") as pf:
                        pf.write('download:[vd-progress] {"status":"finished","percent":100,"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}\n')

                else:
                    # Regular TikTok video
                    if format_id == "tiktok_sd":
                        download_stream_url = tt_data.get("play") or tt_data.get("hdplay")
                    else:
                        download_stream_url = tt_data.get("hdplay") or tt_data.get("play")

                    if not download_stream_url:
                        raise Exception("Gagal mendapatkan link download TikTok")

                    target_file = os.path.join(TEMP_FILES, f"{job_id}_{title}.mp4")

                    with httpx.Client(follow_redirects=True, headers=headers, timeout=60.0) as client:
                        with client.stream("GET", download_stream_url) as resp:
                            total_bytes = int(resp.headers.get("content-length", 0))
                            downloaded_b = 0
                            with open(target_file, "wb") as f_out:
                                for chunk in resp.iter_bytes(chunk_size=128 * 1024):
                                    if chunk:
                                        f_out.write(chunk)
                                        downloaded_b += len(chunk)
                                        pct = (downloaded_b / total_bytes * 100.0) if total_bytes > 0 else 50.0
                                        with open(progress_file, "w", encoding="utf-8") as pf:
                                            pf.write(f'download:[vd-progress] {{"status":"downloading","percent":{round(pct,1)},"downloaded":{downloaded_b},"total":{total_bytes},"total_est":{total_bytes},"speed":0,"eta":0}}\n')

                    with open(progress_file, "w", encoding="utf-8") as pf:
                        pf.write('download:[vd-progress] {"status":"finished","percent":100,"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}\n')

            except Exception as e:
                with open(progress_file, "w", encoding="utf-8") as pf:
                    pf.write(f'ERROR: {str(e)}\n')

        t = threading.Thread(target=run_tiktok_download, daemon=True)
        t.start()
        pid = 999999

    elif format_type == "image":
        # Launch dedicated Python image extraction
        def run_image_download():
            try:
                title = sanitize_filename(format_info.get("title", "images"), 100) or "tiktok_photos"
                indexes = format_info.get("image_indexes") or []
                if isinstance(format_info.get("image_index"), int):
                    indexes = [format_info["image_index"]]
                
                # Check format_id like image_001
                if not indexes and format_id:
                    m_idx = re.match(r"^image_(\d+)$", format_id)
                    if m_idx:
                        indexes = [max(0, int(m_idx.group(1)) - 1)]

                with open(progress_file, "w", encoding="utf-8") as pf:
                    pf.write('download:[vd-progress] {"status":"downloading","percent":15,"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}\n')
                
                # Get images from direct API first, then scraper fallback
                tt_data = fetch_tiktok_direct_api(raw_url) or {}
                img_urls = tt_data.get("images") or extract_tiktok_images(raw_url) or extract_tiktok_images(download_url)
                
                if not img_urls:
                    raise Exception("Tidak ada gambar yang ditemukan untuk link ini")

                if not indexes:
                    indexes = list(range(len(img_urls)))

                downloaded_files = []
                headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)", "Referer": "https://www.tiktok.com/"}
                with httpx.Client(follow_redirects=True, headers=headers, timeout=30.0) as client:
                    for s_idx in indexes:
                        if 0 <= s_idx < len(img_urls):
                            img_url = img_urls[s_idx]
                            target = os.path.join(TEMP_FILES, f"{job_id}_{title}_image_{str(s_idx+1).zfill(3)}.jpg")
                            r = client.get(img_url)
                            if r.status_code == 200:
                                with open(target, "wb") as f:
                                    f.write(r.content)
                                downloaded_files.append(target)

                if not downloaded_files:
                    raise Exception("Gagal mengunduh gambar TikTok")

                if len(downloaded_files) > 1:
                    with open(progress_file, "w", encoding="utf-8") as pf:
                        pf.write('download:[vd-progress] {"status":"processing","percent":95,"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}\n')
                    zip_path = os.path.join(TEMP_FILES, f"{job_id}_{title}_selected_images.zip")
                    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zipf:
                        for df in downloaded_files:
                            zipf.write(df, os.path.basename(df))
                            try:
                                os.remove(df)
                            except Exception:
                                pass

                with open(progress_file, "w", encoding="utf-8") as pf:
                    pf.write('download:[vd-progress] {"status":"finished","percent":100,"downloaded":0,"total":0,"total_est":0,"speed":0,"eta":0}\n')
            except Exception as e:
                with open(progress_file, "w", encoding="utf-8") as pf:
                    pf.write(f'ERROR: {str(e)}\n')

        t = threading.Thread(target=run_image_download, daemon=True)
        t.start()
        pid = 999999

    elif format_type == "audio":
        audio_fmt = audio_format if audio_format in ["mp3", "m4a", "opus", "flac", "wav"] else "mp3"
        cmd_parts.extend(["-x", "--audio-format", audio_fmt])
        if audio_fmt in ["mp3", "m4a", "opus"]:
            cmd_parts.extend(["--audio-quality", "0"])
        format_selector = "bestaudio/best"
        cmd_parts.extend(["-f", format_selector, "-o", output_tpl, download_url])

    else: # Video
        cont = container if container in ["mp4", "mkv", "webm"] else "mp4"
        height = int(quality) if (quality and quality.isdigit()) else 0
        hcap = f"[height<=?{height}]" if height > 0 else ""

        if cont == "webm":
            format_selector = f"bv*{hcap}[vcodec~='^(av01|vp0?9|vp8)']+ba[acodec~='^(opus|vorbis)']/bv*{hcap}[vcodec~='^(av01|vp0?9|vp8)']+ba/b{hcap}[ext=webm]/b{hcap}/b"
            cmd_parts.extend(["--merge-output-format", "webm"])
        elif cont == "mkv":
            format_selector = f"bv*{hcap}+ba/b{hcap}/b"
            cmd_parts.extend(["--merge-output-format", "mkv", "--remux-video", "mkv"])
        else: # mp4
            format_selector = f"bv*{hcap}[vcodec~='^(avc|h264)']+ba[acodec~='^(mp4a|aac)']/bv*{hcap}+ba/b{hcap}[ext=mp4]/b{hcap}/b"
            cmd_parts.extend(["--merge-output-format", "mp4", "--remux-video", "mp4"])

        expects_merge = True
        cmd_parts.extend(["-f", format_selector, "-o", output_tpl, download_url])

    is_tiktok_direct = "tiktok.com" in raw_url.lower() and (format_type in ["video", "audio"] or format_id.startswith("tiktok_"))

    if not is_tiktok_direct and format_type != "image":
        # Launch yt-dlp process
        with open(progress_file, "w", encoding="utf-8") as pf:
            pass

        proc = subprocess.Popen(
            cmd_parts,
            stdout=open(progress_file, "w", encoding="utf-8"),
            stderr=subprocess.STDOUT,
            stdin=subprocess.DEVNULL,
            start_new_session=True,
        )
        pid = proc.pid

    with open(pid_file, "w", encoding="utf-8") as f:
        f.write(str(pid))

    meta = {
        "job_id": job_id,
        "url": raw_url,
        "download_url": download_url,
        "format_id": format_id,
        "format": format_type,
        "quality": quality,
        "container": container if format_type == "video" else None,
        "audio_format": audio_format if format_type == "audio" else None,
        "format_selector": format_selector,
        "expects_merge": expects_merge,
        "title": format_info.get("title", ""),
        "start": time.time(),
        "pid": pid,
    }
    with open(meta_file, "w", encoding="utf-8") as f:
        json.dump(meta, f, indent=2)

    proto = request.headers.get("x-forwarded-proto", "https")
    host = request.headers.get("x-forwarded-host") or request.headers.get("host") or "vid.masamune.my.id"
    base = f"{proto}://{host}"
    progress_url = f"{base}/api/progress.php?id={job_id}"

    return JSONResponse({
        "status": "started",
        "job_id": job_id,
        "message": "Download dimulai",
        "progress_url": progress_url,
    })

@app.get("/api/progress.php")
@app.get("/api/progress")
async def progress_check(request: Request, id: Optional[str] = Query(None)):
    job_id = (id or "").strip()
    if not job_id or not re.match(r"^vd_[a-z0-9]+(?:\.[a-z0-9]+)?$", job_id, re.IGNORECASE):
        return JSONResponse(status_code=400, content={"status": "error", "message": "Job ID tidak valid"})

    progress_file = os.path.join(TEMP_PROGRESS, f"{job_id}.log")
    pid_file = os.path.join(TEMP_PROGRESS, f"{job_id}.pid")
    meta_file = os.path.join(TEMP_PROGRESS, f"{job_id}.json")

    meta = {}
    if os.path.isfile(meta_file):
        try:
            with open(meta_file, "r", encoding="utf-8") as f:
                meta = json.load(f)
        except Exception:
            pass

    # Check if process is running
    is_running = False
    pid = meta.get("pid")
    start_time = float(meta.get("start") or time.time())
    elapsed = time.time() - start_time

    if pid:
        pid_str = str(pid).strip()
        if pid_str == "999999":
            # Background thread job: cek status di progress_file
            if os.path.isfile(progress_file):
                try:
                    with open(progress_file, "r", encoding="utf-8", errors="ignore") as pf:
                        log_content = pf.read()
                        if '"status":"finished"' in log_content or "ERROR:" in log_content:
                            is_running = False
                        else:
                            is_running = elapsed < 300
                except Exception:
                    is_running = elapsed < 300
            else:
                is_running = elapsed < 300
        elif pid_str.isdigit():
            pid_int = int(pid_str)
            try:
                proc_status_file = f"/proc/{pid_int}/status"
                if os.path.isfile(proc_status_file):
                    with open(proc_status_file, "r") as pf:
                        content = pf.read()
                        if "State:\tZ" in content:
                            is_running = False
                        else:
                            is_running = True
                else:
                    os.kill(pid_int, 0)
                    is_running = True
            except (OSError, IOError):
                is_running = False

    # Check if completed file exists
    completed_file = None
    all_matching = glob.glob(os.path.join(TEMP_FILES, f"{job_id}*"))
    valid_finals = []
    for f in all_matching:
        base = os.path.basename(f)
        if any(base.endswith(ext) for ext in [".part", ".ytdl", ".temp"]) or not os.path.isfile(f):
            continue
        valid_finals.append(f)

    # Saring kandidat berdasarkan ekstensi hasil yang DIMINTA job ini.
    job_format = (meta.get("format") or "video").lower()
    expected_exts = []
    if job_format == "audio":
        expected_exts = [(meta.get("audio_format") or "mp3").lower()]
    elif job_format == "video":
        expected_exts = [(meta.get("container") or "mp4").lower()]

    if expected_exts:
        preferred = []
        for f in valid_finals:
            b = os.path.basename(f).lower()
            # Buang berkas antara yt-dlp yang belum tergabung / remux
            if re.search(r"\.f\d+\.[a-z0-9]+$", b) or ".temp." in b:
                continue
            if any(b.endswith(f".{e}") for e in expected_exts):
                preferred.append(f)

        if preferred:
            valid_finals = preferred
        elif is_running:
            # Hasil yang diminta belum ada dan proses masih bekerja — tunggu,
            # jangan finalisasi berkas antara.
            valid_finals = []

    if valid_finals:
        valid_finals.sort(key=lambda x: os.path.getsize(x), reverse=True)
        completed_file = valid_finals[0]

    # Hanya finalisasi jika proses BENAR-BENAR telah selesai (bukan saat masih downloading/merging)
    if completed_file and not is_running:
        # Rename to clean target name
        base_name = os.path.basename(completed_file)
        ext = base_name.split(".")[-1].lower()
        title_part = base_name
        if title_part.startswith(f"{job_id}_"):
            title_part = title_part[len(job_id) + 1:]
        if title_part.endswith(f".{ext}"):
            title_part = title_part[:-len(f".{ext}")]
        title_part = sanitize_filename(title_part, 120) or "video"

        proto = request.headers.get("x-forwarded-proto", "https")
        host = request.headers.get("x-forwarded-host") or request.headers.get("host") or "vid.masamune.my.id"
        host_clean = re.sub(r'[^A-Za-z0-9.-]+', '_', host.split(":")[0]).lower()
        today = time.strftime("%Y-%m-%d")
        seq = next_daily_sequence(today)
        target_name = f"{host_clean}_{today}_{str(seq).zfill(3)}_{title_part}.{ext}"
        target_path = os.path.join(TEMP_FILES, target_name)

        if completed_file != target_path and not os.path.exists(target_path):
            try:
                os.rename(completed_file, target_path)
                completed_file = target_path
            except Exception:
                pass

        ftype = "video"
        if meta.get("format") in ["audio", "image"]:
            ftype = meta["format"]
        log_download(os.path.basename(completed_file), ftype)

        for f_del in [progress_file, pid_file, meta_file]:
            try:
                if os.path.isfile(f_del):
                    os.remove(f_del)
            except Exception:
                pass

        file_size = os.path.getsize(completed_file) if os.path.isfile(completed_file) else 0
        final_fn = os.path.basename(completed_file)
        base = f"{proto}://{host}"

        return JSONResponse({
            "status": "finished",
            "file": f"{base}/download.php?file={urllib.parse.quote(final_fn)}",
            "file_size": format_size(file_size),
            "file_name": final_fn,
            "message": "Download selesai!",
        })

    if not os.path.isfile(progress_file) or elapsed < 3.0:
        return JSONResponse({"status": "starting", "message": "Mempersiapkan download..."})

    lines = []
    try:
        with open(progress_file, "r", encoding="utf-8", errors="ignore") as f:
            lines = [l.strip() for l in f.readlines() if l.strip()]
    except Exception:
        pass

    if not lines:
        return JSONResponse({"status": "starting", "message": "Mempersiapkan download..."})

    progress = 0.0
    speed = "0 MB/s"
    eta = "--:--"
    total_size = ""
    downloaded = ""
    status_str = "downloading"
    err = None

    # Parse last lines
    marker = "[vd-progress]"
    found_vd = False
    for line in reversed(lines):
        if "ERROR:" in line or "CRITICAL:" in line:
            status_str = "error"
            err = re.sub(r'^(\[.*?\]\s*)?(ERROR:|CRITICAL:)\s*', '', line)
            break
        if marker in line:
            pos = line.find(marker)
            raw_json = line[pos + len(marker):].strip()
            try:
                vd = json.loads(raw_json)
                progress = float(vd.get("percent") or 0)
                dl_b = int(vd.get("downloaded") or 0)
                tot_b = int(vd.get("total") or vd.get("total_est") or 0)
                spd_b = float(vd.get("speed") or 0)
                eta_s = int(vd.get("eta") or 0)

                speed = format_speed(spd_b)
                eta = format_eta(eta_s)
                total_size = format_size(tot_b) if tot_b > 0 else ""
                downloaded = format_size(dl_b) if dl_b > 0 else ""

                r_stat = vd.get("status")
                if r_stat == "finished":
                    status_str = "processing"
                    progress = 100.0
                elif r_stat == "processing":
                    status_str = "processing"
                    progress = max(progress, 95.0)
                found_vd = True
                break
            except Exception:
                pass

    if not found_vd and status_str != "error":
        for line in reversed(lines[-10:]):
            m_pct = re.search(r'(\d{1,3}(?:\.\d+)?)%', line)
            if m_pct:
                progress = float(m_pct.group(1))
            m_spd = re.search(r'at\s+([\d\.]+\s*[KMGT]?i?B/s)', line, re.I)
            if m_spd:
                speed = m_spd.group(1).strip()
            m_eta = re.search(r'ETA\s+([\d\:]+)', line, re.I)
            if m_eta:
                eta = m_eta.group(1).strip()
            if "Merging formats into" in line or "Destination" in line:
                status_str = "processing"
                progress = 100.0

    if not is_running and not completed_file and status_str != "error":
        if elapsed < 15.0:
            status_str = "downloading"
            msg = "Mempersiapkan download..."
        else:
            status_str = "error"
            err = "Download berhenti atau file tidak ditemukan"

    msg = "Mendownload..."
    if status_str == "processing":
        job_fmt = meta.get("format")
        if job_fmt == "image":
            msg = "Menyiapkan gambar..."
        elif job_fmt == "audio":
            msg = "Memproses audio..."
        else:
            msg = "Menggabungkan video dan audio..."
    elif status_str == "finished":
        msg = "Download selesai!"
    elif status_str == "error":
        msg = err or "Terjadi kesalahan saat download"

    return JSONResponse({
        "status": status_str,
        "progress": progress,
        "speed": speed,
        "eta": eta,
        "total_size": total_size,
        "downloaded": downloaded,
        "message": msg,
    })

@app.get("/download.php")
@app.get("/download")
@app.head("/download.php")
@app.head("/download")
async def download_file(request: Request, file: Optional[str] = Query(None)):
    if not file or not file.strip():
        raise HTTPException(status_code=404, detail="File not found")
    
    filename = os.path.basename(file.strip())
    filepath = os.path.join(TEMP_FILES, filename)
    if not os.path.isfile(filepath):
        raise HTTPException(status_code=404, detail="File not found")

    ext = filename.split(".")[-1].lower()
    allowed_ext = ['mp4', 'mkv', 'webm', 'mp3', 'm4a', 'aac', 'ogg', 'opus', 'wav', 'flac', 'zip', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif']
    if ext not in allowed_ext:
        raise HTTPException(status_code=403, detail="Invalid file type")

    filesize = os.path.getsize(filepath)
    range_header = request.headers.get("range")

    import mimetypes
    mime, _ = mimetypes.guess_type(filepath)
    mime = mime or "application/octet-stream"

    encoded_fn = urllib.parse.quote(filename)
    headers = {
        "Content-Disposition": f"attachment; filename*=UTF-8''{encoded_fn}",
        "Accept-Ranges": "bytes",
        "Cache-Control": "no-cache",
    }

    if range_header:
        # Handle HTTP Range for resuming download
        m = re.match(r"bytes=(\d+)-(\d*)", range_header)
        if m:
            start = int(m.group(1))
            end = int(m.group(2)) if m.group(2) else filesize - 1
            if start >= filesize:
                raise HTTPException(status_code=416, detail="Range not satisfiable")
            end = min(end, filesize - 1)
            length = end - start + 1

            headers["Content-Range"] = f"bytes {start}-{end}/{filesize}"
            headers["Content-Length"] = str(length)

            def iterfile():
                with open(filepath, "rb") as f:
                    f.seek(start)
                    remaining = length
                    while remaining > 0:
                        chunk_size = min(remaining, 1024 * 1024)
                        data = f.read(chunk_size)
                        if not data:
                            break
                        remaining -= len(data)
                        yield data

            return StreamingResponse(iterfile(), status_code=206, media_type=mime, headers=headers)

    headers["Content-Length"] = str(filesize)
    return FileResponse(filepath, media_type=mime, headers=headers, filename=filename)

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="127.0.0.1", port=3007, log_level="info")
