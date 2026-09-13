<?php

namespace App\Support;

/**
 * Apakah sebuah teks bisa dinilai sama sekali.
 *
 * Satu definisi, dipakai tiga tempat: pembuatan baris koreksi, pengambilan
 * sampel golden dataset, dan perintah pembersihan. Kalau ketiganya punya
 * definisi sendiri-sendiri, baris yang disembunyikan di satu tempat akan muncul
 * di tempat lain - persis yang terjadi ketika penyaringan hanya dipasang di
 * halaman koreksi.
 *
 * Dua penanda, dan keduanya perlu:
 *
 * 1. `method === 'empty'` dari NLP API. Ini yang paling berwenang: layanan
 *    memang tidak mengumpankan teks kosong ke IndoBERT, karena model
 *    mengklasifikasi `[CLS][SEP]` sebagai positif pada keyakinan 0,94 dan itu
 *    langsung menaikkan persentase positif di dasbor.
 * 2. Teks tanpa satu pun huruf atau angka. Ini cadangan untuk baris yang
 *    tersimpan sebelum `method` ikut disimpan, dan untuk jalur yang memang
 *    tidak punya akses ke `method` sama sekali - pengambilan sampel golden
 *    membaca `raw_data`, bukan prediksi.
 *
 * Penyaringan panjang minimal saja tidak cukup: "❤️❤️👍👍👍❤️❤️👍👍👍" lewat
 * dari ambang 20 karakter tetapi tetap tidak ada isinya.
 */
class TextScorability
{
    public static function isUnscorable(?string $text, ?string $method = null): bool
    {
        if ($method === 'empty') {
            return true;
        }

        $text = trim((string) $text);

        if ($text === '') {
            return true;
        }

        // \p{L} huruf apa pun, \p{N} angka apa pun. Emotikon, tanda baca, dan
        // spasi tidak termasuk keduanya.
        return ! preg_match('/[\p{L}\p{N}]/u', $text);
    }

    public static function isScorable(?string $text, ?string $method = null): bool
    {
        return ! self::isUnscorable($text, $method);
    }
}
