<?php

namespace App\Helpers;

/**
 * Menyiapkan angka dan istilah pada halaman hasil untuk ditampilkan.
 *
 * Dibuat setelah menemukan bahwa kartu metrik menebak format dari tipe data
 * PHP: nilai float dikalikan 100 lalu diberi tanda persen. Akibatnya
 * `negative_percentage = 88.58` - yang sudah berupa persen - tampil sebagai
 * "8858%" pada analisis 69 dan 72, sementara pada analisis 95 nilainya
 * kebetulan tersimpan sebagai integer 60 sehingga tampil "60" tanpa persen.
 * Metrik yang sama tampil dengan dua cara berbeda hanya karena JSON-nya
 * menyimpan int atau float.
 *
 * Karena itu format ditentukan per nama metrik, bukan ditebak dari tipenya,
 * dan metrik yang tidak dikenal ditampilkan apa adanya - lebih baik terlihat
 * mentah daripada terlihat rapi tetapi salah.
 */
class ResultPresenter
{
    /**
     * Metrik yang layak tampil sebagai kartu besar.
     *
     * 'ratio' = pecahan 0-1 yang perlu dikali 100; 'count' = jumlah baris.
     */
    private const KARTU = [
        'total_texts' => ['label' => 'Teks Dianalisis', 'format' => 'count'],
        'avg_confidence' => ['label' => 'Rata-rata Keyakinan', 'format' => 'ratio'],
        'min_confidence' => ['label' => 'Keyakinan Terendah', 'format' => 'ratio'],
        'max_confidence' => ['label' => 'Keyakinan Tertinggi', 'format' => 'ratio'],
    ];

    /**
     * Metrik yang benar tetapi bukan untuk konsumsi umum: catatan internal,
     * atau angka yang sudah ditampilkan lebih baik di panel lain.
     *
     * 'persen' = nilai yang SUDAH berupa persen, jangan dikali 100 lagi.
     */
    private const TEKNIS = [
        'total_analyzed' => ['label' => 'Baris terproses', 'format' => 'count'],
        'pipeline_version' => ['label' => 'Versi pipeline', 'format' => 'raw'],
        'positive_percentage' => ['label' => 'Persentase positif', 'format' => 'persen'],
        'neutral_percentage' => ['label' => 'Persentase netral', 'format' => 'persen'],
        'negative_percentage' => ['label' => 'Persentase negatif', 'format' => 'persen'],
        'total_empty' => ['label' => 'Baris kosong', 'format' => 'count'],
        'total_truncated' => ['label' => 'Teks terpotong', 'format' => 'count'],
        'total_failed' => ['label' => 'Baris gagal dinilai', 'format' => 'count'],
    ];

    /**
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public static function metricCards(?array $metrics): array
    {
        return self::ambil($metrics, self::KARTU);
    }

    /**
     * Sisa metrik skalar, termasuk yang tidak dikenal. Ditampilkan di blok
     * "detail teknis" yang bisa dilipat, bukan dibuang: menyembunyikan angka
     * dari pemilik data bukan keputusan yang boleh diambil di lapisan tampilan.
     *
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public static function technicalMetrics(?array $metrics): array
    {
        $dikenal = self::ambil($metrics, self::TEKNIS);

        $sudahTampil = array_merge(array_keys(self::KARTU), array_keys(self::TEKNIS));
        $sisa = [];

        foreach ($metrics ?? [] as $key => $value) {
            // Nilai bersarang (review_queue, failed_batches) punya tampilannya
            // sendiri; mencetaknya lewat {{ }} dulu membuat halaman 500.
            if (! is_scalar($value) || in_array($key, $sudahTampil, true)) {
                continue;
            }

            $sisa[] = [
                'key' => $key,
                'label' => ucfirst(str_replace('_', ' ', $key)),
                'value' => (string) $value,
            ];
        }

        return array_merge($dikenal, $sisa);
    }

    public static function sentimentLabel(string $sentiment): string
    {
        return match (strtolower($sentiment)) {
            'positive', 'positif' => 'Positif',
            'negative', 'negatif' => 'Negatif',
            'neutral', 'netral' => 'Netral',
            default => ucfirst($sentiment),
        };
    }

    public static function analysisTypeLabel(string $type): string
    {
        return match (strtolower($type)) {
            'sentiment' => 'Analisis Sentimen',
            'aspect' => 'Analisis Aspek',
            'topic' => 'Topic Modeling',
            'combined' => 'Analisis Gabungan',
            default => ucfirst($type),
        };
    }

    /**
     * @param  array<string, array{label: string, format: string}>  $daftar
     * @return array<int, array{key: string, label: string, value: string}>
     */
    private static function ambil(?array $metrics, array $daftar): array
    {
        $hasil = [];

        foreach ($daftar as $key => $meta) {
            if (! isset($metrics[$key]) || ! is_scalar($metrics[$key])) {
                continue;
            }

            $hasil[] = [
                'key' => $key,
                'label' => $meta['label'],
                'value' => self::format($metrics[$key], $meta['format']),
            ];
        }

        return $hasil;
    }

    private static function format(mixed $value, string $format): string
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }

        return match ($format) {
            'ratio' => number_format((float) $value * 100, 1).'%',
            'persen' => number_format((float) $value, 1).'%',
            'count' => number_format((float) $value),
            default => (string) $value,
        };
    }

    /**
     * Rambu penafsiran koherensi topik (c_v).
     *
     * Satu-satunya tempat ambang ini ditulis. Sebelumnya ada dua salinan -
     * di ModelEvaluationService dan di show.blade.php - dan halaman evaluasi
     * akan menjadi salinan ketiga. Angka yang sama dilabeli berbeda di dua
     * halaman persis masalah yang dulu ditemukan pada koherensi topik.
     *
     * Ambangnya disetel untuk teks pendek dan tidak baku seperti komentar,
     * bukan untuk artikel: c_v di atas 0,70 pada korpus semacam itu lebih
     * sering menandakan topik yang terlalu sedikit atau dokumen kembar
     * daripada topik yang benar-benar rapi.
     */
    public static function coherenceBand(?float $cv): ?array
    {
        if ($cv === null) {
            return null;
        }

        return match (true) {
            $cv < 0.30 => ['label' => 'Tidak koheren', 'class' => 'text-red-700 bg-red-50 border-red-200'],
            $cv < 0.40 => ['label' => 'Lemah', 'class' => 'text-orange-700 bg-orange-50 border-orange-200'],
            $cv < 0.55 => ['label' => 'Baik untuk teks pendek', 'class' => 'text-green-700 bg-green-50 border-green-200'],
            $cv < 0.70 => ['label' => 'Sangat baik', 'class' => 'text-green-800 bg-green-100 border-green-300'],
            default => ['label' => 'Patut dicurigai', 'class' => 'text-amber-800 bg-amber-50 border-amber-200'],
        };
    }

    public static function coherenceLabel(?float $cv): ?string
    {
        return self::coherenceBand($cv)['label'] ?? null;
    }

    /**
     * Arti angka koherensi, bukan penilaiannya.
     *
     * Label pita ("Baik untuk teks pendek") menilai tanpa memberi tahu apa yang
     * sebenarnya diukur, sehingga pembaca tidak bisa menimbang sendiri apakah
     * penilaian itu masuk akal untuk datanya. Keterangan pada kartu karena itu
     * mendefinisikan angkanya; pitanya tinggal dipakai untuk warna.
     */
    public static function coherenceMeaning(?float $cv): ?string
    {
        if ($cv === null) {
            return null;
        }

        return 'Seberapa sering kata kunci satu topik benar-benar muncul berdekatan '
            .'di teks yang sama. Rentang 0 sampai 1, makin tinggi makin padu.';
    }
}
