<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ambang keyakinan
    |--------------------------------------------------------------------------
    |
    | Prediksi dengan keyakinan di bawah `review_threshold` ditawarkan kepada
    | pengguna untuk dikoreksi (active learning). Nilai ini hanya dipakai bila
    | NLP API tidak menyertakan ambangnya sendiri di dalam `review_queue` -
    | ambang dari model selalu lebih berwenang daripada tebakan sisi Laravel.
    |
    | Bawaan 0,70 dipilih dari sebaran nyata korpus proyek ini: pada 289 dan 60
    | teks, 0,70 menyisakan 20 dan 7 baris. Cukup banyak untuk berarti bagi
    | pelatihan ulang (API mensyaratkan minimal 10 sampel), cukup sedikit untuk
    | benar-benar dikoreksi orang dalam sekali duduk. Ambang 0,85 menghasilkan
    | 33 dan 15 baris - sebagian besar di antaranya sebenarnya sudah benar.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Ambang keyakinan
    |--------------------------------------------------------------------------
    |
    | Ambang dinyatakan sebagai TOLERANSI GALAT, bukan sebagai angka keyakinan
    | yang disetel. Bedanya menentukan apakah angkanya bisa dipertanggungjawabkan.
    |
    | Model sentimen dikalibrasi suhu (T = 2,7748) dengan ECE 0,0209 pada set uji
    | SmSA, n = 500. Pada model terkalibrasi, skor keyakinan adalah taksiran
    | peluang prediksi itu benar - sehingga ambang punya arti langsung:
    | "tinjau setiap prediksi yang peluang benarnya di bawah (1 - toleransi)".
    |
    | Itu PERNYATAAN RISIKO, bukan hasil penyetelan pada data uji. Ambang yang
    | dipilih dari tabel yield pada set uji yang juga dilaporkan (seperti 0,94 di
    | layanan) menimbulkan masalah selection-on-test; ambang yang diturunkan dari
    | toleransi galat tidak.
    |
    | Toleransi 10% (ambang 0,90) juga terkonfirmasi tabel reliabilitas model
    | yang sama:
    |
    |     rentang        n     porsi    akurasi
    |     0,90-0,99    455    91,0%     95,4%
    |     0,70-0,90     28     5,6%     57,1%
    |     0,50-0,70     15     3,0%     53,3%
    |     0,00-0,50      2     0,4%      0,0%
    |
    | Ada jurang 38 poin tepat di 0,90. Yang lebih penting: di bawah 0,90 model
    | TERLALU PERCAYA DIRI - akurasi nyatanya 57% padahal keyakinannya menyebut
    | 70-90%. ECE yang bagus secara keseluruhan didominasi 91% sampel di pita
    | teratas dan menutupi hal itu. Justru karena keyakinannya sendiri tidak bisa
    | dipercaya di bawah 0,90, di sanalah manusia perlu melihat.
    |
    | Catatan kejujuran: tabel itu dari set uji SmSA, bukan dari komentar YouTube.
    | Halaman evaluasi menghitung ulang tabel yang sama dari golden dataset
    | sendiri (`metrics.reliability`), jadi ambangnya bisa dikonfirmasi pada
    | korpus studi kasus.
    |
    */

    'confidence' => [
        /*
         | Toleransi galat yang diterima tanpa ditinjau manusia. Ambang tinjauan
         | = 1 - nilai ini. Satu angka ini menentukan SELURUH tampilan keyakinan:
         | ambang tinjauan, batas pita sebaran, dan penilaian ringkas. Sebelumnya
         | ketiganya punya angka sendiri (0,80 / 0,85 / 0,70 / 0,90) sehingga satu
         | panel bisa menyebut sebuah teks "cukup yakin" sekaligus "perlu
         | ditinjau".
         */
        'error_tolerance' => (float) env('ANALYSIS_ERROR_TOLERANCE', 0.10),

        /*
         | Batas pita sebaran, juga sebagai toleransi galat. Yang pertama SELALU
         | disamakan dengan `error_tolerance` di atas oleh
         | ConfidenceReportService, jadi pita teratas dan ambang tinjauan tidak
         | mungkin berselisih.
         |
         | 0,30 untuk pita kedua bukan angka bulat sembarangan: pada tabel
         | reliabilitas di atas, 0,70 adalah tempat akurasi nyata berhenti turun
         | (57,1% lalu 53,3%) dan mulai runtuh (0%).
         */
        'band_error_levels' => [0.10, 0.30],

        /*
         | Menimpa ambang hasil hitungan di atas. Kosongkan kecuali ada alasan
         | khusus - mengisinya berarti melepas dasar kalibrasi tadi, dan angkanya
         | harus dipertanggungjawabkan sendiri di naskah.
         */
        'review_threshold' => env('ANALYSIS_REVIEW_THRESHOLD') !== null
            ? (float) env('ANALYSIS_REVIEW_THRESHOLD')
            : null,

        /*
         | Dipakai hanya bila layanan NLP tidak terjangkau DAN tidak ada
         | pengaturan eksplisit di atas.
         |
         | 0,94 adalah nilai efektif di nlp-api-service untuk bobot yang aktif
         | (crypter70). Nilai ini per bobot dan tidak boleh disalin antar model.
         | Angka capaiannya ("meninjau 17% menangkap 64% kesalahan") dipilih dari
         | tabel yield pada set uji yang juga dilaporkan, jadi estimasi
         | dalam-sampel dan belum boleh dikutip.
         */
        'fallback_threshold' => 0.94,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pelatihan ulang otomatis
    |--------------------------------------------------------------------------
    |
    | Loop active learning menutup dirinya sendiri: begitu koreksi pengguna
    | mencapai ambang, pelatihan ulang diantrekan tanpa perlu admin menekan
    | tombol. Halaman admin tetap ada, tetapi hanya untuk pengawasan.
    |
    | `min_new_corrections` dihitung dari koreksi yang dibuat SETELAH pelatihan
    | terakhir, bukan jumlah total - kalau total, setiap koreksi berikutnya akan
    | memicu pelatihan lagi selamanya. Bawaannya menyamai
    | NLPApiService::MIN_RETRAIN_SAMPLES; di bawah itu API menolak.
    |
    | `cooldown_hours` mencegah beberapa sesi koreksi yang berdekatan memicu
    | fine-tuning berulang kali. Isi 0 untuk mematikannya.
    |
    */

    'auto_retrain' => [
        'enabled' => (bool) env('ANALYSIS_AUTO_RETRAIN', true),

        // Bawaan awal mode tunda (fase evaluasi kuesioner). Nilai yang berlaku
        // disimpan di tabel app_settings dan diubah dari /admin/training;
        // lihat RetrainDeferralService.
        'defer' => (bool) env('ANALYSIS_RETRAIN_DEFER', false),
        'min_new_corrections' => (int) env('ANALYSIS_AUTO_RETRAIN_MIN', 10),
        'cooldown_hours' => (int) env('ANALYSIS_AUTO_RETRAIN_COOLDOWN', 6),
        'model_type' => env('ANALYSIS_AUTO_RETRAIN_MODEL', 'both'),
        'epochs' => (int) env('ANALYSIS_AUTO_RETRAIN_EPOCHS', 3),

        /*
         | Cermin `settings.calibration_min_samples` di nlp-api-service (50).
         |
         | Dipakai HANYA untuk menandai riwayat training: di bawah angka ini,
         | layanan tidak mengukur ulang suhu kalibrasi melainkan mewarisinya
         | dari bobot sebelumnya, sehingga skor keyakinan pada iterasi itu
         | belum diukur ulang. Nilainya tidak menegakkan apa pun di sisi web -
         | keputusannya sepenuhnya milik layanan - jadi kalau ambang di sana
         | berubah, yang keliru di sini hanya keterangannya, bukan perilakunya.
         */
        'calibration_min_val' => (int) env('ANALYSIS_CALIBRATION_MIN_VAL', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluasi hold-out
    |--------------------------------------------------------------------------
    |
    | Evaluasi dijalankan OTOMATIS di akhir setiap putaran pelatihan ulang
    | (AutoEvaluationService, dipanggil RetrainModel), atas test set beku yang
    | sedang dipakai. Sempat manual atas permintaan pemilik proyek; dijadikan
    | otomatis lagi (24 Sep 2026) supaya loop active learning berjalan penuh
    | tanpa campur tangan: koreksi -> pelatihan -> pengukuran -> putaran
    | berikutnya.
    |
    | Mengapa di akhir PUTARAN, bukan setiap job: satu putaran melatih sentimen
    | dan aspek sebagai dua job. Mengukur setelah job pertama berarti mengukur
    | campuran bobot baru dan lama yang tidak cocok dengan iterasi mana pun.
    |
    | Tombol di /admin/evaluation dan `evaluation:run` tetap ada untuk
    | pengukuran ulang manual. Matikan pemicu otomatis dengan
    | ANALYSIS_AUTO_EVALUATE=false.
    |
    | `bootstrap_rounds` dan `bootstrap_seed` menentukan selang kepercayaan
    | macro-F1. Semainya tetap supaya angka di naskah bisa dihitung ulang;
    | jangan diubah di tengah rangkaian studi kasus.
    |
    */

    'evaluation' => [
        'auto' => (bool) env('ANALYSIS_AUTO_EVALUATE', true),
        'bootstrap_rounds' => (int) env('ANALYSIS_EVAL_BOOTSTRAP', 1000),
        'bootstrap_seed' => (int) env('ANALYSIS_EVAL_SEED', 42),
    ],

    /*
    |--------------------------------------------------------------------------
    | Grafik
    |--------------------------------------------------------------------------
    |
    | Mode aspek otomatis tidak punya daftar tetap - ia mengekstrak sebanyak
    | apa pun frasa benda yang ditemukannya, termasuk yang cuma disebut sekali
    | atau merupakan variasi kata dasar yang sama. Pada korpus ribuan komentar
    | ini bisa jadi puluhan "aspek" sekaligus, dan grafik batang bertumpuk
    | untuk semuanya jadi sumbu-X yang penuh sesak dan tidak terbaca.
    |
    | `max_aspects` membatasi HANYA grafiknya - diambil dari yang penyebutannya
    | terbanyak (paling mewakili gambaran keseluruhan), bukan dipotong begitu
    | saja dari urutan ekstraksi. Kartu "Detail Aspek" di bawah grafik tetap
    | menampilkan semuanya lewat paginasi, jadi tidak ada data yang hilang -
    | ini murni soal keterbacaan satu grafik.
    |
    */

    'chart' => [
        'max_aspects' => (int) env('ANALYSIS_CHART_MAX_ASPECTS', 15),
    ],

];
