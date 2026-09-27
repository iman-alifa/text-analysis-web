<?php

namespace App\Http\Controllers;

use App\Services\PublicStatsService;

class LandingController extends Controller
{
    /**
     * Halaman depan.
     *
     * Seluruh angka di halaman ini dibaca dari keadaan terakhir lewat
     * PublicStatsService, jadi ikut berubah setiap ada evaluasi, koreksi, atau
     * analisis baru. Angka yang belum ada (pemasangan baru) tidak ditampilkan. Versi sebelumnya
     * memuat tiga testimoni bernama lengkap beserta jabatan dan foto, ditambah
     * "99% Akurasi Model" dan "500+ Pengguna Aktif" - semuanya karangan, dan
     * angka akurasinya bahkan bertentangan dengan hasil ukur sendiri (85,67%).
     * Testimoni karangan yang mencatut nama orang dan institusi nyata tidak
     * boleh dipakai, dan pada naskah skripsi klaim yang tidak terukur justru
     * yang pertama dipertanyakan penguji.
     */
    public function index(PublicStatsService $statistik)
    {
        $features = [
            [
                'icon' => '📊',
                'title' => 'Analisis Sentimen',
                'description' => 'Menilai tiap teks sebagai positif, negatif, atau netral memakai IndoBERT, '
                    .'lengkap dengan confidence score per baris.',
            ],
            [
                'icon' => '🎯',
                'title' => 'Ekstraksi Aspek',
                'description' => 'Menemukan hal apa yang sebenarnya dibicarakan, bukan sekadar suasana hatinya, '
                    .'sehingga keluhan bisa diarahkan ke sasaran yang tepat.',
            ],
            [
                'icon' => '🔍',
                'title' => 'Topic Modeling',
                'description' => 'Mengelompokkan teks menurut tema yang muncul sendiri dari datanya, '
                    .'tanpa daftar kategori yang ditentukan lebih dulu.',
            ],
            [
                'icon' => '📁',
                'title' => 'Beragam Sumber Data',
                'description' => 'Upload CSV, TXT, atau XLSX, ketik langsung, atau ambil komentar YouTube '
                    .'lalu analisis tanpa memindahkan berkas secara manual.',
            ],
            [
                'icon' => '🤖',
                'title' => 'Preprocessing Otomatis',
                'description' => 'Case folding, pembuangan tanda baca, stopword removal, dan stemming. '
                    .'Hasilnya bisa dilihat sebelum analisis dijalankan.',
            ],
            [
                'icon' => '📈',
                'title' => 'Hasil yang Bisa Ditelusuri',
                'description' => 'Grafik, word cloud, rincian per kelompok, dan penjelasan AI yang menyebut '
                    .'apa arti angkanya serta langkah tindak lanjutnya.',
            ],
        ];

        $s = $statistik->snapshot();
        $ci = $s['accuracy_ci'];

        // Angka di hero. Yang belum ada (mis. belum pernah ada evaluasi)
        // dibuang dari daftar, bukan diisi nol atau angka lama.
        $stats = array_values(array_filter([
            ['number' => PublicStatsService::persen($s['accuracy']), 'label' => 'Akurasi sentimen'],
            ['number' => PublicStatsService::persen($s['aspect_f1']), 'label' => 'F1-score ekstraksi aspek'],
            ['number' => $s['corrections'] ? PublicStatsService::angka($s['corrections']) : null, 'label' => 'Koreksi pengguna untuk active learning'],
            ['number' => $s['texts'] ? PublicStatsService::angka($s['texts']) : null, 'label' => 'Teks sudah dianalisis'],
        ], fn ($stat) => $stat['number'] !== null));

        // Angka evaluasi adalah angka model yang sedang dipakai, dan kalimatnya
        // menyebut iterasi asalnya: sentimen dan aspek bisa berasal dari
        // iterasi yang berbeda (model aspek dikembalikan ke iterasi 1).
        $asal = fn (?int $iterasi, string $modul) => $iterasi === null ? ''
            : ($iterasi === 0
                ? "Model {$modul} yang sedang dipakai adalah base model. "
                : "Model {$modul} yang sedang dipakai berasal dari iterasi {$iterasi}. ");

        $pengukuran = array_values(array_filter([
            $s['accuracy'] === null ? null : [
                'angka' => PublicStatsService::persen($s['accuracy'], 2),
                'judul' => 'Akurasi analisis sentimen',
                'dasar' => $asal($s['accuracy_iteration'], 'sentimen')
                    .'Diukur pada test set '.PublicStatsService::angka($s['test_size'])
                    .' komentar yang dilabeli manusia dan tidak pernah dipakai untuk training.'
                    .($ci ? ' Confidence interval 95%: '.PublicStatsService::persen($ci['low'])
                        .' sampai '.PublicStatsService::persen($ci['high']).'.' : ''),
            ],
            $s['aspect_f1'] === null ? null : [
                'angka' => PublicStatsService::persen($s['aspect_f1'], 2),
                'judul' => 'F1-score ekstraksi aspek',
                'dasar' => $asal($s['aspect_iteration'], 'aspek').($s['aspect_f1_baseline'] !== null
                    ? ($s['aspect_f1'] >= $s['aspect_f1_baseline'] ? 'Naik dari ' : 'Sebelumnya ')
                        .PublicStatsService::persen($s['aspect_f1_baseline'], 2)
                        .' sebelum model belajar dari koreksi pengguna, diukur pada test set yang sama di tiap iterasi.'
                        .' Pencocokan parsial: aspek dianggap ketemu bila berbagi kata dasar dengan label manusia.'
                    : 'Micro F1-score pada test set yang dilabeli manusia, dengan pencocokan parsial '
                        .'(aspek dianggap ketemu bila berbagi kata dasar dengan label manusia).'),
            ],
            ! $s['iterations'] ? null : [
                'angka' => (string) $s['iterations'],
                'judul' => 'Iterasi active learning',
                'dasar' => 'Tiap iterasi melatih ulang model dari base model memakai seluruh koreksi '
                    .'yang terkumpul, lalu mengukurnya ulang pada test set yang dibekukan.',
            ],
        ]));

        $diukurPada = $s['measured_at']?->translatedFormat('j F Y');

        return view('landing', compact('features', 'stats', 'pengukuran', 'diukurPada'));
    }
}
