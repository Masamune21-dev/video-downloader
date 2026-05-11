<?php

if (empty($_POST['url'])) {
    json_response(['status' => 'error', 'message' => 'URL tidak boleh kosong'], 400);
}

$rawUrl = trim((string) $_POST['url']);
$urlParts = parse_url($rawUrl);
$allowedSchemes = ['http', 'https'];

if (!$urlParts || empty($urlParts['scheme']) || !in_array(strtolower((string)$urlParts['scheme']), $allowedSchemes, true)) {
    json_response(['status' => 'error', 'message' => 'URL tidak valid'], 400);
}

$formatId   = trim((string) ($_POST['format_id'] ?? ''));
$formatType = trim((string) ($_POST['format_type'] ?? 'video'));
$quality    = $_POST['quality'] ?? '';
$formatData = $_POST['format_data'] ?? '';

$allowedFormatTypes = ['video', 'audio', 'muxed', 'combined'];
if (!in_array($formatType, $allowedFormatTypes, true)) {
    json_response(['status' => 'error', 'message' => 'Tipe format tidak valid'], 400);
}

$formatInfo = [];
if ($formatData) {
    $decoded = json_decode((string)$formatData, true);
    if (is_array($decoded)) {
        $formatInfo = $decoded;
    }
}

function is_valid_format_id($value): bool {
    if (!is_string($value) || $value === '') return false;
    return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value);
}

function format_has_audio(array $formatInfo): bool {
    $acodec = strtolower(trim((string) ($formatInfo['acodec'] ?? '')));
    return $acodec !== '' && $acodec !== 'none';
}

function is_mp4_compatible_audio_codec(string $codec): bool {
    $codec = strtolower(trim($codec));
    if ($codec === '') return false;
    return $codec === 'aac' || str_starts_with($codec, 'mp4a');
}

try {
    $jobId = 'vd_' . bin2hex(random_bytes(16));
} catch (Throwable $e) {
    $jobId = uniqid('vd_', true);
}

$progressFile = TEMP_PROGRESS . $jobId . '.log';
$pidFile      = TEMP_PROGRESS . $jobId . '.pid';
$metaFile     = TEMP_PROGRESS . $jobId . '.json';
// Keep jobId prefix for active-job detection/cleanup, but include title so we can rename nicely when finished.
// Final rename happens in app/jobs/progress.php.
$outputTpl    = TEMP_FILES . $jobId . '_%(title).120s.%(ext)s';

$progressTemplate = 'download:[vd-progress] {"status":%(progress.status)j,"percent":%(progress._percent|0).1f,"downloaded":%(progress.downloaded_bytes|0)d,"total":%(progress.total_bytes|0)d,"total_est":%(progress.total_bytes_estimate|0)d,"speed":%(progress.speed|0).0f,"eta":%(progress.eta|0).0f}';

$cmdParts = [
    ytdlp_bin(),
    ...ytdlp_base_args(),
];

if (defined('YTDLP_NO_PLAYLIST') && YTDLP_NO_PLAYLIST) {
    $cmdParts[] = '--no-playlist';
}

$cmdParts[] = '--newline';
$cmdParts[] = '--progress';
$cmdParts[] = '--progress-template';
$cmdParts[] = $progressTemplate;

if (defined('YTDLP_PROGRESS_DELTA') && is_numeric(YTDLP_PROGRESS_DELTA) && (int) YTDLP_PROGRESS_DELTA > 0) {
    $cmdParts[] = '--progress-delta';
    $cmdParts[] = (string) (int) YTDLP_PROGRESS_DELTA;
}

$cmdParts[] = '--restrict-filenames';

if (defined('YTDLP_RETRIES')) {
    $cmdParts[] = '--retries';
    $cmdParts[] = (string) (int) YTDLP_RETRIES;
}
if (defined('YTDLP_FRAGMENT_RETRIES')) {
    $cmdParts[] = '--fragment-retries';
    $cmdParts[] = (string) (int) YTDLP_FRAGMENT_RETRIES;
}
if (defined('YTDLP_SOCKET_TIMEOUT')) {
    $cmdParts[] = '--socket-timeout';
    $cmdParts[] = (string) (int) YTDLP_SOCKET_TIMEOUT;
}
if (defined('YTDLP_CONCURRENT_FRAGMENTS') && (int) YTDLP_CONCURRENT_FRAGMENTS > 1) {
    $cmdParts[] = '--concurrent-fragments';
    $cmdParts[] = (string) (int) YTDLP_CONCURRENT_FRAGMENTS;
}
if (defined('YTDLP_HTTP_CHUNK_SIZE') && is_string(YTDLP_HTTP_CHUNK_SIZE) && trim(YTDLP_HTTP_CHUNK_SIZE) !== '') {
    $cmdParts[] = '--http-chunk-size';
    $cmdParts[] = trim(YTDLP_HTTP_CHUNK_SIZE);
}

$formatSelector = '';
$expectsMerge = false;
if ($formatType === 'audio') {
    $cmdParts[] = '-x';
    $cmdParts[] = '--audio-format';
    $cmdParts[] = 'mp3';
    $cmdParts[] = '--audio-quality';
    $cmdParts[] = '0';

    $audioSelector = 'bestaudio';
    if ($formatId !== '' && is_valid_format_id($formatId)) {
        $audioSelector = $formatId;
    }

    $formatSelector = $audioSelector;

} else {
    $mergeOutputFormat = null;
    if (defined('YTDLP_MERGE_OUTPUT_FORMAT') && is_string(YTDLP_MERGE_OUTPUT_FORMAT) && trim(YTDLP_MERGE_OUTPUT_FORMAT) !== '') {
        $mergeOutputFormat = trim(YTDLP_MERGE_OUTPUT_FORMAT);
    }

    $selectedAudioCodec = strtolower(trim((string) ($formatInfo['acodec'] ?? '')));
    if ($selectedAudioCodec !== '' && $selectedAudioCodec !== 'none' && !is_mp4_compatible_audio_codec($selectedAudioCodec)) {
        $mergeOutputFormat = 'mkv';
    }

    if ($mergeOutputFormat !== null) {
        $cmdParts[] = '--merge-output-format';
        $cmdParts[] = $mergeOutputFormat;
    }

    if (!empty($formatInfo['video_id']) && !empty($formatInfo['audio_id'])) {
        $videoId = (string) $formatInfo['video_id'];
        $audioId = (string) $formatInfo['audio_id'];

        if (!is_valid_format_id($videoId) || !is_valid_format_id($audioId)) {
            json_response(['status' => 'error', 'message' => 'Format ID tidak valid'], 400);
        }

        $formatSelector = $videoId . '+' . $audioId;
        $expectsMerge = true;

    } elseif ($formatId !== '') {
        if (!is_valid_format_id($formatId)) {
            json_response(['status' => 'error', 'message' => 'Format ID tidak valid'], 400);
        }

        $selectorAlreadyCombined = strpos($formatId, '+') !== false;
        $selectorHasAudio = format_has_audio($formatInfo);
        $isExplicitMuxed = $formatType === 'muxed';

        if ($selectorAlreadyCombined) {
            $formatSelector = $formatId;
            $expectsMerge = true;
        } elseif (!$isExplicitMuxed && ($formatType === 'video' || !$selectorHasAudio)) {
            // Video-only id should be paired with best available audio to avoid silent output.
            $formatSelector = $formatId . '+bestaudio/' . $formatId . '/best';
            $expectsMerge = true;
        } else {
            $formatSelector = $formatId;
        }

    } else {
        $formatSelector = 'bestvideo[height<=?2160]+bestaudio/best';
        $expectsMerge = true;
    }
}

$cmdParts[] = '-f';
$cmdParts[] = $formatSelector;

$cmdParts[] = '-o';
$cmdParts[] = $outputTpl;
$cmdParts[] = $rawUrl;

@file_put_contents($progressFile, '');

$metadata = [
    'job_id' => $jobId,
    'url' => $rawUrl,
    'format_id' => $formatId,
    'format' => $formatType,
    'quality' => $quality,
    'format_selector' => $formatSelector,
    'expects_merge' => $expectsMerge,
    'start' => time(),
    'command' => $cmdParts,
    'progress_file' => $progressFile,
    'pid_file' => $pidFile,
    'output_tpl' => $outputTpl,
];

@file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));

$nohupCmd = 'nohup ' . shell_join($cmdParts)
    . ' > ' . escapeshellarg($progressFile)
    . ' 2>&1 < /dev/null & echo $!';

$pid = trim((string) shell_exec($nohupCmd));

if ($pid === '' || !ctype_digit($pid)) {
    $metadata['pid'] = $pid;
    @file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));
    json_response(['status' => 'error', 'message' => 'Gagal memulai proses download (yt-dlp tidak berjalan)'], 500);
}

@file_put_contents($pidFile, $pid);

$metadata['pid'] = $pid;
@file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));

$progressUrl = full_url('api/progress.php?id=' . $jobId);

json_response([
    'status' => 'started',
    'job_id' => $jobId,
    'message' => 'Download dimulai',
    'progress_url' => $progressUrl,
]);
