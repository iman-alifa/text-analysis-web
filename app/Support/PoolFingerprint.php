<?php

namespace App\Support;

/**
 * Sidik jari isi kolam pelatihan: 16 heksadesimal dari SHA-256 atas teks
 * yang diurutkan.
 *
 * Salinan persis `pool_fingerprint()` di `../nlp-api-service/app/utils/training.py`.
 * Harus persis, karena gunanya justru membandingkan angka dari kedua sisi:
 * yang dicatat Laravel saat mengirim, dan yang ditulis layanan ke
 * `provenance.json` di sebelah checkpoint. Kalau algoritmanya berbeda sedikit
 * saja, keduanya tidak akan pernah cocok dan perbandingannya kehilangan arti -
 * kelas kesalahan yang sama dengan `TextFingerprint` pada penjaga kebocoran.
 *
 * Tiga hal yang membuat kedua sisi menghasilkan angka yang sama:
 *
 * 1. **Diurutkan**, sehingga urutan pengambilan dari basis data tidak
 *    berpengaruh - yang diidentifikasi adalah ISI kolam, bukan urutannya.
 *    `sort()` PHP dengan SORT_STRING membandingkan byte, dan `sorted()` Python
 *    membandingkan titik kode; untuk UTF-8 keduanya menghasilkan urutan yang
 *    sama karena urutan byte UTF-8 mengikuti urutan titik kodenya.
 * 2. **Pemisah `\x00`** setelah tiap teks, supaya ["ab","c"] dan ["a","bc"]
 *    tidak bertabrakan.
 * 3. **Dipotong 16 heksadesimal**, bukan 64.
 *
 * Kenapa ini ada: kontaminasi checkpoint tidak terlihat dari berkas bobot, dari
 * ukurannya, maupun dari `/health` - ketiganya tampak normal. Pada studi kasus
 * ini dua pelatihan verifikasi dengan kalimat sintetis tertulis ke
 * `aspect_retrained.pt` dan baru ketahuan dari log berjam-jam kemudian, setelah
 * satu iterasi terlanjur dibangun di atasnya.
 */
class PoolFingerprint
{
    /**
     * @param  array<int, string>  $texts
     */
    public static function of(array $texts): string
    {
        $teks = array_map(static fn ($t) => (string) $t, array_values($texts));

        sort($teks, SORT_STRING);

        $hash = hash_init('sha256');

        foreach ($teks as $satu) {
            hash_update($hash, $satu);
            hash_update($hash, "\0");
        }

        return substr(hash_final($hash), 0, 16);
    }

    /**
     * Sidik jari dari muatan pelatihan apa adanya - baik bentuk
     * `[{text, label}]` (sentimen) maupun `[{text, aspects}]` (aspek).
     *
     * Layanan menghitungnya atas `[d['text'] for d in training_data]`, jadi
     * yang diambil di sini juga hanya kolom `text`.
     *
     * @param  array<int, array<string, mixed>>  $payload
     */
    public static function ofPayload(array $payload): string
    {
        return self::of(array_column($payload, 'text'));
    }
}
