<?php
require_once 'config.php';

$file = $_GET['file'] ?? '';
$path = TEMP_FILES . basename($file);

if (!$file || !file_exists($path)) {
    http_response_code(404);
    exit('File not found');
}

$filename = basename($path);
$filesize = filesize($path);

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-cache');
header('Pragma: public');

readfile($path);
exit;
