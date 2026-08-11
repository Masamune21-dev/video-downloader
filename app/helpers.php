<?php
declare(strict_types=1);

/**
 * Shared helper functions used across the application.
 * Loaded once via bootstrap.php to avoid redeclaration.
 */

function format_size(int $bytes): string {
    if ($bytes <= 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($bytes) / log($k));
    $i = max(0, min($i, count($sizes) - 1));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function format_file_size(int $bytes): string {
    return format_size($bytes);
}

function format_speed(float $bytesPerSecond): string {
    if ($bytesPerSecond <= 0) return '0 MB/s';
    return format_size((int) $bytesPerSecond) . '/s';
}

function format_eta(int $seconds): string {
    if ($seconds <= 0) return '--:--';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

function is_process_running(int $pid): bool {
    if ($pid <= 0) return false;

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    $output = [];
    @exec('ps -p ' . $pid . ' > /dev/null 2>&1; echo $?', $output);
    return trim((string) ($output[0] ?? '1')) === '0';
}

/**
 * TikTok mobile-share links (vt./vm.tiktok.com, /t/XXXX) hide the real path
 * behind a redirect, so a /photo/ slideshow looks like an ordinary link until
 * it is resolved. yt-dlp follows the redirect itself but then rejects the
 * /photo/ URL ("Unsupported URL"), so we must resolve it here and rewrite it
 * before yt-dlp ever sees it.
 *
 * The result is cached: analyze and download normalize the same URL, and
 * TikTok throttles repeated hits from one IP.
 */
function expand_tiktok_short_url(string $url): string {
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');

    if (!str_contains($host, 'tiktok.com')) return $url;

    $isShortLink = in_array($host, ['vt.tiktok.com', 'vm.tiktok.com'], true)
        || str_starts_with($path, '/t/');
    if (!$isShortLink || !function_exists('curl_init')) return $url;

    $cacheFile = TEMP_CACHE . 'shorturl_' . sha1($url) . '.txt';
    if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < 86400) {
        $cached = trim((string) @file_get_contents($cacheFile));
        if ($cached !== '') return $cached;
    }

    $ch = curl_init($url);
    if ($ch === false) return $url;

    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => tiktok_browser_user_agent(),
    ]);

    curl_exec($ch);
    $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    // Only trust a redirect that stayed on TikTok; anything else is not ours.
    $effectiveHost = strtolower((string) (parse_url($effective, PHP_URL_HOST) ?: ''));
    if ($effective === '' || !str_contains($effectiveHost, 'tiktok.com')) {
        return $url;
    }

    @file_put_contents($cacheFile, $effective);

    return $effective;
}

/**
 * TikTok answers a desktop UA from a datacenter IP with an empty JS shell +
 * captcha challenge, which is why slideshow scraping used to find nothing and
 * fall back to the single cover thumbnail. A mobile UA still gets the real
 * server-rendered page with the full imagePost payload, so it is required here
 * rather than merely preferred — do not swap it back to a desktop string.
 */
function tiktok_browser_user_agent(): string {
    return 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15'
        . ' (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
}

function normalize_tiktok_photo_url_for_ytdlp(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts)) return $url;

    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!str_contains($host, 'tiktok.com')) return $url;

    // A share link has no /photo/ in its path yet — resolve it before matching.
    $url = expand_tiktok_short_url($url);

    $parts = parse_url($url);
    if (!is_array($parts)) return $url;

    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');

    if (!preg_match('~^/(@[\w.-]+)/photo/(\d+)~', $path, $matches)) {
        return $url;
    }

    $scheme = (string) ($parts['scheme'] ?? 'https');
    return $scheme . '://' . $host . '/' . $matches[1] . '/video/' . $matches[2];
}

function get_file_statistics(): array {
    $videosDir = TEMP_FILES;
    
    $videos = 0;
    $audios = 0;
    $images = 0;
    $totalSize = 0;
    
    if (!is_dir($videosDir)) {
        return [
            'videos' => 0,
            'audios' => 0,
            'images' => 0,
            'total' => 0,
            'totalSize' => 0
        ];
    }
    
    $videoExts = ['mp4', 'mkv', 'webm', 'avi', 'mov', 'flv', 'wmv', 'mpeg', 'mpg', '3gp'];
    $audioExts = ['mp3', 'm4a', 'aac', 'ogg', 'opus', 'wav', 'flac', 'wma', 'aiff'];
    $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'ico', 'svg', 'tiff'];
    
    $files = @scandir($videosDir);
    if (!is_array($files)) {
        return [
            'videos' => 0,
            'audios' => 0,
            'images' => 0,
            'total' => 0,
            'totalSize' => 0
        ];
    }
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || $file === '.htaccess') continue;
        
        $filePath = $videosDir . $file;
        if (!is_file($filePath)) continue;
        
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $fileSize = filesize($filePath);
        
        if (!$fileSize) continue;
        
        $totalSize += $fileSize;
        
        if (in_array($ext, $videoExts, true)) {
            $videos++;
        } elseif (in_array($ext, $audioExts, true)) {
            $audios++;
        } elseif (in_array($ext, $imageExts, true)) {
            $images++;
        }
    }
    
    return [
        'videos' => $videos,
        'audios' => $audios,
        'images' => $images,
        'total' => $videos + $audios + $images,
        'totalSize' => $totalSize
    ];
}

function get_download_history(): array {
    $historyFile = TEMP_PROGRESS . '.download-history.json';
    
    if (!is_file($historyFile)) {
        return [
            'videos' => 0,
            'audios' => 0,
            'images' => 0,
            'total' => 0
        ];
    }
    
    $content = @file_get_contents($historyFile);
    if (!$content) {
        return [
            'videos' => 0,
            'audios' => 0,
            'images' => 0,
            'total' => 0
        ];
    }
    
    $data = @json_decode($content, true);
    if (!is_array($data)) {
        return [
            'videos' => 0,
            'audios' => 0,
            'images' => 0,
            'total' => 0
        ];
    }
    
    return [
        'videos' => (int) ($data['videos'] ?? 0),
        'audios' => (int) ($data['audios'] ?? 0),
        'images' => (int) ($data['images'] ?? 0),
        'total' => (int) ($data['total'] ?? 0)
    ];
}

function log_download(string $filename, string $type = 'video'): bool {
    $historyFile = TEMP_PROGRESS . '.download-history.json';
    
    $types = ['video', 'audio', 'image'];
    if (!in_array($type, $types, true)) {
        $type = 'video';
    }
    
    // Map type to correct key name (singular to plural)
    $keyMap = [
        'video' => 'videos',
        'audio' => 'audios',
        'image' => 'images'
    ];
    $historyKey = $keyMap[$type] ?? 'videos';
    
    $history = get_download_history();
    
    $history[$historyKey]++;
    $history['total']++;
    
    $json = json_encode($history, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    
    return @file_put_contents($historyFile, $json) !== false;
}
