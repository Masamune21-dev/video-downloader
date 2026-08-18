# 🎬 Video Downloader (Full Python FastAPI + yt-dlp)

A **high-performance, modern video downloader web application** built with **Python 3 (FastAPI & Uvicorn)**, powered by **yt-dlp** and native direct media resolvers.

This project allows you to download videos, audio, and photo albums from **YouTube, TikTok, Bilibili, Instagram, Facebook, and 100+ supported platforms** with quality up to **4K (2160p)**.

Designed for **VPS and self-hosted servers**, focusing on **performance, native async streaming, zero legacy PHP overhead**, and a playful **DL-chan comic / neo-brutal UI**.

---

## ✨ Features

- 🎬 Download videos from **100+ platforms** (YouTube, TikTok, Bilibili, Instagram, Twitter/X, etc.)
- 🎧 Audio extraction (**MP3, M4A, Opus, FLAC, WAV**)
- 📺 Quality up to **4K / Ultra HD**
- 🖼️ TikTok Photo Slideshow picker & batch ZIP downloader
- ⚡ **Pure Python Async Backend (FastAPI + Uvicorn)**
- 🚀 HTTP Range streaming support (pause & resume download)
- 🧹 Automated background sweeper (auto-deletes temporary files after 10 minutes)
- 🔒 No user data stored
- 🎨 DL-chan inspired **comic neo-brutal UI** with Glassmorphism
- 📱 Responsive layout for desktop and mobile

---

## 🧰 Tech Stack

- **Python 3.10+ (FastAPI, Uvicorn, httpx, jinja2, aiofiles)**
- **yt-dlp + Deno (EJS solver) + curl_cffi**
- **FFmpeg** *(stream merging & audio conversion)*
- **HTML5 / CSS3 / Vanilla JavaScript**
- **PM2 Process Manager & Nginx Reverse Proxy**

---

## 📁 Project Structure

```text
video-downloader/
├── main.py                # FastAPI main application & API routes
├── templates/
│   └── index.html         # Jinja2 template with comic neo-brutal UI
├── public/
│   └── assets/            # Static files (CSS, JS, images, icons)
│       ├── css/
│       ├── js/
│       └── img/
├── storage/
│   └── temp/              # Temporary download buffers & progress logs
└── README.md
```

---

## 🔧 Installation & Running

### 1️⃣ Setup Virtual Environment & Dependencies
```bash
python3 -m venv venv
source venv/bin/activate
pip install fastapi uvicorn yt-dlp jinja2 aiofiles python-multipart httpx "curl_cffi==0.15.0"
```

### 2️⃣ Run with Uvicorn / PM2
```bash
# Development / Standalone:
python3 main.py

# Production with PM2:
pm2 start main.py --name "vid-masamune" --interpreter ./venv/bin/python
```

### 3️⃣ Nginx Reverse Proxy Configuration
```nginx
location / {
    proxy_pass http://127.0.0.1:3007;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

---

## 📜 License
- This project is provided as-is for learning and personal use.
