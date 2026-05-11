<?php
// app/config/config.example.php
// Copy to: app/config/config.php
declare(strict_types=1);

/*
 * Catatan:
 * - File ini berisi default yang aman dan umum.
 * - Untuk deployment subfolder (mis. https://domain.com/video-downloader/), set env:
 *     APP_BASE_PATH=/video-downloader
 * - Pastikan web root (DocumentRoot) mengarah ke folder: public/
 */

if (!defined('APP_ROOT')) {
    // __DIR__ = .../video-downloader/app/config
    define('APP_ROOT', dirname(__DIR__, 2));
}
if (!defined('APP_PUBLIC')) {
    define('APP_PUBLIC', APP_ROOT . '/public');
}

define('APP_NAME', 'VideoDownloader Pro');
define('APP_VERSION', '3.0');
define('SECURITY_HSTS_MAX_AGE', 31536000);
define('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true);
define('SECURITY_HSTS_PRELOAD', false);

function detect_base_url(): string
{
    $isHttps = false;
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $isHttps = true;
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $isHttps = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // APP_BASE_PATH optional, contoh: '/', '/video-downloader/'
    $basePath = getenv('APP_BASE_PATH');
    $basePath = is_string($basePath) && $basePath !== '' ? $basePath : '/';
    if ($basePath[0] !== '/') $basePath = '/' . $basePath;
    $basePath = rtrim($basePath, '/') . '/';

    return $scheme . '://' . $host . $basePath;
}

define('BASE_URL', detect_base_url());

// Lokasi yt-dlp di server. Sesuaikan bila perlu.
define('YTDLP_BIN', '/usr/local/bin/yt-dlp');

// Semua artefak download/progress/cache disimpan di bawah storage/temp/
define('TEMP_ROOT', APP_ROOT . '/storage/temp');
define('TEMP_PROGRESS', TEMP_ROOT . '/progress/');
define('TEMP_FILES', TEMP_ROOT . '/files/');
define('TEMP_CACHE', TEMP_ROOT . '/cache/');

// Batas ukuran file (byte). Default 2GB.
define('MAX_FILE_SIZE', 2147483648);

// Ensure directories exist
foreach ([TEMP_PROGRESS, TEMP_FILES, TEMP_CACHE] as $dir) {
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Gagal membuat direktori: ' . $dir);
        }
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('Direktori tidak writable: ' . $dir);
    }
}

if (!is_file(YTDLP_BIN) || !is_executable(YTDLP_BIN)) {
    error_log('Peringatan: YTDLP_BIN tidak ditemukan atau tidak executable: ' . YTDLP_BIN);
}

// Protect temp directories from direct web access (defense in depth).
$denyHtaccess = "<IfModule mod_authz_core.c>\n"
    . "    Require all denied\n"
    . "</IfModule>\n"
    . "<IfModule !mod_authz_core.c>\n"
    . "    Order Allow,Deny\n"
    . "    Deny from all\n"
    . "</IfModule>\n";

foreach ([TEMP_ROOT . '/.htaccess', TEMP_PROGRESS . '/.htaccess', TEMP_FILES . '/.htaccess', TEMP_CACHE . '/.htaccess'] as $p) {
    if (!file_exists($p)) {
        @file_put_contents($p, $denyHtaccess);
    }
}

// yt-dlp tuning
define('YTDLP_NO_PLAYLIST', true);
define('YTDLP_RETRIES', 10);
define('YTDLP_FRAGMENT_RETRIES', 10);
define('YTDLP_SOCKET_TIMEOUT', 30);
define('YTDLP_CONCURRENT_FRAGMENTS', 8);
define('YTDLP_HTTP_CHUNK_SIZE', '10M'); // Mempercepat host tertentu dengan chunked HTTP
define('YTDLP_MERGE_OUTPUT_FORMAT', 'mp4/mkv'); // Prefer mp4, fallback mkv
define('YTDLP_PROGRESS_DELTA', 1);

// Optional: cookies untuk situs yang butuh login/consent.
// Simpan file cookies di: cookies.txt (root project, bukan di public/)
define('YTDLP_COOKIES_FILE', APP_ROOT . '/cookies.txt');
define('YTDLP_USER_AGENT', '');
define('YTDLP_EXTRA_HEADERS', []);
define('YTDLP_EXTRA_ARGS', []);

// Optimasi untuk file besar (berlaku untuk request ini; untuk global gunakan php.ini/php-fpm pool)
ini_set('upload_max_filesize', '2G');
ini_set('post_max_size', '2G');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300');
ini_set('max_input_time', '300');

function is_web_request(): bool
{
    return PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
}

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    if (!empty($_SERVER['REQUEST_SCHEME'])) {
        return strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https';
    }

    if (!empty($_SERVER['SERVER_PORT'])) {
        return (string) $_SERVER['SERVER_PORT'] === '443';
    }

    return false;
}

function security_csp(): string
{
    return implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "object-src 'none'",
        "script-src 'self' https://cdn.jsdelivr.net",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:",
        "img-src 'self' https: data:",
        "connect-src 'self'",
    ]);
}

function security_hsts(): string
{
    $value = 'max-age=' . SECURITY_HSTS_MAX_AGE;

    if (SECURITY_HSTS_INCLUDE_SUBDOMAINS) {
        $value .= '; includeSubDomains';
    }

    if (SECURITY_HSTS_PRELOAD) {
        $value .= '; preload';
    }

    return $value;
}

function send_security_headers(): void
{
    if (!is_web_request() || headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Content-Security-Policy: ' . security_csp());

    if (is_https_request()) {
        header('Strict-Transport-Security: ' . security_hsts());
    }
}

send_security_headers();

function full_url(string $path): string
{
    return BASE_URL . ltrim($path, '/');
}

function ytdlp_bin(): string
{
    if (is_file(YTDLP_BIN) && is_executable(YTDLP_BIN)) {
        return YTDLP_BIN;
    }
    $fallback = trim((string) @shell_exec('command -v yt-dlp 2>/dev/null'));
    return $fallback !== '' ? $fallback : YTDLP_BIN;
}

function ytdlp_base_args(): array
{
    $args = [];

    $args[] = '--cache-dir';
    $args[] = TEMP_CACHE;

    if (is_string(YTDLP_USER_AGENT) && YTDLP_USER_AGENT !== '') {
        $args[] = '--user-agent';
        $args[] = YTDLP_USER_AGENT;
    }

    if (is_string(YTDLP_COOKIES_FILE) && YTDLP_COOKIES_FILE !== '' && is_file(YTDLP_COOKIES_FILE)) {
        $args[] = '--cookies';
        $args[] = YTDLP_COOKIES_FILE;
    }

    if (defined('YTDLP_EXTRA_HEADERS') && is_array(YTDLP_EXTRA_HEADERS)) {
        foreach (YTDLP_EXTRA_HEADERS as $header) {
            if (!is_string($header) || trim($header) === '') continue;
            $args[] = '--add-headers';
            $args[] = $header;
        }
    }

    if (defined('YTDLP_EXTRA_ARGS') && is_array(YTDLP_EXTRA_ARGS)) {
        foreach (YTDLP_EXTRA_ARGS as $arg) {
            if (!is_string($arg) || $arg === '') continue;
            $args[] = $arg;
        }
    }

    return $args;
}

function shell_join(array $args): string
{
    $escaped = [];
    foreach ($args as $arg) $escaped[] = escapeshellarg((string) $arg);
    return implode(' ', $escaped);
}

function json_response($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
