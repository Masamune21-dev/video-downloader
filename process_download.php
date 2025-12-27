<?php
require_once 'config.php';

/* =========================================================
   VALIDASI INPUT
   ========================================================= */
if (empty($_POST['url'])) {
    json_response([
        'status' => 'error',
        'message' => 'URL tidak boleh kosong'
    ]);
}

$url = escapeshellarg($_POST['url']);

$formatId   = $_POST['format_id']   ?? '';
$formatType = $_POST['format_type'] ?? 'video';
$quality    = $_POST['quality']     ?? '';
$formatData = $_POST['format_data'] ?? '';

$formatInfo = [];
if ($formatData) {
    $decoded = json_decode($formatData, true);
    if (is_array($decoded)) {
        $formatInfo = $decoded;
    }
}

/* =========================================================
   JOB ID & PATH
   ========================================================= */
$jobId = uniqid('vd_', true);

$progressFile = TEMP_PROGRESS . $jobId . '.log';
$pidFile      = TEMP_PROGRESS . $jobId . '.pid';
$metaFile     = TEMP_PROGRESS . $jobId . '.json';
$outputTpl    = TEMP_FILES . $jobId . '.%(ext)s';

/* =========================================================
   BUILD yt-dlp COMMAND (STABLE & SAFE)
   ========================================================= */
$cmd = YTDLP_BIN;
$cmd .= " --no-playlist";
$cmd .= " --newline";
$cmd .= " --progress";
$cmd .= " --merge-output-format mp4";
$cmd .= " --restrict-filenames";
$cmd .= " --retries 10";
$cmd .= " --fragment-retries 10";
$cmd .= " --socket-timeout 30";

/* =========================================================
   FORMAT HANDLING
   ========================================================= */
if ($formatType === 'audio') {
    // ===== AUDIO ONLY (MP3) =====
    $cmd .= " -x --audio-format mp3 --audio-quality 0";
    $cmd .= " -f \"bestaudio\"";

} else {
    // ===== VIDEO =====
    if (!empty($formatInfo['video_id']) && !empty($formatInfo['audio_id'])) {
        // Combined video + audio (untuk kualitas tinggi)
        $cmd .= " -f \"{$formatInfo['video_id']}+{$formatInfo['audio_id']}\"";
        
        // Tambah parameter untuk kualitas tinggi
        $cmd .= " --recode-video mp4";
        $cmd .= " --postprocessor-args \"-c:v libx264 -preset medium -crf 18\"";
        
    } elseif (!empty($formatId)) {
        // Single selected format
        $cmd .= " -f \"$formatId\"";
        
        // Jika format tinggi, gunakan setting khusus
        $quality = $formatInfo['height'] ?? 0;
        if ($quality >= 1440) {
            $cmd .= " --recode-video mp4";
            $cmd .= " --postprocessor-args \"-c:v libx264 -preset slow -crf 17\"";
        }
        
    } else {
        // Fallback best
        $cmd .= " -f \"bestvideo[height<=?2160]+bestaudio/best\"";
    }
}

/* =========================================================
   OUTPUT & EXECUTION
   ========================================================= */
$cmd .= " -o \"$outputTpl\"";
$cmd .= " $url";
$cmd .= " 2>&1 | tee \"$progressFile\"";

/* =========================================================
   SAVE METADATA
   ========================================================= */
$metadata = [
    'job_id'    => $jobId,
    'url'       => $_POST['url'],
    'format_id' => $formatId,
    'format'    => $formatType,
    'quality'   => $quality,
    'start'     => time(),
    'command'   => $cmd
];
file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));

/* =========================================================
   CREATE & RUN ASYNC SHELL SCRIPT
   ========================================================= */
$shellScript = TEMP_PROGRESS . $jobId . '.sh';

file_put_contents(
    $shellScript,
    "#!/bin/bash\n$cmd\necho \$! > \"$pidFile\""
);

chmod($shellScript, 0755);

// Run async
$pid = shell_exec("nohup bash \"$shellScript\" > /dev/null 2>&1 & echo $!");

// Save PID
$metadata['pid'] = trim($pid);
file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));

/* =========================================================
   RESPONSE
   ========================================================= */
json_response([
    'status'  => 'started',
    'job_id'  => $jobId,
    'message' => 'Download dimulai',
    'progress_url' => full_url('progress.php?id=' . $jobId)
]);
