<?php

$directories = [
    __DIR__ . '/temp/files/',
    __DIR__ . '/temp/progress/'
];

$maxAge   = 3600; // 1 jam
$maxFiles = 10;   // maksimal 10 file

foreach ($directories as $dir) {

    if (!is_dir($dir)) continue;

    // Ambil semua file (bukan folder)
    $files = array_filter(glob($dir . '*'), 'is_file');

    /* ===============================
       RULE 1: FILE TERLALU BANYAK
       =============================== */
    if (count($files) > $maxFiles) {
        foreach ($files as $file) {

            // Jangan hapus file sementara (.part)
            if (str_ends_with($file, '.part')) continue;

            @unlink($file);
        }

        // lanjut ke folder berikutnya
        continue;
    }

    /* ===============================
       RULE 2: FILE TERLALU LAMA
       =============================== */
    foreach ($files as $file) {

        if (str_ends_with($file, '.part')) continue;

        if (time() - filemtime($file) > $maxAge) {
            @unlink($file);
        }
    }
}
