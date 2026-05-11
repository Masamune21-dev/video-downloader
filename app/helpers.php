<?php
declare(strict_types=1);

/**
 * Shared helper functions used across the application.
 * Loaded once via bootstrap.php to avoid redeclaration.
 */

function format_size(int $bytes): string {
    if ($bytes <= 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($bytes) / log($k));
    $i = max(0, min($i, count($sizes) - 1));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function format_file_size(int $bytes): string {
    return format_size($bytes);
}

function format_speed(float $bytesPerSecond): string {
    if ($bytesPerSecond <= 0) return '0 MB/s';
    return format_size((int) $bytesPerSecond) . '/s';
}

function format_eta(int $seconds): string {
    if ($seconds <= 0) return '--:--';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

function is_process_running(int $pid): bool {
    if ($pid <= 0) return false;

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    $output = [];
    @exec('ps -p ' . $pid . ' > /dev/null 2>&1; echo $?', $output);
    return trim((string) ($output[0] ?? '1')) === '0';
}
