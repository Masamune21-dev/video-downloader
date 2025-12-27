<?php
require_once 'config.php';

$action = $_GET['action'] ?? '';

if ($action === 'analyze') {
    $url = escapeshellarg($_POST['url']);
    
    // Gunakan output yang lebih detail dengan format spesifik
    $cmd = YTDLP_BIN . " -J --no-playlist $url 2>/dev/null";
    $output = shell_exec($cmd);
    
    if (!$output) {
        json_response([
            'status' => 'error',
            'message' => 'Gagal menganalisis video'
        ]);
    }
    
    $data = json_decode($output, true);
    $availableFormats = [];
    $videoOnlyFormats = [];
    $audioOnlyFormats = [];
    $bestAudioFormat = null;
    
    // Proses semua format
    if (isset($data['formats'])) {
        foreach ($data['formats'] as $format) {
            $hasVideo = isset($format['vcodec']) && $format['vcodec'] != 'none';
            $hasAudio = isset($format['acodec']) && $format['acodec'] != 'none';
            
            // Format muxed (video+audio)
            if ($hasVideo && $hasAudio) {
                $availableFormats[] = [
                    'format_id' => $format['format_id'] ?? '',
                    'ext' => $format['ext'] ?? '',
                    'resolution' => get_resolution_string($format),
                    'height' => $format['height'] ?? 0,
                    'width' => $format['width'] ?? 0,
                    'filesize' => isset($format['filesize']) ? format_file_size($format['filesize']) : 'Unknown',
                    'filesize_bytes' => $format['filesize'] ?? 0,
                    'vcodec' => $format['vcodec'] ?? '',
                    'acodec' => $format['acodec'] ?? '',
                    'note' => $format['format_note'] ?? '',
                    'type' => 'muxed',
                    'bitrate' => $format['abr'] ?? $format['tbr'] ?? 0
                ];
            }
            
            // Format video only (untuk kualitas tinggi)
            if ($hasVideo && !$hasAudio) {
                $videoOnlyFormats[] = [
                    'format_id' => $format['format_id'] ?? '',
                    'ext' => $format['ext'] ?? '',
                    'resolution' => get_resolution_string($format),
                    'height' => $format['height'] ?? 0,
                    'width' => $format['width'] ?? 0,
                    'filesize' => isset($format['filesize']) ? format_file_size($format['filesize']) : 'Unknown',
                    'filesize_bytes' => $format['filesize'] ?? 0,
                    'vcodec' => $format['vcodec'] ?? '',
                    'fps' => $format['fps'] ?? 0,
                    'dynamic_range' => isset($format['dynamic_range']) ? $format['dynamic_range'] : 'SDR',
                    'type' => 'video',
                    'bitrate' => $format['tbr'] ?? 0
                ];
            }
            
            // Format audio only (untuk gabungan)
            if ($hasAudio && !$hasVideo) {
                $audioOnlyFormats[] = [
                    'format_id' => $format['format_id'] ?? '',
                    'ext' => $format['ext'] ?? '',
                    'acodec' => $format['acodec'] ?? '',
                    'filesize' => isset($format['filesize']) ? format_file_size($format['filesize']) : 'Unknown',
                    'filesize_bytes' => $format['filesize'] ?? 0,
                    'type' => 'audio',
                    'bitrate' => $format['abr'] ?? 0,
                    'quality' => isset($format['quality']) ? $format['quality'] : 0
                ];
            }
        }
    }
    
    // Urutkan video only dari kualitas tertinggi (4K, 2K, 1080p, dll)
    usort($videoOnlyFormats, function($a, $b) {
        return $b['height'] - $a['height'];
    });
    
    // Urutkan audio only dari bitrate tertinggi
    usort($audioOnlyFormats, function($a, $b) {
        return $b['bitrate'] - $a['bitrate'];
    });
    
    // Ambil audio terbaik untuk kombinasi
    $bestAudioFormat = !empty($audioOnlyFormats) ? $audioOnlyFormats[0] : null;
    
    // Buat format kombinasi dari video-only + audio-only (untuk kualitas tinggi)
    if (!empty($videoOnlyFormats) && $bestAudioFormat) {
        foreach ($videoOnlyFormats as $videoFormat) {
            // Skip jika resolusi terlalu rendah (opsional)
            if ($videoFormat['height'] < 720) continue;
            
            $availableFormats[] = [
                'format_id' => $videoFormat['format_id'] . '+' . $bestAudioFormat['format_id'],
                'ext' => 'mp4',
                'resolution' => $videoFormat['resolution'],
                'height' => $videoFormat['height'],
                'width' => $videoFormat['width'],
                'filesize' => 'Auto Merge',
                'filesize_bytes' => 0,
                'vcodec' => $videoFormat['vcodec'],
                'acodec' => $bestAudioFormat['acodec'],
                'note' => 'High Quality (merged)',
                'type' => 'combined',
                'video_id' => $videoFormat['format_id'],
                'audio_id' => $bestAudioFormat['format_id'],
                'dynamic_range' => $videoFormat['dynamic_range'] ?? 'SDR',
                'fps' => $videoFormat['fps'] ?? 0
            ];
        }
    }
    
    // Urutkan semua format dari kualitas tertinggi
    usort($availableFormats, function($a, $b) {
        return $b['height'] - $a['height'];
    });
    
    // Filter audio formats untuk MP3
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
                    'acodec' => $format['acodec'] ?? '',
                    'type' => 'audio'
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
        'has_high_quality' => !empty(array_filter($availableFormats, function($f) { 
            return $f['height'] >= 1440; // 2K+
        }))
    ]);
}

// Helper function untuk mendapatkan string resolusi
function get_resolution_string($format) {
    if (isset($format['resolution'])) {
        return $format['resolution'];
    }
    
    if (isset($format['height'])) {
        $height = $format['height'];
        $width = $format['width'] ?? round($height * 16/9);
        
        // Deteksi 4K, 2K, dll
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