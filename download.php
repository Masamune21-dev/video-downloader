<?php
require_once 'config.php';

/* =========================================================
   CONFIG
   ========================================================= */
set_time_limit(0);

$allowedExt = ['mp4', 'mkv', 'webm', 'mp3', 'm4a', 'aac', 'ogg'];

/* =========================================================
   INPUT VALIDATION
   ========================================================= */
$file = $_GET['file'] ?? '';
$filename = basename($file);
$path = TEMP_FILES . $filename;

if (!$file || !$filename || !file_exists($path)) {
    http_response_code(404);
    exit('File not found');
}

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(403);
    exit('Invalid file type');
}

$filesize = filesize($path);
$mime = mime_content_type($path) ?: 'application/octet-stream';

/* =========================================================
   RANGE SUPPORT (RESUME)
   ========================================================= */
$start = 0;
$end = $filesize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = (int)$m[1];
        if ($m[2] !== '') {
            $end = (int)$m[2];
        }
        http_response_code(206);
    }
}

/* =========================================================
   HEADERS
   ========================================================= */
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: no-cache');
header('Pragma: public');

if ($start > 0 || $end < ($filesize - 1)) {
    header("Content-Range: bytes $start-$end/$filesize");
    header('Content-Length: ' . ($end - $start + 1));
} else {
    header('Content-Length: ' . $filesize);
}

/* =========================================================
   STREAM FILE (LOW MEMORY)
   ========================================================= */
$chunkSize = 1024 * 1024; // 1MB
$fp = fopen($path, 'rb');

if ($fp === false) {
    http_response_code(500);
    exit('Failed to open file');
}

fseek($fp, $start);

while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
    if (connection_aborted()) break;

    $read = min($chunkSize, $end - $pos + 1);
    echo fread($fp, $read);
    flush();
}

fclose($fp);
exit;
