<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Berkas hasil YouTube Scraper yang boleh dijangkau dari luar.
 *
 * Satu tempat untuk menjawab "nama berkas ini sah atau tidak", dipakai bersama
 * oleh tombol unduh dan oleh jalur "langsung analisis". Nama berkasnya datang
 * dari URL atau dari formulir, jadi penjagaannya berlapis:
 *
 * 1. `basename()` membuang komponen path apa pun, sehingga '..%2F..%2F.env'
 *    menjadi sekadar '.env'.
 * 2. Polanya dibatasi pada bentuk yang memang dihasilkan `exportComments()`.
 * 3. `realpath()` yang sudah diselesaikan wajib berada di dalam direktori
 *    exports, menutup jalur lewat symlink.
 *
 * Disatukan di sini, bukan disalin ke pemanggil kedua: dua salinan penjagaan
 * path pasti berselisih, dan yang berselisih di sini berarti lubang baca berkas.
 */
class ScraperExport
{
    public static function directory(): string
    {
        return storage_path('app/public/exports');
    }

    /**
     * Path absolut berkas ekspor, atau null bila namanya tidak sah / tidak ada.
     */
    public static function path(string $filename): ?string
    {
        $direktori = self::directory();
        $filename = basename($filename);

        if (! preg_match('/^[A-Za-z0-9._-]+\.(csv|xlsx|txt)$/', $filename)) {
            return null;
        }

        $realPath = realpath($direktori.DIRECTORY_SEPARATOR.$filename);
        $realDir = realpath($direktori);

        if ($realPath === false || $realDir === false) {
            return null;
        }

        if (! str_starts_with($realPath, $realDir.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realPath;
    }

    public static function exists(string $filename): bool
    {
        return self::path($filename) !== null;
    }

    /**
     * Berkas ekspor dibungkus sebagai UploadedFile.
     *
     * Seluruh jalur unggah (`FileProcessingService`, `prepareFileConfig`)
     * menerima `UploadedFile`, jadi membungkusnya di sini membuat berkas hasil
     * scraping melewati pemrosesan yang **persis sama** dengan berkas yang
     * diunggah manual. Menyalin logika pemrosesan ke jalur kedua akan membuat
     * kedua jalur berselisih pada berkas yang isinya sama.
     *
     * Argumen `$test = true` menandai berkas ini tidak berasal dari unggahan
     * HTTP, sehingga `is_uploaded_file()` tidak dipaksakan.
     */
    public static function asUploadedFile(string $filename): ?UploadedFile
    {
        $path = self::path($filename);

        if ($path === null) {
            return null;
        }

        return new UploadedFile($path, basename($path), null, null, true);
    }
}
