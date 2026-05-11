<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$tempRoot    = rtrim(TEMP_ROOT, '/');
$filesDir    = rtrim(TEMP_FILES, '/');
$progressDir = rtrim(TEMP_PROGRESS, '/');
$lockPath    = $progressDir . '/.cleanup.lock';

$defaults = [
    'files_age' => 15 * 60,
    'progress_age' => 2 * 3600,
    'max_files' => 200,
    'active_log_grace' => 300,
];

$options = getopt('', ['files-age::', 'progress-age::', 'max-files::', 'quiet::']);
$filesAge    = parse_int_option($options['files-age'] ?? null, $defaults['files_age'], 60);
$progressAge = parse_int_option($options['progress-age'] ?? null, $defaults['progress_age'], 60);
$maxFiles    = parse_int_option($options['max-files'] ?? null, $defaults['max_files'], 1);
$quiet       = array_key_exists('quiet', $options);

foreach ([$tempRoot, $filesDir, $progressDir] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        cleanup_error('failed to create directory: ' . $dir);
        exit(1);
    }
}

if (!is_writable($filesDir) || !is_writable($progressDir)) {
    cleanup_error(
        "permission denied: temp directories are not writable for this user.\n" .
        "Run cleanup as the same user as your web server (usually www-data), or fix directory permissions.\n" .
        "filesDir={$filesDir}\nprogressDir={$progressDir}\nuser=" . current_process_user()
    );
    exit(1);
}

$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false) {
    cleanup_error('unable to open cleanup lock file: ' . $lockPath);
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    cleanup_log('Skip cleanup: another cleanup process is running.', $quiet);
    fclose($lockHandle);
    exit(0);
}

$activeJobs = detect_active_jobs($progressDir, $defaults['active_log_grace']);

$stats = [
    'removed_files' => 0,
    'removed_progress' => 0,
    'skipped_active' => 0,
];

$stats['removed_files'] += cleanup_download_files($filesDir, $filesAge, $activeJobs, $stats);
$stats['removed_files'] += enforce_max_files($filesDir, $maxFiles, $activeJobs, $stats);
$stats['removed_progress'] += cleanup_progress_files($progressDir, $progressAge, $activeJobs, $stats);

cleanup_log(
    sprintf(
        'Cleanup done: removed_files=%d, removed_progress=%d, active_jobs=%d, skipped_active=%d',
        $stats['removed_files'],
        $stats['removed_progress'],
        count($activeJobs),
        $stats['skipped_active']
    ),
    $quiet
);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);

function parse_int_option(mixed $value, int $default, int $min): int
{
    if ($value === null || $value === false || $value === '') return $default;
    if (!is_numeric($value)) return $default;

    $intValue = (int) $value;
    if ($intValue < $min) return $default;

    return $intValue;
}

function cleanup_log(string $message, bool $quiet): void
{
    if ($quiet) return;
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function cleanup_error(string $message): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . PHP_EOL);
}

function current_process_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $euid = @posix_geteuid();
        if (is_int($euid)) {
            $pw = @posix_getpwuid($euid);
            if (is_array($pw) && isset($pw['name']) && is_string($pw['name']) && $pw['name'] !== '') {
                return $pw['name'];
            }
            return (string) $euid;
        }
    }
    $user = getenv('USER');
    if (is_string($user) && $user !== '') return $user;
    return get_current_user();
}

function list_files(string $dir): array
{
    if (!is_dir($dir)) return [];
    $matches = glob(rtrim($dir, '/') . '/*');
    if ($matches === false) return [];
    return array_values(array_filter($matches, 'is_file'));
}

function detect_active_jobs(string $progressDir, int $activeLogGrace): array
{
    $active = [];

    foreach (glob(rtrim($progressDir, '/') . '/*.pid') ?: [] as $pidFile) {
        $jobId = basename($pidFile, '.pid');
        $pidRaw = trim((string) @file_get_contents($pidFile));
        if ($pidRaw !== '' && ctype_digit($pidRaw) && is_process_running((int) $pidRaw)) {
            $active[$jobId] = true;
        }
    }
    
    foreach (glob(rtrim($progressDir, '/') . '/*.log') ?: [] as $logFile) {
        $jobId = basename($logFile, '.log');
        $mtime = @filemtime($logFile);
        if ($mtime !== false && (time() - $mtime) <= $activeLogGrace) {
            $active[$jobId] = true;
        }
    }

    return array_keys($active);
}

// is_process_running() is now provided by app/helpers.php

function is_temporary_download_file(string $basename): bool
{
    return str_ends_with($basename, '.part')
        || str_ends_with($basename, '.ytdl')
        || str_ends_with($basename, '.temp');
}

function file_belongs_to_active_job(string $basename, array $activeJobs): bool
{
    foreach ($activeJobs as $jobId) {
        if ($basename === $jobId || str_starts_with($basename, $jobId . '.')) {
            return true;
        }
        if (str_starts_with($basename, $jobId . '_')) {
            return true;
        }
    }
    return false;
}

function cleanup_download_files(string $filesDir, int $maxAge, array $activeJobs, array &$stats): int
{
    $removed = 0;
    $now = time();

    foreach (list_files($filesDir) as $file) {
        $basename = basename($file);

        if (file_belongs_to_active_job($basename, $activeJobs)) {
            $stats['skipped_active']++;
            continue;
        }

        $mtime = @filemtime($file);
        if ($mtime === false) continue;

        if (($now - $mtime) > $maxAge && @unlink($file)) {
            $removed++;
        }
    }
    return $removed;
}

function enforce_max_files(string $filesDir, int $maxFiles, array $activeJobs, array &$stats): int
{
    $candidates = [];
    foreach (list_files($filesDir) as $file) {
        $basename = basename($file);
        if (is_temporary_download_file($basename) || file_belongs_to_active_job($basename, $activeJobs)) {
            continue;
        }
        $candidates[] = $file;
    }

    if (count($candidates) <= $maxFiles) return 0;

    usort(
        $candidates,
        static fn(string $a, string $b): int => ((int) (@filemtime($a) ?: 0)) <=> ((int) (@filemtime($b) ?: 0))
    );

    $toDelete = array_slice($candidates, 0, count($candidates) - $maxFiles);
    $removed = 0;
    foreach ($toDelete as $file) {
        if (@unlink($file)) $removed++;
    }

    return $removed;
}

function cleanup_progress_files(string $progressDir, int $maxAge, array $activeJobs, array &$stats): int
{
    $removed = 0;
    $now = time();
    $extensions = ['pid', 'log', 'json', 'sh'];

    foreach ($extensions as $ext) {
        foreach (glob(rtrim($progressDir, '/') . '/*.' . $ext) ?: [] as $file) {
            $basename = basename($file);
            $jobId = preg_replace('/\.(pid|log|json|sh)$/', '', $basename);
            if (!is_string($jobId) || $jobId === '') continue;

            if (in_array($jobId, $activeJobs, true)) {
                $stats['skipped_active']++;
                continue;
            }

            $mtime = @filemtime($file);
            if ($mtime === false) continue;

            if (($now - $mtime) > $maxAge && @unlink($file)) {
                $removed++;
            }
        }
    }

    return $removed;
}
