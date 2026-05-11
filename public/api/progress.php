<?php
require_once __DIR__ . '/../../app/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response([
        'status' => 'error',
        'message' => 'Method not allowed'
    ]);
}

require APP_ROOT . '/app/jobs/progress.php';
exit;