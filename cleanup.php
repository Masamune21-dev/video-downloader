<?php

$dir = __DIR__ . '/temp/files/';
$maxAge = 3600;   // 1 jam
$maxFiles = 10;   // maksimal 20 file

// Ambil semua file (bukan folder)
$files = array_filter(glob($dir . '*.*'), 'is_file');

/* ===============================
   RULE 1: FILE TERLALU BANYAK
   =============================== */
if (count($files) > $maxFiles) {

    foreach ($files as $file) {

        // Jangan hapus file sementara
        if (str_ends_with($file, '.part')) continue;

        @unlink($file);
    }

    exit; // STOP, tidak perlu cek umur
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
