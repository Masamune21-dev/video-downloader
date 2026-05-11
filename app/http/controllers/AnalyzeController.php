<?php

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$urlRaw = trim((string) ($_POST['url'] ?? ''));
if ($urlRaw === '') {
    json_response(['status' => 'error', 'message' => 'URL tidak boleh kosong'], 400);
}

$urlParts = parse_url($urlRaw);
$allowedSchemes = ['http', 'https'];

if (!$urlParts || empty($urlParts['scheme']) || !in_array(strtolower((string)$urlParts['scheme']), $allowedSchemes, true)) {
    json_response(['status' => 'error', 'message' => 'URL tidak valid'], 400);
}

$analyzeUrl = normalize_tiktok_photo_url_for_ytdlp($urlRaw);

$cmdParts = [
    ytdlp_bin(),
    ...ytdlp_base_args(),
    '--no-warnings',
    '-J',
];

if (defined('YTDLP_NO_PLAYLIST') && YTDLP_NO_PLAYLIST) {
    $cmdParts[] = '--no-playlist';
}

$cmdParts[] = $analyzeUrl;

$output = (string) @shell_exec(shell_join($cmdParts) . ' 2>&1');
$output = trim($output);

if ($output === '' || stripos($output, 'ERROR:') !== false) {
    $msg = 'Gagal menganalisis video';
    $lines = preg_split("/\r\n|\n|\r/", $output) ?: [];
    foreach (array_reverse($lines) as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        if (strtolower($line) === 'null') continue;
        $line = preg_replace('/^(ERROR:|WARNING:)\s*/', '', $line);
        $msg = $line;
        break;
    }
    json_response(['status' => 'error', 'message' => $msg], 400);
}

$data = json_decode($output, true);
if (!is_array($data)) {
    json_response(['status' => 'error', 'message' => 'Output analyzer tidak valid'], 500);
}

$availableFormats = [];
$videoOnlyFormats = [];
$audioOnlyFormats = [];

$formats = $data['formats'] ?? [];
if (is_array($formats)) {
    foreach ($formats as $format) {
        if (!is_array($format)) continue;

        $vcodec = (string) ($format['vcodec'] ?? 'none');
        $acodec = (string) ($format['acodec'] ?? 'none');

        $hasVideo = $vcodec !== 'none';
        $hasAudio = $acodec !== 'none';

        if ($hasVideo && $hasAudio) {
            $availableFormats[] = [
                'format_id' => (string) ($format['format_id'] ?? ''),
                'ext' => (string) ($format['ext'] ?? ''),
                'resolution' => get_resolution_string($format),
                'height' => (int) ($format['height'] ?? 0),
                'width' => (int) ($format['width'] ?? 0),
                'filesize' => isset($format['filesize']) ? format_file_size((int)$format['filesize']) : 'Unknown',
                'filesize_bytes' => (int) ($format['filesize'] ?? 0),
                'vcodec' => $vcodec,
                'acodec' => $acodec,
                'note' => (string) ($format['format_note'] ?? ''),
                'type' => 'muxed',
                'bitrate' => (float) ($format['abr'] ?? $format['tbr'] ?? 0),
            ];
        }

        if ($hasVideo && !$hasAudio) {
            $videoOnlyFormats[] = [
                'format_id' => (string) ($format['format_id'] ?? ''),
                'ext' => (string) ($format['ext'] ?? ''),
                'resolution' => get_resolution_string($format),
                'height' => (int) ($format['height'] ?? 0),
                'width' => (int) ($format['width'] ?? 0),
                'filesize' => isset($format['filesize']) ? format_file_size((int)$format['filesize']) : 'Unknown',
                'filesize_bytes' => (int) ($format['filesize'] ?? 0),
                'vcodec' => $vcodec,
                'fps' => (float) ($format['fps'] ?? 0),
                'dynamic_range' => (string) ($format['dynamic_range'] ?? 'SDR'),
                'type' => 'video',
                'bitrate' => (float) ($format['tbr'] ?? 0),
            ];
        }

        if ($hasAudio && !$hasVideo) {
            $audioOnlyFormats[] = [
                'format_id' => (string) ($format['format_id'] ?? ''),
                'ext' => (string) ($format['ext'] ?? ''),
                'acodec' => $acodec,
                'filesize' => isset($format['filesize']) ? format_file_size((int)$format['filesize']) : 'Unknown',
                'filesize_bytes' => (int) ($format['filesize'] ?? 0),
                'type' => 'audio',
                'bitrate' => (float) ($format['abr'] ?? 0),
                'quality' => (int) ($format['quality'] ?? 0),
            ];
        }
    }
}

usort($videoOnlyFormats, fn($a, $b) => ($b['height'] ?? 0) <=> ($a['height'] ?? 0));
usort($audioOnlyFormats, fn($a, $b) => ($b['bitrate'] ?? 0) <=> ($a['bitrate'] ?? 0));

$bestAudioFormat = pick_best_audio_for_merge($audioOnlyFormats);

if (!empty($videoOnlyFormats) && $bestAudioFormat) {
    foreach ($videoOnlyFormats as $videoFormat) {
        $availableFormats[] = [
            'format_id' => $videoFormat['format_id'] . '+' . $bestAudioFormat['format_id'],
            'ext' => 'mp4',
            'resolution' => $videoFormat['resolution'],
            'height' => (int) $videoFormat['height'],
            'width' => (int) $videoFormat['width'],
            'filesize' => 'Auto Merge',
            'filesize_bytes' => 0,
            'vcodec' => (string) $videoFormat['vcodec'],
            'acodec' => (string) $bestAudioFormat['acodec'],
            'note' => 'High Quality (merged)',
            'type' => 'combined',
            'video_id' => (string) $videoFormat['format_id'],
            'audio_id' => (string) $bestAudioFormat['format_id'],
            'dynamic_range' => (string) ($videoFormat['dynamic_range'] ?? 'SDR'),
            'fps' => (float) ($videoFormat['fps'] ?? 0),
        ];
    }
}

usort($availableFormats, fn($a, $b) => ($b['height'] ?? 0) <=> ($a['height'] ?? 0));

$audioFormats = [];
$hasAnyAudio = false;
foreach ($formats as $format) {
    if (!is_array($format)) continue;

    $acodec = (string) ($format['acodec'] ?? 'none');
    $vcodec = (string) ($format['vcodec'] ?? 'none');
    if ($acodec !== 'none') $hasAnyAudio = true;

    if ($acodec !== 'none' && $vcodec === 'none') {
        $abr = isset($format['abr']) ? (float)$format['abr'] : 0.0;
        $audioFormats[] = [
            'format_id' => (string) ($format['format_id'] ?? ''),
            'ext' => (string) ($format['ext'] ?? ''),
            'filesize' => isset($format['filesize']) ? format_file_size((int)$format['filesize']) : 'Unknown',
            'bitrate' => $abr > 0 ? (string) (round($abr) . 'kbps') : 'Unknown',
            'acodec' => $acodec,
            'type' => 'audio'
        ];
    }
}

usort($audioFormats, function($a, $b) {
    $abrA = (float) preg_replace('/[^0-9.]/', '', (string) ($a['bitrate'] ?? '0'));
    $abrB = (float) preg_replace('/[^0-9.]/', '', (string) ($b['bitrate'] ?? '0'));
    return $abrB <=> $abrA;
});

if (empty($audioFormats) && $hasAnyAudio) {
    $audioFormats[] = [
        'format_id' => 'bestaudio',
        'ext' => 'mp3',
        'filesize' => 'Unknown',
        'bitrate' => 'Best',
        'acodec' => 'best',
        'type' => 'audio',
        'note' => 'Best available audio',
    ];
}

$imageFormats = build_image_formats($data, $urlRaw, $analyzeUrl, empty($availableFormats));

json_response([
    'status' => 'success',
    'title' => (string) ($data['title'] ?? '-'),
    'thumbnail' => (string) ($data['thumbnail'] ?? ''),
    'duration' => (string) ($data['duration_string'] ?? ''),
    'duration_seconds' => (int) ($data['duration'] ?? 0),
    'uploader' => (string) ($data['uploader'] ?? ''),
    'view_count' => (int) ($data['view_count'] ?? 0),
    'available_formats' => $availableFormats,
    'audio_formats' => array_slice($audioFormats, 0, 5),
    'image_formats' => $imageFormats,
    'has_high_quality' => !empty(array_filter($availableFormats, fn($f) => ((int)($f['height'] ?? 0)) >= 1440)),
]);

function pick_best_audio_for_merge(array $audioOnlyFormats): ?array {
    if (empty($audioOnlyFormats)) return null;

    $preferred = [];
    $fallback = [];

    foreach ($audioOnlyFormats as $f) {
        $ext = strtolower((string) ($f['ext'] ?? ''));
        $acodec = strtolower((string) ($f['acodec'] ?? ''));

        $startsMp4a = (strpos($acodec, 'mp4a') === 0);

        $isMp4Audio = in_array($ext, ['m4a', 'mp4'], true)
            || $startsMp4a
            || $acodec === 'aac';

        if ($isMp4Audio) $preferred[] = $f;
        else $fallback[] = $f;
    }

    usort($preferred, fn($a, $b) => ((float)($b['bitrate'] ?? 0)) <=> ((float)($a['bitrate'] ?? 0)));
    usort($fallback, fn($a, $b) => ((float)($b['bitrate'] ?? 0)) <=> ((float)($a['bitrate'] ?? 0)));

    return $preferred[0] ?? $fallback[0] ?? null;
}

function get_resolution_string($format): string {
    if (isset($format['resolution']) && $format['resolution'] !== null) return (string) $format['resolution'];
    if (isset($format['height'])) {
        $height = (int) $format['height'];
        if ($height >= 2160) return '4K';
        if ($height >= 1440) return '2K';
        if ($height >= 1080) return '1080p';
        if ($height >= 720) return '720p';
        if ($height >= 480) return '480p';
        if ($height >= 360) return '360p';
        return $height . 'p';
    }
    return 'Unknown';
}

function build_image_formats(array $data, string $sourceUrl, string $analyzeUrl, bool $hasNoVideoFormats): array {
    $imageGroups = [];

    $host = strtolower((string) (parse_url($sourceUrl, PHP_URL_HOST) ?: ''));
    $path = (string) (parse_url($sourceUrl, PHP_URL_PATH) ?: '');
    $isTikTok = str_contains($host, 'tiktok.com');
    $isTikTokPhoto = $isTikTok && (bool) preg_match('~/photo/\d+~', $path);

    if (!$hasNoVideoFormats && !$isTikTokPhoto) {
        return [];
    }

    if ($isTikTok) {
        $imageGroups = analyze_extract_tiktok_image_groups($sourceUrl);
        if (empty($imageGroups) && $analyzeUrl !== $sourceUrl) {
            $imageGroups = analyze_extract_tiktok_image_groups($analyzeUrl);
        }
    }

    if (empty($imageGroups)) {
        $thumbnail = $data['thumbnail'] ?? '';
        if (is_string($thumbnail) && filter_var($thumbnail, FILTER_VALIDATE_URL)) {
            $imageGroups[] = [$thumbnail];
        }

        $thumbnails = $data['thumbnails'] ?? [];
        if (is_array($thumbnails)) {
            foreach ($thumbnails as $thumbnailInfo) {
                if (!is_array($thumbnailInfo)) continue;

                $url = $thumbnailInfo['url'] ?? '';
                if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) continue;

                $imageGroups[] = [$url];
            }
        }

        $imageGroups = analyze_dedupe_tiktok_image_groups($imageGroups);
    }

    if (empty($imageGroups)) return [];

    if (!$isTikTok && !$hasNoVideoFormats && count($imageGroups) < 2) return [];

    $formats = [];
    foreach ($imageGroups as $index => $group) {
        $url = (string) ($group[0] ?? '');
        if ($url === '') continue;

        $formats[] = [
            'format_id' => 'image_' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
            'ext' => analyze_image_extension_from_url($url) ?: 'jpg',
            'type' => 'image',
            'image_index' => $index,
            'image_number' => $index + 1,
            'image_count' => count($imageGroups),
            'image_url' => $url,
            'filesize' => 'Image',
            'filesize_bytes' => 0,
            'note' => 'Image only',
        ];
    }

    return $formats;
}

function analyze_extract_tiktok_image_groups(string $url): array {
    if (!function_exists('curl_init')) return [];

    $html = analyze_fetch_tiktok_page($url);
    if ($html === '') return [];

    $jsonBlobs = [];
    foreach ([
        '__UNIVERSAL_DATA_FOR_REHYDRATION__',
        'SIGI_STATE',
        '__NEXT_DATA__',
    ] as $scriptId) {
        if (preg_match('~<script[^>]+id=["\']' . preg_quote($scriptId, '~') . '["\'][^>]*>(.*?)</script>~is', $html, $match)) {
            $jsonBlobs[] = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    if (preg_match_all('~"imagePost"\s*:\s*\{~', $html, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $object = analyze_extract_json_object_at($html, (int) $match[1] + strpos($match[0], '{'));
            if ($object !== '') $jsonBlobs[] = '{"imagePost":' . $object . '}';
        }
    }

    $groups = [];
    foreach ($jsonBlobs as $blob) {
        $decoded = json_decode($blob, true);
        if (is_array($decoded)) {
            $groups = array_merge($groups, analyze_collect_tiktok_image_groups($decoded));
        }
    }

    return analyze_dedupe_tiktok_image_groups($groups);
}

function analyze_fetch_tiktok_page(string $url): string {
    $ch = curl_init($url);
    if ($ch === false) return '';

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => analyze_image_download_user_agent(),
        CURLOPT_REFERER => 'https://www.tiktok.com/',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);

    if (is_string(YTDLP_COOKIES_FILE) && YTDLP_COOKIES_FILE !== '' && is_file(YTDLP_COOKIES_FILE)) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, YTDLP_COOKIES_FILE);
    }

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return is_string($body) && $httpCode >= 200 && $httpCode < 400 ? $body : '';
}

function analyze_collect_tiktok_image_groups($node): array {
    if (!is_array($node)) return [];

    $groups = [];

    if (isset($node['imagePost']) && is_array($node['imagePost'])) {
        $groups = array_merge($groups, analyze_image_groups_from_web_image_post($node['imagePost']));
    }
    if (isset($node['image_post_info']) && is_array($node['image_post_info'])) {
        $groups = array_merge($groups, analyze_image_groups_from_app_image_post($node['image_post_info']));
    }

    foreach ($node as $child) {
        if (is_array($child)) {
            $groups = array_merge($groups, analyze_collect_tiktok_image_groups($child));
        }
    }

    return $groups;
}

function analyze_image_groups_from_web_image_post(array $imagePost): array {
    $groups = [];
    $images = $imagePost['images'] ?? [];
    if (!is_array($images)) return [];

    foreach ($images as $image) {
        if (!is_array($image)) continue;

        $urls = [];
        $urls = array_merge($urls, analyze_url_list_from_path($image, ['imageURL', 'urlList']));
        $urls = array_merge($urls, analyze_url_list_from_path($image, ['image_url', 'url_list']));
        $urls = array_merge($urls, analyze_url_list_from_path($image, ['display_image', 'url_list']));
        $urls = array_merge($urls, analyze_url_list_from_path($image, ['urlList']));
        $urls = array_merge($urls, analyze_url_list_from_path($image, ['url_list']));

        $urls = analyze_valid_image_urls($urls);
        if (!empty($urls)) $groups[] = $urls;
    }

    return $groups;
}

function analyze_image_groups_from_app_image_post(array $imagePostInfo): array {
    $groups = [];
    $images = $imagePostInfo['images'] ?? [];
    if (!is_array($images)) return [];

    foreach ($images as $image) {
        if (!is_array($image)) continue;

        $urls = [];
        $urls = array_merge($urls, analyze_url_list_from_path($image, ['display_image', 'url_list']));
        $urls = array_merge($urls, analyze_url_list_from_path($image, ['imageURL', 'urlList']));
        $urls = array_merge($urls, analyze_url_list_from_path($image, ['image_url', 'url_list']));
        $urls = array_merge($urls, analyze_url_list_from_path($image, ['url_list']));

        $urls = analyze_valid_image_urls($urls);
        if (!empty($urls)) $groups[] = $urls;
    }

    return $groups;
}

function analyze_url_list_from_path(array $node, array $path): array {
    $value = $node;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) return [];
        $value = $value[$segment];
    }

    if (is_string($value)) return [$value];
    return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
}

function analyze_valid_image_urls(array $urls): array {
    $valid = [];
    foreach ($urls as $url) {
        if (!is_string($url)) continue;
        if (str_starts_with($url, '//')) $url = 'https:' . $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
        if (!preg_match('~\.(?:jpe?g|png|webp|gif|avif)(?:[?&#]|$)|/tos-[^?]+/|photomode-image~i', $url)) continue;
        $valid[] = $url;
    }
    return array_values(array_unique($valid));
}

function analyze_dedupe_tiktok_image_groups(array $groups): array {
    $seen = [];
    $deduped = [];

    foreach ($groups as $group) {
        if (!is_array($group) || empty($group)) continue;
        $key = analyze_tiktok_image_identity((string) $group[0]);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $deduped[] = array_values(array_unique($group));
    }

    return $deduped;
}

function analyze_tiktok_image_identity(string $url): string {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    if ($path === '') return '';
    return preg_replace('~/+~', '/', $path) ?: $path;
}

function analyze_extract_json_object_at(string $text, int $start): string {
    $length = strlen($text);
    if ($start < 0 || $start >= $length || $text[$start] !== '{') return '';

    $depth = 0;
    $inString = false;
    $escape = false;

    for ($i = $start; $i < $length; $i++) {
        $char = $text[$i];
        if ($inString) {
            if ($escape) {
                $escape = false;
            } elseif ($char === '\\') {
                $escape = true;
            } elseif ($char === '"') {
                $inString = false;
            }
            continue;
        }

        if ($char === '"') {
            $inString = true;
        } elseif ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) return substr($text, $start, $i - $start + 1);
        }
    }

    return '';
}

function analyze_image_download_user_agent(): string {
    if (is_string(YTDLP_USER_AGENT) && YTDLP_USER_AGENT !== '') {
        return YTDLP_USER_AGENT;
    }
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
}

function analyze_image_extension_from_url(string $url): string {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : '';
}

// format_file_size() is now provided by app/helpers.php
