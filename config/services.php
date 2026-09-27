<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Dibaca lewat config() dan bukan env() langsung, supaya tetap bekerja
     * setelah `php artisan config:cache` (env() mengembalikan null di sana).
     */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),

        // Sengaja dipatok versinya, bukan alias 'gemini-flash-latest': narasi
        // yang dikutip di naskah harus bisa direproduksi berbulan-bulan
        // kemudian, dan alias -latest berubah tanpa pemberitahuan.
        //
        // Jangan otomatis mengejar versi terbaru. Diuji 3 Sep 2026 dengan API
        // key proyek ini: gemini-3.5-flash membalas 200, gemini-3.8-flash
        // membalas 503.
        //
        // Boleh diisi lebih dari satu model dipisah koma ("gemini-3.5-flash,
        // gemini-3.0-flash") - LlmService mencobanya berurutan dan berhenti
        // pada yang pertama berhasil. Ini BUKAN mengejar versi terbaru: tiap
        // kandidat tetap dipatok eksplisit, dan model yang benar-benar
        // menjawab tersimpan apa adanya di provenance narasi (bukan selalu
        // kandidat pertama), jadi setiap narasi tetap bisa disebut model
        // persisnya. Gunanya menghindari layanan mengulang model yang sama
        // yang sedang padat (503) alih-alih menunggunya lebih lama.
        // Diuji 24 Sep 2026 dengan kunci proyek: gemini-3.5-flash dan
        // gemini-3.6-flash membalas 200. gemini-2.5-* dan seluruh 1.5 sudah
        // 404 ("no longer available to new users") - cadangan yang pensiun
        // bukan cadangan, dan dulu justru membuat galat sesaat model utama
        // tampil sebagai "model tidak tersedia".
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash,gemini-3.6-flash'),

        // Narasi hasil analisis lebih panjang daripada label topik, jadi
        // batas 30 detik yang lama kadang terpotong.
        'timeout' => (int) env('GEMINI_TIMEOUT', 45),

        // Percobaan ulang PER MODEL sebelum pindah ke kandidat berikutnya di
        // atas. Sengaja kecil (bukan mengulang lama pada satu model padat) -
        // daftar model itu sendiri yang jadi strategi utama menghadapi 503,
        // bukan retry panjang pada model yang sama.
        'retry' => (int) env('GEMINI_RETRY', 2),
    ],

    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

    'nlp_api' => [
        'url' => env('NLP_API_URL', 'http://localhost:8001'),
        // Kunci bersama yang dikirim sebagai header X-API-Key.
        //
        // Layanan NLP menolak start di produksi tanpa kunci ini (lihat
        // app/security.py di nlp-api-service): tanpa autentikasi, siapa pun
        // yang tahu URL-nya bisa memanggil /api/retrain/* dan menimpa bobot
        // model. Kosong hanya sah untuk pengembangan lokal.
        'key' => env('NLP_API_KEY', ''),
        // Timeout satu permintaan HTTP ke service NLP.
        //
        // HARUS LEBIH KECIL dari ProcessTextAnalysis::$timeout (1800 detik),
        // supaya yang menghentikan pekerjaan macet adalah job-nya - yang bisa
        // mencatat kegagalan dan mencoba ulang dengan rapi - bukan permintaan
        // HTTP yang menggantung. Nilai lama 7200 detik (2 jam) empat kali lebih
        // besar dari timeout job, sehingga job selalu mati lebih dulu tanpa
        // sempat mencatat sebab yang berguna.
        //
        // Diukur pada jalur produksi: 885 teks memakan 50 detik (sentimen),
        // 54 detik (aspek), 52 detik (topik), dan 235 detik untuk analisis
        // gabungan. 600 detik memberi ruang lebih dari 10x, termasuk untuk
        // permintaan pertama yang masih memuat bobot model.
        'timeout' => env('NLP_API_TIMEOUT', 600),

        // Timeout HTTP khusus /api/retrain/*, terpisah dari timeout analisis.
        //
        // Dulu memakai timeout * 2 = 1200 detik. Terukur di CPU (laptop Ryzen 7
        // 5700U, torch 2.9.1+cpu): 1,68 dtk/sampel sentimen, 1,06 aspek. Di VPS
        // 4 vCPU diperkirakan 2-3x lebih lambat, jadi pelatihan sentimen atas
        // kolam 481 sampel memakan 27-40 menit dan melewati 1200 detik.
        //
        // Menyerahnya klien TIDAK menghentikan pelatihan di layanan (terukur
        // dengan uvicorn sungguhan): checkpoint tetap disimpan sementara Laravel
        // mencatat putaran itu 'failed'. Catatan lalu tidak cocok lagi dengan
        // bobot yang hidup - kelas kesalahan yang dulu membatalkan iterasi 2.
        //
        // Rantai: nilai ini < RetrainModel::$timeout (7200) < retry_after
        // antrean 'training' (7500). Dijaga QueueTimeoutConfigTest.
        'retrain_timeout' => (int) env('NLP_API_RETRAIN_TIMEOUT', 6600),

        // Timeout HTTP khusus /api/analyze/topic. Pemodelan topik adalah satu
        // permintaan utuh (tidak dipecah per batch) dan paling lama di antara
        // semua jalur. Terukur di VPS produksi (Xeon Gold 6138, 3 thread):
        // 3.366 komentar = 972 detik. Batas lama (timeout * 2 = 1200) hanya
        // menyisakan ~19%; korpus 1,2x lebih besar sudah melewatinya.
        //
        // WAJIB < ProcessTextAnalysis::$timeout (3600). Pada analisis gabungan
        // berbatch, sentimen + aspek sudah memakan ~13 menit sebelum topik
        // dimulai, jadi 2400 masih menyisakan ruang di dalam satu job.
        'topic_timeout' => (int) env('NLP_API_TOPIC_TIMEOUT', 2400),

        'batch_size' => env('NLP_API_BATCH_SIZE', 50),

        // Batas yang ditegakkan service NLP (app/config.py: max_batch_size dan
        // max_text_length). Divalidasi lebih dulu di sisi Laravel supaya
        // pengguna mendapat pesan yang jelas, bukan HTTP 422 mentah dari API.
        //
        // Menggantikan 'max_texts_single_request' yang bernilai 100 padahal
        // batas sebenarnya 10.000, dan tidak pernah dibaca kode mana pun.
        'max_texts' => env('NLP_API_MAX_TEXTS', 10000),
        'max_text_length' => env('NLP_API_MAX_TEXT_LENGTH', 10000),
    ],

];
