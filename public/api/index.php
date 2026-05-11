<?php
require_once __DIR__ . '/../../app/bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Basic CSRF protection: validate Origin header
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $allowedOrigin = rtrim(BASE_URL, '/');
    $originNormalized = rtrim($origin, '/');
    if ($originNormalized !== $allowedOrigin) {
        json_response(['status' => 'error', 'message' => 'Invalid origin'], 403);
    }
}

$action = $_GET['action'] ?? '';

if (!in_array($action, ['analyze', 'download'], true)) {
    json_response(['status' => 'error', 'message' => 'Invalid request']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed']);
}

if ($action === 'analyze') {
    require APP_ROOT . '/app/http/controllers/AnalyzeController.php';
    exit;
}

require APP_ROOT . '/app/http/controllers/DownloadController.php';
exit;