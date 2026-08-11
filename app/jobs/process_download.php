<?php

if (empty($_POST['url'])) {
    json_response(['status' => 'error', 'message' => 'URL tidak boleh kosong'], 400);
}

$rawUrl = trim((string) $_POST['url']);
$downloadUrl = normalize_tiktok_photo_url_for_ytdlp($rawUrl);
$urlParts = parse_url($rawUrl);
$allowedSchemes = ['http', 'https'];

if (!$urlParts || empty($urlParts['scheme']) || !in_array(strtolower((string)$urlParts['scheme']), $allowedSchemes, true)) {
    json_response(['status' => 'error', 'message' => 'URL tidak valid'], 400);
}

$formatId    = trim((string) ($_POST['format_id'] ?? ''));
$formatType  = trim((string) ($_POST['format_type'] ?? 'video'));
$quality     = $_POST['quality'] ?? '';
$formatData  = $_POST['format_data'] ?? '';
// Output preferences (added for multi-format support).
$container   = strtolower(trim((string) ($_POST['container'] ?? 'mp4')));      // mp4 | mkv | webm
$audioFormat = strtolower(trim((string) ($_POST['audio_format'] ?? 'mp3')));   // mp3 | m4a | opus | flac | wav

$allowedFormatTypes = ['video', 'audio', 'image', 'muxed', 'combined'];
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

/**
 * Background jobs need the same extensions as the web app (curl to scrape
 * TikTok slideshows, zip to pack them). `php` on PATH is not necessarily the
 * version serving the site — on this box the web runs 8.3 with curl+zip while
 * /usr/bin/php is 8.4 with neither, which silently degraded image jobs to a
 * single cover thumbnail. So prefer the CLI matching the running version.
 */
function php_cli_bin(): string {
    $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    foreach ([PHP_BINDIR . '/php' . $version, '/usr/bin/php' . $version] as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }

    $candidate = trim((string) @shell_exec('command -v php 2>/dev/null'));
    if ($candidate !== '' && is_executable($candidate)) return $candidate;

    $candidate = PHP_BINDIR . '/php';
    if (is_executable($candidate)) return $candidate;

    return PHP_BINARY;
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
if ($formatType === 'image') {
    if ($formatId !== '' && $formatId !== 'images_selected' && !preg_match('/^image_\d{3}$/', $formatId)) {
        json_response(['status' => 'error', 'message' => 'Format gambar tidak valid'], 400);
    }

    $cmdParts = [
        php_cli_bin(),
        APP_ROOT . '/app/jobs/process_images.php',
        $jobId,
    ];
    $formatSelector = 'all_thumbnails';
    $imageIndexes = image_indexes_from_format_info($formatInfo, $formatId);

} elseif ($formatType === 'audio') {
    // Audio: user-chosen output format (mp3 default). yt-dlp copies the stream
    // when the source already matches (e.g. opus/m4a) and only re-encodes when
    // it must, so quality is preserved where possible.
    $allowedAudio = ['mp3', 'm4a', 'opus', 'flac', 'wav'];
    $audioFmt = in_array($audioFormat, $allowedAudio, true) ? $audioFormat : 'mp3';

    $cmdParts[] = '-x';
    $cmdParts[] = '--audio-format';
    $cmdParts[] = $audioFmt;
    if (in_array($audioFmt, ['mp3', 'm4a', 'opus'], true)) {
        // --audio-quality only applies to lossy encoders; 0 = best.
        $cmdParts[] = '--audio-quality';
        $cmdParts[] = '0';
    }

    $formatSelector = 'bestaudio/best';

} else {
    // Video: user-chosen container (mp4 default) + resolution cap from quality.
    $allowedContainers = ['mp4', 'mkv', 'webm'];
    $cont = in_array($container, $allowedContainers, true) ? $container : 'mp4';

    $height = ctype_digit((string) $quality) ? (int) $quality : 0;
    $hcap = $height > 0 ? "[height<=?{$height}]" : '';

    if ($cont === 'webm') {
        // Prefer native webm-compatible streams (only offered by the UI when
        // such streams exist); fall back to best at the cap without forcing a
        // doomed remux of an h264 muxed file.
        $formatSelector = "bv*{$hcap}[vcodec~='^(av01|vp0?9|vp8)']+ba[acodec~='^(opus|vorbis)']"
            . "/bv*{$hcap}[vcodec~='^(av01|vp0?9|vp8)']+ba"
            . "/b{$hcap}[ext=webm]/b{$hcap}/b";
        $cmdParts[] = '--merge-output-format';
        $cmdParts[] = 'webm';
    } elseif ($cont === 'mkv') {
        // MKV holds any codec; remux (no re-encode) guarantees the container.
        $formatSelector = "bv*{$hcap}+ba/b{$hcap}/b";
        $cmdParts[] = '--merge-output-format';
        $cmdParts[] = 'mkv';
        $cmdParts[] = '--remux-video';
        $cmdParts[] = 'mkv';
    } else { // mp4
        // Prefer avc+aac (zero re-encode); otherwise merge/remux into mp4
        // (modern codecs copy cleanly into mp4).
        $formatSelector = "bv*{$hcap}[vcodec~='^(avc|h264)']+ba[acodec~='^(mp4a|aac)']"
            . "/bv*{$hcap}+ba"
            . "/b{$hcap}[ext=mp4]/b{$hcap}/b";
        $cmdParts[] = '--merge-output-format';
        $cmdParts[] = 'mp4';
        $cmdParts[] = '--remux-video';
        $cmdParts[] = 'mp4';
    }

    $expectsMerge = true;
}

if ($formatType !== 'image') {
    $cmdParts[] = '-f';
    $cmdParts[] = $formatSelector;

    $cmdParts[] = '-o';
    $cmdParts[] = $outputTpl;
    $cmdParts[] = $downloadUrl;
}

$metadataImageIndexes = !empty($imageIndexes ?? [])
    ? array_values($imageIndexes)
    : [image_index_from_format_id($formatId)];

@file_put_contents($progressFile, '');

$metadata = [
    'job_id' => $jobId,
    'url' => $rawUrl,
    'download_url' => $downloadUrl,
    'format_id' => $formatId,
    'format' => $formatType,
    'quality' => $quality,
    'container' => $formatType === 'video' ? $container : null,
    'audio_format' => $formatType === 'audio' ? $audioFormat : null,
    'format_selector' => $formatSelector,
    'expects_merge' => $expectsMerge,
    'title' => (string) ($formatInfo['title'] ?? ''),
    'image_index' => $metadataImageIndexes[0],
    'image_indexes' => $formatType === 'image' ? $metadataImageIndexes : [],
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

function image_index_from_format_id(string $formatId): int {
    if (preg_match('/^image_(\d{3})$/', $formatId, $matches)) {
        return max(0, (int) $matches[1] - 1);
    }
    return 0;
}

function image_indexes_from_format_info(array $formatInfo, string $formatId): array {
    $indexes = [];

    if (isset($formatInfo['image_indexes']) && is_array($formatInfo['image_indexes'])) {
        foreach ($formatInfo['image_indexes'] as $index) {
            if (!is_numeric($index)) continue;
            $indexes[] = max(0, (int) $index);
        }
    } elseif (isset($formatInfo['image_index']) && is_numeric($formatInfo['image_index'])) {
        $indexes[] = max(0, (int) $formatInfo['image_index']);
    } else {
        $indexes[] = image_index_from_format_id($formatId);
    }

    $indexes = array_values(array_unique($indexes));
    sort($indexes, SORT_NUMERIC);

    return array_slice($indexes, 0, 50);
}
