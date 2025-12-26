<?php
require_once 'config.php';

function format_size($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

$jobId = $_GET['id'] ?? '';

if (!$jobId) {
    json_response(['status' => 'error', 'message' => 'Job ID tidak valid']);
}

$progressFile = TEMP_PROGRESS . $jobId . '.log';
$pidFile = TEMP_PROGRESS . $jobId . '.pid';
$metadataFile = TEMP_PROGRESS . $jobId . '.json';
$shellScript = TEMP_PROGRESS . $jobId . '.sh';

/* ===== CHECK METADATA ===== */
$metadata = [];
if (file_exists($metadataFile)) {
    $metadata = json_decode(file_get_contents($metadataFile), true);
}

/* ===== CHECK COMPLETED FILES ===== */
$completedFiles = glob(TEMP_FILES . $jobId . '.*');
foreach ($completedFiles as $file) {
    // Skip temporary and system files
    if (strpos($file, '.part') !== false || 
        strpos($file, '.ytdl') !== false ||
        strpos($file, '.temp') !== false) {
        continue;
    }
    
    // Check if file is complete (not being written)
    $size1 = filesize($file);
    usleep(100000); // 100ms delay
    clearstatcache();
    $size2 = filesize($file);
    
    if ($size1 > 10240 && $size1 === $size2) {
        // Check if process is still running
        $isRunning = false;
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && is_numeric($pid)) {
                exec("ps -p $pid > /dev/null 2>&1; echo $?", $output);
                $isRunning = (trim($output[0] ?? '') === '0');
            }
        }
        
        if (!$isRunning) {
            // Cleanup
            @unlink($progressFile);
            @unlink($pidFile);
            @unlink($shellScript);
            @unlink($metadataFile);
            
            json_response([
                'status' => 'finished',
                'file' => full_url('download.php?file=' . basename($file)),
                'file_size' => format_size($size1),
                'file_name' => basename($file),
                'message' => 'Download selesai!'
            ]);
        }
    }
}

/* ===== CHECK PROGRESS ===== */
if (!file_exists($progressFile)) {
    json_response([
        'status' => 'waiting',
        'message' => 'Menunggu server memulai download...'
    ]);
}

$content = @file($progressFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$content) {
    json_response([
        'status' => 'starting',
        'message' => 'Mempersiapkan download...'
    ]);
}

/* ===== PARSE YT-DLP OUTPUT ===== */
$lastLines = array_slice($content, -10); // Get last 10 lines
$progress = 0;
$speed = '0 MB/s';
$eta = '--:--';
$totalSize = '';
$downloaded = '';
$status = 'downloading';

foreach ($lastLines as $line) {
    // Download progress
    if (preg_match('/(\d{1,3}(?:\.\d+)?)%/', $line, $matches)) {
        $progress = floatval($matches[1]);
    }
    
    if (preg_match('/at\s+([\d\.]+\s*[KMGT]?i?B\/s)/i', $line, $matches)) {
        $speed = trim($matches[1]);
    }
    
    if (preg_match('/ETA\s+([\d\:]+)/i', $line, $matches)) {
        $eta = $matches[1];
    }
    
    if (preg_match('/of\s+([\d\.]+\s*[KMGT]?i?B)/i', $line, $matches)) {
        $totalSize = trim($matches[1]);
    }
    
    if (preg_match('/has\s+([\d\.]+\s*[KMGT]?i?B)/i', $line, $matches)) {
        $downloaded = trim($matches[1]);
    }
    
    // Check for completion or errors
    if (strpos($line, '[Merger] Merging formats into') !== false ||
        strpos($line, '[ExtractAudio] Destination') !== false) {
        $status = 'processing';
        $progress = 100;
    }
    
    if (strpos($line, 'ERROR') !== false || 
        strpos($line, 'CRITICAL') !== false ||
        strpos($line, 'WARNING') !== false && strpos($line, 'Unsupported URL') !== false) {
        $status = 'error';
    }
    
    if (strpos($line, 'Deleting original file') !== false ||
        strpos($line, '100%') !== false && $progress >= 99) {
        $status = 'finished';
        $progress = 100;
    }
}

// Update metadata
if (!empty($metadata)) {
    $metadata['progress'] = $progress;
    $metadata['speed'] = $speed;
    $metadata['eta'] = $eta;
    $metadata['last_update'] = time();
    $metadata['status'] = $status;
    
    file_put_contents($metadataFile, json_encode($metadata));
}

/* ===== RESPONSE ===== */
$response = [
    'status' => $status,
    'progress' => $progress,
    'speed' => $speed,
    'eta' => $eta,
    'total_size' => $totalSize,
    'downloaded' => $downloaded
];

switch ($status) {
    case 'processing':
        $response['message'] = 'Menggabungkan video dan audio...';
        break;
    case 'finished':
        $response['message'] = 'Download selesai!';
        break;
    case 'error':
        $response['message'] = 'Terjadi kesalahan saat download';
        break;
    default:
        $response['message'] = 'Mendownload...';
}

json_response($response);
?>