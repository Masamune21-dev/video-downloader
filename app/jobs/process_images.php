<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$jobId = trim((string) ($argv[1] ?? ''));
if ($jobId === '' || !preg_match('/^vd_[a-z0-9]+(?:\.[a-z0-9]+)?$/i', $jobId)) {
    fwrite(STDERR, "ERROR: Job ID tidak valid\n");
    exit(2);
}

$metadataFile = TEMP_PROGRESS . $jobId . '.json';
$metadata = [];
if (is_file($metadataFile)) {
    $decoded = json_decode((string) file_get_contents($metadataFile), true);
    if (is_array($decoded)) $metadata = $decoded;
}

$rawUrl = trim((string) ($metadata['url'] ?? ''));
$downloadUrl = trim((string) ($metadata['download_url'] ?? ''));
if ($downloadUrl === '') $downloadUrl = normalize_tiktok_photo_url_for_ytdlp($rawUrl);
$urlParts = parse_url($rawUrl);
if (!$urlParts || empty($urlParts['scheme']) || !in_array(strtolower((string) $urlParts['scheme']), ['http', 'https'], true)) {
    fwrite(STDERR, "ERROR: URL tidak valid\n");
    exit(2);
}

$title = sanitize_image_job_filename((string) ($metadata['title'] ?? ''));
if ($title === '') $title = 'images';

$selectedImageIndexes = image_indexes_from_metadata($metadata);
$imageFiles = [];

// Without curl the TikTok scraper below is skipped and we quietly fall back to
// yt-dlp's cover thumbnail — the user asks for a 4-image slideshow and gets one
// duplicated cover. Fail loudly instead of handing back the wrong pictures.
if (is_tiktok_source($rawUrl) && !function_exists('curl_init')) {
    fwrite(STDERR, 'ERROR: Ekstensi PHP curl tidak aktif di PHP CLI ' . PHP_VERSION . "\n");
    exit(1);
}

if (is_tiktok_source($rawUrl)) {
    emit_image_progress('downloading', 5, 'Mencari gambar TikTok...');
    $imageGroups = extract_tiktok_image_groups($rawUrl);
    if (empty($imageGroups) && $downloadUrl !== $rawUrl) {
        $imageGroups = extract_tiktok_image_groups($downloadUrl);
    }
    if (!empty($imageGroups)) {
        emit_image_progress('downloading', 15, 'Mendownload gambar TikTok...');
        $imageFiles = download_selected_tiktok_image_groups($imageGroups, $selectedImageIndexes, $jobId, $title, $rawUrl);
    }
}

if (empty($imageFiles)) {
    emit_image_progress('downloading', 5, 'Mendownload gambar...');

    $outputTpl = TEMP_FILES . $jobId . '_%(title).120s_%(id)s_%(thumbnail_id)s.%(ext)s';
    $cmdParts = [
        ytdlp_bin(),
        ...ytdlp_base_args(),
    ];

    if (defined('YTDLP_NO_PLAYLIST') && YTDLP_NO_PLAYLIST) {
        $cmdParts[] = '--no-playlist';
    }

    $cmdParts[] = '--no-warnings';
    $cmdParts[] = '--skip-download';
    $cmdParts[] = '--write-all-thumbnails';
    $cmdParts[] = '--restrict-filenames';

    append_image_ytdlp_tuning($cmdParts);

    $cmdParts[] = '-o';
    $cmdParts[] = $outputTpl;
    $cmdParts[] = $downloadUrl;

    $metadata['image_command'] = $cmdParts;
    $metadata['image_output_tpl'] = $outputTpl;
    $metadata['last_update'] = time();
    @file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));

    $exitCode = 0;
    passthru(shell_join($cmdParts) . ' 2>&1', $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "ERROR: Gagal mendownload gambar dari sumber\n");
        exit($exitCode);
    }

    $imageFiles = keep_selected_image_files(find_downloaded_image_files($jobId), $selectedImageIndexes, $jobId, $title);
}

if (empty($imageFiles)) {
    fwrite(STDERR, "ERROR: Tidak ada gambar yang ditemukan untuk URL ini\n");
    exit(1);
}

if (count($imageFiles) > 1) {
    if (!class_exists('ZipArchive')) {
        fwrite(STDERR, "ERROR: Ekstensi PHP zip belum aktif di server\n");
        exit(1);
    }

    emit_image_progress('processing', 95, 'Mengemas gambar pilihan...');

    $zipPath = TEMP_FILES . $jobId . '_' . $title . '_selected_images.zip';
    if (!create_image_zip($zipPath, $imageFiles)) {
        fwrite(STDERR, "ERROR: Gagal membuat ZIP gambar\n");
        exit(1);
    }

    foreach ($imageFiles as $imageFile) {
        @unlink($imageFile);
    }

    $imageFiles = [$zipPath];
}

clearstatcache(true, $imageFiles[0]);
if (!is_file($imageFiles[0]) || (int) (@filesize($imageFiles[0]) ?: 0) <= 0) {
    fwrite(STDERR, "ERROR: File gambar tidak valid\n");
    exit(1);
}

emit_image_progress('finished', 100, 'Download gambar selesai!');
exit(0);

function emit_image_progress(string $status, float $percent, string $message): void {
    echo 'download:[vd-progress] ' . json_encode([
        'status' => $status,
        'percent' => $percent,
        'downloaded' => 0,
        'total' => 0,
        'total_est' => 0,
        'speed' => 0,
        'eta' => 0,
        'message' => $message,
    ]) . PHP_EOL;
    flush();
}

function append_image_ytdlp_tuning(array &$cmdParts): void {
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
}

function image_indexes_from_metadata(array $metadata): array {
    $indexes = [];

    if (isset($metadata['image_indexes']) && is_array($metadata['image_indexes'])) {
        foreach ($metadata['image_indexes'] as $index) {
            if (!is_numeric($index)) continue;
            $indexes[] = max(0, (int) $index);
        }
    } elseif (isset($metadata['image_index']) && is_numeric($metadata['image_index'])) {
        $indexes[] = max(0, (int) $metadata['image_index']);
    }

    if (empty($indexes)) $indexes[] = 0;

    $indexes = array_values(array_unique($indexes));
    sort($indexes, SORT_NUMERIC);

    return array_slice($indexes, 0, 50);
}

function is_tiktok_source(string $url): bool {
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    return str_contains($host, 'tiktok.com') || str_contains($host, 'tiktokcdn.com');
}

function extract_tiktok_image_groups(string $url): array {
    if (!function_exists('curl_init')) return [];

    $html = fetch_tiktok_page($url);
    if ($html === '') return [];

    $jsonBlobs = [];
    foreach ([
        '__UNIVERSAL_DATA_FOR_REHYDRATION__',
        'SIGI_STATE',
        '__NEXT_DATA__',
        'api-data', // where the mobile page currently carries imagePost
    ] as $scriptId) {
        if (preg_match('~<script[^>]+id=["\']' . preg_quote($scriptId, '~') . '["\'][^>]*>(.*?)</script>~is', $html, $match)) {
            $jsonBlobs[] = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    if (preg_match_all('~"imagePost"\s*:\s*\{~', $html, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $object = extract_json_object_at($html, (int) $match[1] + strpos($match[0], '{'));
            if ($object !== '') $jsonBlobs[] = '{"imagePost":' . $object . '}';
        }
    }

    $groups = [];
    foreach ($jsonBlobs as $blob) {
        $decoded = json_decode($blob, true);
        if (is_array($decoded)) {
            $groups = array_merge($groups, collect_tiktok_image_groups($decoded));
        }
    }

    return dedupe_tiktok_image_groups($groups);
}

function fetch_tiktok_page(string $url): string {
    $ch = curl_init($url);
    if ($ch === false) return '';

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => tiktok_browser_user_agent(),
        CURLOPT_REFERER => 'https://www.tiktok.com/',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);

    if (is_string(YTDLP_COOKIES_FILE) && YTDLP_COOKIES_FILE !== '' && is_file(YTDLP_COOKIES_FILE)) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, YTDLP_COOKIES_FILE);
    }

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return is_string($body) && $httpCode >= 200 && $httpCode < 400 ? $body : '';
}

function collect_tiktok_image_groups($node): array {
    if (!is_array($node)) return [];

    $groups = [];

    if (isset($node['imagePost']) && is_array($node['imagePost'])) {
        $groups = array_merge($groups, image_groups_from_web_image_post($node['imagePost']));
    }
    if (isset($node['image_post_info']) && is_array($node['image_post_info'])) {
        $groups = array_merge($groups, image_groups_from_app_image_post($node['image_post_info']));
    }

    foreach ($node as $child) {
        if (is_array($child)) {
            $groups = array_merge($groups, collect_tiktok_image_groups($child));
        }
    }

    return $groups;
}

function image_groups_from_web_image_post(array $imagePost): array {
    $groups = [];
    $images = $imagePost['images'] ?? [];
    if (!is_array($images)) return [];

    foreach ($images as $image) {
        if (!is_array($image)) continue;

        $urls = [];
        $urls = array_merge($urls, url_list_from_path($image, ['imageURL', 'urlList']));
        $urls = array_merge($urls, url_list_from_path($image, ['image_url', 'url_list']));
        $urls = array_merge($urls, url_list_from_path($image, ['display_image', 'url_list']));
        $urls = array_merge($urls, url_list_from_path($image, ['urlList']));
        $urls = array_merge($urls, url_list_from_path($image, ['url_list']));

        $urls = valid_image_urls($urls);
        if (!empty($urls)) $groups[] = $urls;
    }

    return $groups;
}

function image_groups_from_app_image_post(array $imagePostInfo): array {
    $groups = [];
    $images = $imagePostInfo['images'] ?? [];
    if (!is_array($images)) return [];

    foreach ($images as $image) {
        if (!is_array($image)) continue;

        $urls = [];
        $urls = array_merge($urls, url_list_from_path($image, ['display_image', 'url_list']));
        $urls = array_merge($urls, url_list_from_path($image, ['imageURL', 'urlList']));
        $urls = array_merge($urls, url_list_from_path($image, ['image_url', 'url_list']));
        $urls = array_merge($urls, url_list_from_path($image, ['url_list']));

        $urls = valid_image_urls($urls);
        if (!empty($urls)) $groups[] = $urls;
    }

    return $groups;
}

function url_list_from_path(array $node, array $path): array {
    $value = $node;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) return [];
        $value = $value[$segment];
    }

    if (is_string($value)) return [$value];
    return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
}

function valid_image_urls(array $urls): array {
    $valid = [];
    foreach ($urls as $url) {
        if (!is_string($url)) continue;
        if (str_starts_with($url, '//')) $url = 'https:' . $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
        if (!preg_match('~\.(?:jpe?g|png|webp|gif|avif)(?:[?&#]|$)|/tos-[^?]+/|photomode-image~i', $url)) continue;
        $valid[] = $url;
    }
    return array_values(array_unique($valid));
}

function dedupe_tiktok_image_groups(array $groups): array {
    $seen = [];
    $deduped = [];

    foreach ($groups as $group) {
        if (!is_array($group) || empty($group)) continue;
        $key = tiktok_image_identity((string) $group[0]);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $deduped[] = array_values(array_unique($group));
    }

    return $deduped;
}

function tiktok_image_identity(string $url): string {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    if ($path === '') return '';
    return preg_replace('~/+~', '/', $path) ?: $path;
}

function extract_json_object_at(string $text, int $start): string {
    $length = strlen($text);
    if ($start < 0 || $start >= $length || $text[$start] !== '{') return '';

    $depth = 0;
    $inString = false;
    $escape = false;

    for ($i = $start; $i < $length; $i++) {
        $char = $text[$i];
        if ($inString) {
            if ($escape) {
                $escape = false;
            } elseif ($char === '\\') {
                $escape = true;
            } elseif ($char === '"') {
                $inString = false;
            }
            continue;
        }

        if ($char === '"') {
            $inString = true;
        } elseif ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) return substr($text, $start, $i - $start + 1);
        }
    }

    return '';
}

function download_selected_tiktok_image_groups(array $groups, array $selectedIndexes, string $jobId, string $title, string $referer): array {
    $files = [];

    foreach ($selectedIndexes as $selectedIndex) {
        if (!isset($groups[$selectedIndex])) {
            fwrite(STDERR, "ERROR: Gambar yang dipilih tidak ditemukan\n");
            exit(1);
        }

        $targetBase = TEMP_FILES . $jobId . '_' . $title . '_image_' . str_pad((string) ($selectedIndex + 1), 3, '0', STR_PAD_LEFT);
        $downloaded = download_first_available_image((array) $groups[$selectedIndex], $targetBase, $referer);
        if ($downloaded !== null) $files[] = $downloaded;
    }

    return $files;
}

function download_first_available_image(array $urls, string $targetBase, string $referer): ?string {
    foreach ($urls as $url) {
        $downloaded = download_remote_image((string) $url, $targetBase, $referer);
        if ($downloaded !== null) return $downloaded;
    }
    return null;
}

function download_remote_image(string $url, string $targetBase, string $referer): ?string {
    if (!function_exists('curl_init')) return null;

    $tmpPath = $targetBase . '.tmp';
    $fp = @fopen($tmpPath, 'wb');
    if ($fp === false) return null;

    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fp);
        @unlink($tmpPath);
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => image_download_user_agent(),
        CURLOPT_REFERER => $referer,
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'],
    ]);

    if (is_string(YTDLP_COOKIES_FILE) && YTDLP_COOKIES_FILE !== '' && is_file(YTDLP_COOKIES_FILE)) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, YTDLP_COOKIES_FILE);
    }

    $ok = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fp);

    clearstatcache(true, $tmpPath);
    if (!$ok || $httpCode < 200 || $httpCode >= 400 || !is_file($tmpPath) || (int) (@filesize($tmpPath) ?: 0) <= 0) {
        @unlink($tmpPath);
        return null;
    }

    $ext = image_extension_from_content_type($contentType) ?: image_extension_from_url($url);
    $targetPath = $targetBase . '.' . ($ext ?: 'jpg');
    if (@rename($tmpPath, $targetPath)) return $targetPath;

    @unlink($tmpPath);
    return null;
}

function image_download_user_agent(): string {
    if (is_string(YTDLP_USER_AGENT) && YTDLP_USER_AGENT !== '') {
        return YTDLP_USER_AGENT;
    }
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
}

function image_extension_from_content_type(string $contentType): string {
    $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
    return match ($contentType) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        default => '',
    };
}

function image_extension_from_url(string $url): string {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : '';
}

function find_downloaded_image_files(string $jobId): array {
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif'];
    $files = [];

    foreach (glob(TEMP_FILES . $jobId . '_*') ?: [] as $file) {
        if (!is_file($file)) continue;

        $base = basename($file);
        if (str_contains($base, '.part') || str_contains($base, '.ytdl') || str_contains($base, '.temp')) continue;

        $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) continue;

        $files[] = $file;
    }

    natsort($files);
    return array_values($files);
}

function keep_selected_image_files(array $imageFiles, array $selectedIndexes, string $jobId, string $title): array {
    if (empty($imageFiles)) return [];

    $selectedFiles = [];
    foreach ($selectedIndexes as $selectedIndex) {
        if (!isset($imageFiles[$selectedIndex])) continue;

        $selected = $imageFiles[$selectedIndex];
        $ext = strtolower((string) pathinfo($selected, PATHINFO_EXTENSION));
        $target = TEMP_FILES . $jobId . '_' . $title . '_image_' . str_pad((string) ($selectedIndex + 1), 3, '0', STR_PAD_LEFT) . '.' . ($ext ?: 'jpg');

        if ($selected !== $target && !file_exists($target)) {
            @rename($selected, $target);
            $selected = $target;
        }

        $selectedFiles[] = $selected;
    }

    if (empty($selectedFiles)) $selectedFiles[] = $imageFiles[0];

    $keep = array_fill_keys($selectedFiles, true);
    foreach ($imageFiles as $file) {
        if (!isset($keep[$file])) @unlink($file);
    }

    return array_values($selectedFiles);
}

function create_image_zip(string $zipPath, array $imageFiles): bool {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    foreach ($imageFiles as $file) {
        $zip->addFile($file, basename($file));
    }

    return $zip->close();
}

function sanitize_image_job_filename(string $title): string {
    $title = trim($title);
    if ($title === '') return '';

    $title = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $title);
    $title = trim($title, "._- \t\n\r\0\x0B");
    if (strlen($title) > 100) $title = substr($title, 0, 100);
    return rtrim($title, '._-');
}
