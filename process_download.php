<?php
require_once 'config.php';

/* ===== VALIDASI ===== */
if (empty($_POST['url'])) {
    json_response(['status' => 'error', 'message' => 'URL kosong']);
}

$url = escapeshellarg($_POST['url']);

/* ===== GET FORMAT DATA ===== */
$formatId = $_POST['format_id'] ?? '';
$formatType = $_POST['format_type'] ?? 'video';
$selectedQuality = $_POST['quality'] ?? '';
$formatData = $_POST['format_data'] ?? '';

// Decode format data
$formatInfo = [];
if (!empty($formatData)) {
    $formatInfo = json_decode($formatData, true);
}

/* ===== JOB ID ===== */
$jobId = uniqid('vd_', true);

/* ===== PATHS ===== */
$progressFile = TEMP_PROGRESS . $jobId . '.log';
$pidFile = TEMP_PROGRESS . $jobId . '.pid';
$metadataFile = TEMP_PROGRESS . $jobId . '.json';
$outputFile = TEMP_FILES . $jobId . '.%(ext)s';

/* ===== BUILD COMMAND ===== */
$cmd = YTDLP_BIN;

// Common options
$cmd .= " --no-playlist";
$cmd .= " --no-warnings";
$cmd .= " --newline";
$cmd .= " --progress";
$cmd .= " --console-title";
$cmd .= " --no-part";
$cmd .= " --restrict-filenames";
$cmd .= " --socket-timeout 30";
$cmd .= " --retries 10";
$cmd .= " --fragment-retries 10";
$cmd .= " --skip-unavailable-fragments";

/* ===== FORMAT SPECIFIC ===== */
if ($formatType === 'audio') {
    // Audio download
    $cmd .= " -x --audio-format mp3";
    $cmd .= " --audio-quality 0";
    $cmd .= " --postprocessor-args \"-ar 44100 -ac 2 -b:a 192k\"";
    
    if (!empty($formatId)) {
        $cmd .= " -f \"$formatId\"";
    } else {
        $cmd .= " -f \"bestaudio[ext=m4a]/bestaudio\"";
    }
    
} else {
    // Video download
    
    // Handle format selection
    if (!empty($formatId)) {
        if (isset($formatInfo['video_id']) && isset($formatInfo['audio_id'])) {
            // Combined video+audio
            $cmd .= " -f \"{$formatInfo['video_id']}+{$formatInfo['audio_id']}\"";
            $cmd .= " --merge-output-format mp4";
            $cmd .= " --audio-quality 0";
            $cmd .= " --postprocessor-args \"-c:v copy -c:a aac -b:a 192k\"";
        } else {
            // Single format
            $cmd .= " -f \"$formatId\"";
            $cmd .= " --merge-output-format mp4";
            
            // Force audio conversion to AAC for better compatibility
            $cmd .= " --postprocessor-args \"-c:a aac -b:a 192k\"";
        }
    } else {
        // Auto selection based on quality
        $qualityMap = [
            '2160' => 'bestvideo[height<=2160][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=2160]',
            '1440' => 'bestvideo[height<=1440][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1440]',
            '1080' => 'bestvideo[height<=1080][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1080]',
            '720' => 'bestvideo[height<=720][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=720]',
            '480' => 'bestvideo[height<=480]+bestaudio/best[height<=480]',
            '360' => 'bestvideo[height<=360]+bestaudio/best[height<=360]',
        ];
        
        $fallbackFormat = $qualityMap[$selectedQuality] ?? 'bestvideo[vcodec^=avc1]+bestaudio[ext=m4a]/best';
        $cmd .= " -f \"$fallbackFormat\"";
        $cmd .= " --merge-output-format mp4";
        $cmd .= " --postprocessor-args \"-c:a aac -b:a 192k\"";
    }
    
    // Add optional recoding
    if (isset($_POST['recode']) && $_POST['recode'] === 'true') {
        $cmd .= " --recode-video mp4";
    }
}

// Output configuration
$cmd .= " -o \"$outputFile\"";
$cmd .= " $url";

// Add progress logging
$cmd .= " 2>&1 | tee \"$progressFile\"";

/* ===== CREATE METADATA ===== */
$metadata = [
    'job_id' => $jobId,
    'url' => $_POST['url'],
    'format_id' => $formatId,
    'format_type' => $formatType,
    'quality' => $selectedQuality,
    'format_info' => $formatInfo,
    'start_time' => time(),
    'status' => 'starting',
    'command' => $cmd // For debugging
];

file_put_contents($metadataFile, json_encode($metadata));

/* ===== EXECUTE ASYNC ===== */
// Write command to shell script
$shellScript = TEMP_PROGRESS . $jobId . '.sh';
file_put_contents($shellScript, "#!/bin/bash\n" . $cmd . "\necho $? > \"$pidFile\"");
chmod($shellScript, 0755);

// Execute in background
$asyncCmd = "nohup bash \"$shellScript\" > /dev/null 2>&1 & echo $!";
$pid = shell_exec($asyncCmd);

// Update metadata with PID
$metadata['pid'] = trim($pid);
file_put_contents($metadataFile, json_encode($metadata));

/* ===== RESPONSE ===== */
json_response([
    'status' => 'started',
    'job_id' => $jobId,
    'message' => 'Download telah dimulai',
    'details' => [
        'type' => $formatType === 'audio' ? 'MP3 Audio' : 'Video',
        'quality' => $selectedQuality,
        'progress_url' => 'progress.php?id=' . $jobId
    ]
]);
?>