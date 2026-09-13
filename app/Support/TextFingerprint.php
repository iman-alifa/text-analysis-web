<?php

namespace App\Support;

/**
 * Sidik jari teks untuk mendeteksi baris yang sama.
 *
 * Dipakai bersama golden dataset dan data latih. Wajib satu implementasi:
 * kalau kedua sisi menormalkan dengan cara berbeda, pemeriksaan kebocoran
 * akan melewatkan pasangan yang sebenarnya identik - dan kebocoran yang
 * lolos justru membuat angka evaluasi terlihat lebih baik.
 */
class TextFingerprint
{
    /**
     * Normalisasi ringan saja: huruf kecil, tanda baca dibuang, spasi
     * dirapikan. Sengaja tidak memakai stemming atau stopword removal -
     * dua kalimat berbeda bisa punya bentuk terstem yang sama, dan
     * menganggapnya duplikat akan membuang baris uji yang sah.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function hash(string $text): string
    {
        return hash('sha256', self::normalize($text));
    }
}
