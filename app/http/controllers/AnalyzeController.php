<?php

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$urlRaw = trim((string) ($_POST['url'] ?? ''));
if ($urlRaw === '') {
    json_response(['status' => 'error', 'message' => 'URL tidak boleh kosong'], 400);
}

$urlParts = parse_url($urlRaw);
$allowedSchemes = ['http', 'https'];

if (!$urlParts || empty($urlParts['scheme']) || !in_array(strtolower((string)$urlParts['scheme']), $allowedSchemes, true)) {
    json_response(['status' => 'error', 'message' => 'URL tidak valid'], 400);
}

$cmdParts = [
    ytdlp_bin(),
    ...ytdlp_base_args(),
    '--no-warnings',
    '-J',
];

if (defined('YTDLP_NO_PLAYLIST') && YTDLP_NO_PLAYLIST) {
    $cmdParts[] = '--no-playlist';
}

$cmdParts[] = $urlRaw;

$output = (string) @shell_exec(shell_join($cmdParts) . ' 2>&1');
$output = trim($output);

if ($output === '' || stripos($output, 'ERROR:') !== false) {
    $msg = 'Gagal menganalisis video';
    $lines = preg_split("/\r\n|\n|\r/", $output) ?: [];
    foreach (array_reverse($lines) as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $line = preg_replace('/^(ERROR:|WARNING:)\s*/', '', $line);
        $msg = $line;
        break;
    }
    json_response(['status' => 'error', 'message' => $msg], 400);
}

$data = json_decode($output, true);
if (!is_array($data)) {
    json_response(['status' => 'error', 'message' => 'Output analyzer tidak valid'], 500);
}

$availableFormats = [];
$videoOnlyFormats = [];
$audioOnlyFormats = [];

$formats = $data['formats'] ?? [];
if (is_array($formats)) {
    foreach ($formats as $format) {
        if (!is_array($format)) continue;

        $vcodec = (string) ($format['vcodec'] ?? 'none');
        $acodec = (string) ($format['acodec'] ?? 'none');

        $hasVideo = $vcodec !== 'none';
        $hasAudio = $acodec !== 'none';

        if ($hasVideo && $hasAudio) {
            $availableFormats[] = [
                'format_id' => (string) ($format['format_id'] ?? ''),
                'ext' => (string) ($format['ext'] ?? ''),
                'resolution' => get_resolution_string($format),
                'height' => (int) ($format['height'] ?? 0),
                'width' => (int) ($format['width'] ?? 0),
                'filesize' => isset($format['filesize']) ? format_file_size((int)$format['filesize']) : 'Unknown',
                'filesize_bytes' => (int) ($format['filesize'] ?? 0),
                'vcodec' => $vcodec,
                'acodec' => $acodec,
                'note' => (string) ($format['format_note'] ?? ''),
                'type' => 'muxed',
                'bitrate' => (float) ($format['abr'] ?? $format['tbr'] ?? 0),
            ];
        }

        if ($hasVideo && !$hasAudio) {
            $videoOnlyFormats[] = [
                'format_id' => (string) ($format['format_id'] ?? ''),
                'ext' => (string) ($format['ext'] ?? ''),
                'resolution' => get_resolution_string($format),
                'height' => (int) ($format['height'] ?? 0),
                'width' => (int) ($format['width'] ?? 0),
                'filesize' => isset($format['filesize']) ? format_file_size((int)$format['filesize']) : 'Unknown',
                'filesize_bytes' => (int) ($format['filesize'] ?? 0),
                'vcodec' => $vcodec,
                'fps' => (float) ($format['fps'] ?? 0),
                'dynamic_range' => (string) ($format['dynamic_range'] ?? 'SDR'),
                'type' => 'video',
                'bitrate' => (float) ($format['tbr'] ?? 0),
            ];
        }

        if ($hasAudio && !$hasVideo) {
            $audioOnlyFormats[] = [
                'format_id' => (string) ($format['format_id'] ?? ''),
                'ext' => (string) ($format['ext'] ?? ''),
                'acodec' => $acodec,
                'filesize' => isset($format['filesize']) ? format_file_size((int)$format['filesize']) : 'Unknown',
                'filesize_bytes' => (int) ($format['filesize'] ?? 0),
                'type' => 'audio',
                'bitrate' => (float) ($format['abr'] ?? 0),
                'quality' => (int) ($format['quality'] ?? 0),
            ];
        }
    }
}

usort($videoOnlyFormats, fn($a, $b) => ($b['height'] ?? 0) <=> ($a['height'] ?? 0));
usort($audioOnlyFormats, fn($a, $b) => ($b['bitrate'] ?? 0) <=> ($a['bitrate'] ?? 0));

$bestAudioFormat = pick_best_audio_for_merge($audioOnlyFormats);

if (!empty($videoOnlyFormats) && $bestAudioFormat) {
    foreach ($videoOnlyFormats as $videoFormat) {
        $availableFormats[] = [
            'format_id' => $videoFormat['format_id'] . '+' . $bestAudioFormat['format_id'],
            'ext' => 'mp4',
            'resolution' => $videoFormat['resolution'],
            'height' => (int) $videoFormat['height'],
            'width' => (int) $videoFormat['width'],
            'filesize' => 'Auto Merge',
            'filesize_bytes' => 0,
            'vcodec' => (string) $videoFormat['vcodec'],
            'acodec' => (string) $bestAudioFormat['acodec'],
            'note' => 'High Quality (merged)',
            'type' => 'combined',
            'video_id' => (string) $videoFormat['format_id'],
            'audio_id' => (string) $bestAudioFormat['format_id'],
            'dynamic_range' => (string) ($videoFormat['dynamic_range'] ?? 'SDR'),
            'fps' => (float) ($videoFormat['fps'] ?? 0),
        ];
    }
}

usort($availableFormats, fn($a, $b) => ($b['height'] ?? 0) <=> ($a['height'] ?? 0));

$audioFormats = [];
foreach ($formats as $format) {
    if (!is_array($format)) continue;

    $acodec = (string) ($format['acodec'] ?? 'none');
    $vcodec = (string) ($format['vcodec'] ?? 'none');

    if ($acodec !== 'none' && $vcodec === 'none') {
        $abr = isset($format['abr']) ? (float)$format['abr'] : 0.0;
        $audioFormats[] = [
            'format_id' => (string) ($format['format_id'] ?? ''),
            'ext' => (string) ($format['ext'] ?? ''),
            'filesize' => isset($format['filesize']) ? format_file_size((int)$format['filesize']) : 'Unknown',
            'bitrate' => $abr > 0 ? (string) (round($abr) . 'kbps') : 'Unknown',
            'acodec' => $acodec,
            'type' => 'audio'
        ];
    }
}

usort($audioFormats, function($a, $b) {
    $abrA = (float) preg_replace('/[^0-9.]/', '', (string) ($a['bitrate'] ?? '0'));
    $abrB = (float) preg_replace('/[^0-9.]/', '', (string) ($b['bitrate'] ?? '0'));
    return $abrB <=> $abrA;
});

json_response([
    'status' => 'success',
    'title' => (string) ($data['title'] ?? '-'),
    'thumbnail' => (string) ($data['thumbnail'] ?? ''),
    'duration' => (string) ($data['duration_string'] ?? ''),
    'duration_seconds' => (int) ($data['duration'] ?? 0),
    'uploader' => (string) ($data['uploader'] ?? ''),
    'view_count' => (int) ($data['view_count'] ?? 0),
    'available_formats' => $availableFormats,
    'audio_formats' => array_slice($audioFormats, 0, 5),
    'has_high_quality' => !empty(array_filter($availableFormats, fn($f) => ((int)($f['height'] ?? 0)) >= 1440)),
]);

function pick_best_audio_for_merge(array $audioOnlyFormats): ?array {
    if (empty($audioOnlyFormats)) return null;

    $preferred = [];
    $fallback = [];

    foreach ($audioOnlyFormats as $f) {
        $ext = strtolower((string) ($f['ext'] ?? ''));
        $acodec = strtolower((string) ($f['acodec'] ?? ''));

        $startsMp4a = (strpos($acodec, 'mp4a') === 0);

        $isMp4Audio = in_array($ext, ['m4a', 'mp4'], true)
            || $startsMp4a
            || $acodec === 'aac';

        if ($isMp4Audio) $preferred[] = $f;
        else $fallback[] = $f;
    }

    usort($preferred, fn($a, $b) => ((float)($b['bitrate'] ?? 0)) <=> ((float)($a['bitrate'] ?? 0)));
    usort($fallback, fn($a, $b) => ((float)($b['bitrate'] ?? 0)) <=> ((float)($a['bitrate'] ?? 0)));

    return $preferred[0] ?? $fallback[0] ?? null;
}

function get_resolution_string($format): string {
    if (isset($format['resolution']) && $format['resolution'] !== null) return (string) $format['resolution'];
    if (isset($format['height'])) {
        $height = (int) $format['height'];
        if ($height >= 2160) return '4K';
        if ($height >= 1440) return '2K';
        if ($height >= 1080) return '1080p';
        if ($height >= 720) return '720p';
        if ($height >= 480) return '480p';
        if ($height >= 360) return '360p';
        return $height . 'p';
    }
    return 'Unknown';
}

// format_file_size() is now provided by app/helpers.php