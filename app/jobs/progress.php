<?php

$jobId = trim((string) ($_GET['id'] ?? ''));

if (!$jobId || !preg_match('/^vd_[a-z0-9]+(?:\.[a-z0-9]+)?$/i', $jobId)) {
    json_response(['status' => 'error', 'message' => 'Job ID tidak valid'], 400);
}

$progressFile = TEMP_PROGRESS . $jobId . '.log';
$pidFile      = TEMP_PROGRESS . $jobId . '.pid';
$metadataFile = TEMP_PROGRESS . $jobId . '.json';

$metadata = [];
if (is_file($metadataFile)) {
    $decoded = json_decode((string) file_get_contents($metadataFile), true);
    if (is_array($decoded)) $metadata = $decoded;
}

$pid = read_job_pid($pidFile, $metadata);
$isRunning = $pid !== null ? is_process_running((int) $pid) : false;

$completed = find_completed_file($jobId, $metadata);
if ($completed !== null && !$isRunning) {
    // Rename final file to user-friendly name.
    $renamed = rename_completed_file($jobId, $completed['path']);
    if ($renamed !== null) {
        $completed['path'] = $renamed;
        $completed['size'] = (int) (@filesize($renamed) ?: $completed['size']);
    }

    @unlink($progressFile);
    @unlink($pidFile);
    @unlink($metadataFile);
    @unlink(TEMP_PROGRESS . $jobId . '.sh');

    json_response([
        'status'    => 'finished',
        'file'      => full_url('download.php?file=' . basename($completed['path'])),
        'file_size' => format_size((int) $completed['size']),
        'file_name' => basename($completed['path']),
        'message'   => 'Download selesai!',
    ]);
}

if (!is_file($progressFile)) {
    json_response(['status' => 'waiting', 'message' => 'Menunggu server memulai download...']);
}

$lines = tail_lines($progressFile, 200, 512 * 1024);
if (!$lines) {
    json_response(['status' => 'starting', 'message' => 'Mempersiapkan download...']);
}

$progress = 0.0;
$speed = '0 MB/s';
$eta = '--:--';
$totalSize = '';
$downloaded = '';
$status = 'downloading';
$err = null;

if ($err = find_last_error_line($lines)) {
    $status = 'error';
} else {
    $vd = parse_vd_progress_line($lines);
    if (is_array($vd)) {
        $progress = (float) ($vd['percent'] ?? 0);
        $downloadedBytes = (int) ($vd['downloaded'] ?? 0);
        $totalBytes = (int) ($vd['total'] ?? 0);
        $totalEstBytes = (int) ($vd['total_est'] ?? 0);
        $speedBytes = (float) ($vd['speed'] ?? 0);
        $etaSeconds = (int) ($vd['eta'] ?? 0);

        $totalBytes = $totalBytes > 0 ? $totalBytes : $totalEstBytes;
        if ($progress <= 0 && $totalBytes > 0 && $downloadedBytes > 0) {
            $progress = min(100.0, 100.0 * $downloadedBytes / $totalBytes);
        }

        $speed = format_speed($speedBytes);
        $eta = format_eta($etaSeconds);
        $totalSize = $totalBytes > 0 ? format_size($totalBytes) : '';
        $downloaded = $downloadedBytes > 0 ? format_size($downloadedBytes) : '';

        $rawStatus = (string) ($vd['status'] ?? 'downloading');
        if ($rawStatus === 'finished') {
            $status = 'processing';
            $progress = 100.0;
        } elseif ($rawStatus === 'processing') {
            $status = 'processing';
            $progress = max($progress, 95.0);
        }
    } else {
        $lastLines = array_slice($lines, -10);
        foreach ($lastLines as $line) {
            if (preg_match('/(\d{1,3}(?:\.\d+)?)%/', $line, $matches)) {
                $progress = (float) $matches[1];
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
            if (strpos($line, '[Merger] Merging formats into') !== false ||
                strpos($line, '[ExtractAudio] Destination') !== false) {
                $status = 'processing';
                $progress = 100.0;
            }
        }
    }
}

if (!$isRunning && $completed === null && $status !== 'error') {
    $status = 'error';
    $err = 'Download berhenti atau file tidak ditemukan';
}

if (!empty($metadata)) {
    $metadata['progress'] = $progress;
    $metadata['speed'] = $speed;
    $metadata['eta'] = $eta;
    $metadata['last_update'] = time();
    $metadata['status'] = $status;
    @file_put_contents($metadataFile, json_encode($metadata));
}

$response = [
    'status' => $status,
    'progress' => $progress,
    'speed' => $speed,
    'eta' => $eta,
    'total_size' => $totalSize,
    'downloaded' => $downloaded,
];

switch ($status) {
    case 'processing':
        $response['message'] = (($metadata['format'] ?? '') === 'image')
            ? 'Menyiapkan gambar...'
            : 'Menggabungkan video dan audio...';
        break;
    case 'finished':
        $response['message'] = 'Download selesai!';
        break;
    case 'error':
        $response['message'] = $err ?? 'Terjadi kesalahan saat download';
        break;
    default:
        $response['message'] = (($metadata['format'] ?? '') === 'image')
            ? 'Mendownload gambar...'
            : 'Mendownload...';
}

json_response($response);

// format_size(), format_speed(), format_eta() are now provided by app/helpers.php

function read_job_pid(string $pidFile, array $metadata): ?string {
    if (is_file($pidFile)) {
        $pid = trim((string) @file_get_contents($pidFile));
        if ($pid !== '' && ctype_digit($pid)) return $pid;
    }
    $pid = $metadata['pid'] ?? null;
    if (is_string($pid)) {
        $pid = trim($pid);
        if ($pid !== '' && ctype_digit($pid)) return $pid;
    }
    return null;
}

// is_process_running() is now provided by app/helpers.php

function tail_lines(string $path, int $maxLines, int $maxBytes): array {
    $fp = @fopen($path, 'rb');
    if ($fp === false) return [];
    $stat = fstat($fp);
    $size = (int) ($stat['size'] ?? 0);
    if ($size <= 0) { fclose($fp); return []; }

    $bytes = min($size, $maxBytes);
    if (@fseek($fp, -$bytes, SEEK_END) !== 0) @fseek($fp, 0, SEEK_SET);

    $data = fread($fp, $bytes);
    fclose($fp);
    if (!is_string($data) || $data === '') return [];

    $data = str_replace("\r\n", "\n", $data);
    $lines = explode("\n", $data);
    $lines = array_values(array_filter($lines, static fn($l) => trim((string)$l) !== ''));

    if (count($lines) > $maxLines) $lines = array_slice($lines, -$maxLines);
    return $lines;
}

function parse_vd_progress_line(array $lines): ?array {
    $marker = '[vd-progress]';
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim((string) $lines[$i]);
        $pos = strpos($line, $marker);
        if ($pos === false) continue;

        $json = trim(substr($line, $pos + strlen($marker)));
        if ($json === '') continue;

        $decoded = json_decode($json, true);
        if (is_array($decoded) && isset($decoded['status'])) return $decoded;
    }
    return null;
}

function find_last_error_line(array $lines): ?string {
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim((string) $lines[$i]);
        if ($line === '') continue;

        if (stripos($line, 'ERROR:') !== false || stripos($line, 'CRITICAL:') !== false) {
            return preg_replace('/^(\[.*?\]\s*)?(ERROR:|CRITICAL:)\s*/i', '', $line);
        }
        if (stripos($line, 'WARNING:') !== false && stripos($line, 'Unsupported URL') !== false) {
            return preg_replace('/^(\[.*?\]\s*)?WARNING:\s*/i', '', $line);
        }
    }
    return null;
}

function find_completed_file(string $jobId, array $metadata = []): ?array {
    // Files are stored with jobId prefix, e.g. vd_xxx_Title.mp4 (or .part/.ytdl during download).
    $expectsMerge = !empty($metadata['expects_merge']);
    $files = glob(TEMP_FILES . $jobId . '*') ?: [];
    if (empty($files)) return null;

    $finalCandidates = [];
    $intermediateCandidates = [];

    foreach ($files as $file) {
        $base = basename($file);
        if (str_contains($base, '.part') || str_contains($base, '.ytdl') || str_contains($base, '.temp')) continue;
        if (!is_file($file)) continue;

        $isIntermediate = (bool) preg_match('/\.f\d+\.[A-Za-z0-9]+$/i', $base);
        if ($isIntermediate) $intermediateCandidates[] = $file;
        else $finalCandidates[] = $file;
    }

    if (!empty($finalCandidates)) {
        if (($metadata['format'] ?? '') === 'image') {
            $zipCandidates = array_values(array_filter($finalCandidates, static function (string $file): bool {
                return strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) === 'zip';
            }));
            if (!empty($zipCandidates)) return pick_stable_largest_file($zipCandidates, 1);
        }

        return pick_stable_largest_file($finalCandidates);
    }

    // If merge was expected but no merged output exists, do not expose stream fragments as completed.
    if ($expectsMerge || empty($intermediateCandidates)) return null;

    return pick_stable_largest_file($intermediateCandidates);
}

function pick_stable_largest_file(array $candidates, int $minBytes = 1024): ?array {
    $best = null;
    foreach ($candidates as $file) {
        $size1 = @filesize($file);
        if ($size1 === false || $size1 < $minBytes) continue;

        usleep(120000);
        clearstatcache(true, $file);
        $size2 = @filesize($file);
        if ($size2 === false || $size1 !== $size2) continue;

        if ($best === null || $size1 > $best['size']) $best = ['path' => $file, 'size' => $size1];
    }

    return $best;
}

function rename_completed_file(string $jobId, string $path): ?string {
    if (!is_file($path)) return null;

    $base = basename($path);
    $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    if ($ext === '') return null;

    $titlePart = $base;
    $dotExt = '.' . pathinfo($base, PATHINFO_EXTENSION);
    if ($dotExt !== '.' && str_ends_with($titlePart, $dotExt)) {
        $titlePart = substr($titlePart, 0, -strlen($dotExt));
    }

    $prefix = $jobId . '_';
    if (str_starts_with($titlePart, $prefix)) {
        $titlePart = substr($titlePart, strlen($prefix));
    }

    $titlePart = sanitize_title_for_filename($titlePart);
    if ($titlePart === '') $titlePart = 'video';

    $host = sanitize_host_for_filename($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        $hostFromBase = parse_url((string) BASE_URL, PHP_URL_HOST);
        $host = sanitize_host_for_filename(is_string($hostFromBase) ? $hostFromBase : '');
    }
    if ($host === '') $host = 'localhost';

    $date = date('Y-m-d');

    // Allocate daily sequence at rename-time (finish) so filenames are stable for users.
    for ($i = 0; $i < 20; $i++) {
        $seq = next_daily_sequence($date);
        $seqStr = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        $target = rtrim(TEMP_FILES, '/') . '/' . $host . '_' . $date . '_' . $seqStr . '_' . $titlePart . '.' . $ext;

        if (!file_exists($target)) {
            if (@rename($path, $target)) return $target;
            return null;
        }
    }

    return null;
}

function next_daily_sequence(string $date): int {
    $dir = rtrim(TEMP_PROGRESS, '/');
    if (!is_dir($dir)) return 1;

    $lockPath = $dir . '/.download-seq.lock';
    $seqPath = $dir . '/download-seq-' . $date . '.txt';

    $lock = @fopen($lockPath, 'c');
    if ($lock === false) return 1;

    $seq = 0;
    if (flock($lock, LOCK_EX)) {
        $raw = is_file($seqPath) ? trim((string) @file_get_contents($seqPath)) : '';
        if ($raw !== '' && ctype_digit($raw)) $seq = (int) $raw;
        $seq++;
        @file_put_contents($seqPath, (string) $seq, LOCK_EX);
        flock($lock, LOCK_UN);
    } else {
        $seq = 1;
    }

    fclose($lock);
    return $seq > 0 ? $seq : 1;
}

function sanitize_host_for_filename(string $host): string {
    $host = trim($host);
    if ($host === '') return '';

    $host = (string) preg_replace('/:\\d+$/', '', $host); // strip :port
    $host = (string) preg_replace('/[^A-Za-z0-9.-]+/', '_', $host);
    $host = trim($host, "._- \t\n\r\0\x0B");
    return strtolower($host);
}

function sanitize_title_for_filename(string $title): string {
    $title = trim($title);
    if ($title === '') return '';

    // Title should already be restricted by yt-dlp, but keep it defensive.
    $title = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $title);
    $title = trim($title, "._- \t\n\r\0\x0B");
    if (strlen($title) > 120) $title = substr($title, 0, 120);
    $title = rtrim($title, '._-');
    return $title;
}
