<?php
require_once 'config.php';

$action = $_GET['action'] ?? '';

if ($action === 'analyze') {
    $url = escapeshellarg($_POST['url']);
    
    // Gunakan output yang lebih detail dengan format spesifik
    $cmd = YTDLP_BIN . " -J --no-playlist --format-sort \"res,codec,br\" $url 2>/dev/null";
    $output = shell_exec($cmd);
    
    if (!$output) {
        json_response([
            'status' => 'error',
            'message' => 'Gagal menganalisis video'
        ]);
    }
    
    $data = json_decode($output, true);
    $availableFormats = [];
    
    // Proses setiap format untuk mendapatkan video DENGAN audio
    if (isset($data['formats'])) {
        foreach ($data['formats'] as $format) {
            // Filter hanya format yang memiliki video DAN audio
            $hasVideo = isset($format['vcodec']) && $format['vcodec'] != 'none';
            $hasAudio = isset($format['acodec']) && $format['acodec'] != 'none';
            
            if ($hasVideo && $hasAudio) {
                // Format dengan video+audio (muxed)
                $formatInfo = [
                    'format_id' => $format['format_id'] ?? '',
                    'ext' => $format['ext'] ?? '',
                    'resolution' => $format['resolution'] ?? (isset($format['height']) ? $format['height'] . 'p' : 'Unknown'),
                    'height' => $format['height'] ?? 0,
                    'filesize' => isset($format['filesize']) ? format_file_size($format['filesize']) : 'Unknown',
                    'filesize_bytes' => $format['filesize'] ?? 0,
                    'vcodec' => $format['vcodec'] ?? '',
                    'acodec' => $format['acodec'] ?? '',
                    'note' => $format['format_note'] ?? '',
                    'type' => 'muxed' // Video+audio sudah digabung
                ];
                $availableFormats[] = $formatInfo;
            }
        }
    }
    
    // Jika tidak ada format muxed, ambil format video only dan audio only terpisah
    if (empty($availableFormats)) {
        $videoOnlyFormats = [];
        $audioOnlyFormats = [];
        
        foreach ($data['formats'] as $format) {
            $hasVideo = isset($format['vcodec']) && $format['vcodec'] != 'none';
            $hasAudio = isset($format['acodec']) && $format['acodec'] != 'none';
            
            if ($hasVideo && !$hasAudio) {
                // Video only
                $videoOnlyFormats[] = [
                    'format_id' => $format['format_id'],
                    'height' => $format['height'] ?? 0,
                    'vcodec' => $format['vcodec'] ?? '',
                    'filesize' => isset($format['filesize']) ? format_file_size($format['filesize']) : 'Unknown'
                ];
            }
            
            if ($hasAudio && !$hasVideo) {
                // Audio only
                $audioOnlyFormats[] = [
                    'format_id' => $format['format_id'],
                    'acodec' => $format['acodec'] ?? '',
                    'filesize' => isset($format['filesize']) ? format_file_size($format['filesize']) : 'Unknown',
                    'bitrate' => isset($format['abr']) ? $format['abr'] . 'kbps' : 'Unknown'
                ];
            }
        }
        
        // Gabungkan video terbaik dengan audio terbaik
        if (!empty($videoOnlyFormats) && !empty($audioOnlyFormats)) {
            // Urutkan video dari kualitas tertinggi
            usort($videoOnlyFormats, function($a, $b) {
                return $b['height'] - $a['height'];
            });
            
            // Urutkan audio dari bitrate tertinggi
            usort($audioOnlyFormats, function($a, $b) {
                $bitrateA = isset($a['bitrate']) ? intval($a['bitrate']) : 0;
                $bitrateB = isset($b['bitrate']) ? intval($b['bitrate']) : 0;
                return $bitrateB - $bitrateA;
            });
            
            // Buat format kombinasi
            $bestVideo = $videoOnlyFormats[0];
            $bestAudio = $audioOnlyFormats[0];
            
            $availableFormats[] = [
                'format_id' => $bestVideo['format_id'] . '+' . $bestAudio['format_id'],
                'ext' => 'mp4',
                'resolution' => $bestVideo['height'] . 'p',
                'height' => $bestVideo['height'],
                'filesize' => 'Unknown (will merge)',
                'filesize_bytes' => 0,
                'vcodec' => $bestVideo['vcodec'],
                'acodec' => $bestAudio['acodec'],
                'note' => 'Video+Audio (merged)',
                'type' => 'combined',
                'video_id' => $bestVideo['format_id'],
                'audio_id' => $bestAudio['format_id']
            ];
        }
    }
    
    // Cari format audio untuk MP3 (hanya audio only)
    $audioFormats = [];
    if (isset($data['formats'])) {
        foreach ($data['formats'] as $format) {
            if (isset($format['acodec']) && $format['acodec'] != 'none' && 
                (!isset($format['vcodec']) || $format['vcodec'] == 'none')) {
                $audioFormats[] = [
                    'format_id' => $format['format_id'],
                    'ext' => $format['ext'],
                    'filesize' => isset($format['filesize']) ? format_file_size($format['filesize']) : 'Unknown',
                    'bitrate' => isset($format['abr']) ? $format['abr'] . 'kbps' : 'Unknown',
                    'acodec' => $format['acodec'] ?? ''
                ];
            }
        }
    }
    
    json_response([
        'status' => 'success',
        'title' => $data['title'] ?? '-',
        'thumbnail' => $data['thumbnail'] ?? '',
        'duration' => $data['duration_string'] ?? '',
        'duration_seconds' => $data['duration'] ?? 0,
        'uploader' => $data['uploader'] ?? '',
        'view_count' => $data['view_count'] ?? 0,
        'available_formats' => $availableFormats,
        'audio_formats' => array_slice($audioFormats, 0, 5),
        'has_muxed_formats' => !empty(array_filter($availableFormats, function($f) { 
            return isset($f['type']) && $f['type'] === 'muxed'; 
        }))
    ]);
}

// Tambahkan fungsi helper untuk format ukuran file
function format_file_size($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
if ($action === 'download') {
    require 'process_download.php';
}

json_response([
    'status' => 'error',
    'message' => 'Invalid request'
]);
