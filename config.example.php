<?php
define('APP_NAME', 'VideoDownloader Pro');
define('APP_VERSION', '3.0');

define('BASE_URL', 'https://genieacs.bmkv.net/video-downloader/');
define('YTDLP_BIN', '/usr/local/bin/yt-dlp');

// Path untuk file besar (2GB+ support)
define('TEMP_PROGRESS', __DIR__ . '/temp/progress/');
define('TEMP_FILES', __DIR__ . '/temp/files/');
define('MAX_FILE_SIZE', 2147483648); // 2GB in bytes

// Ensure directories exist
foreach ([TEMP_PROGRESS, TEMP_FILES] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

// Optimasi untuk file besar
ini_set('upload_max_filesize', '2G');
ini_set('post_max_size', '2G');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);

function full_url($path) {
    return BASE_URL . ltrim($path, '/');
}

function json_response($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>