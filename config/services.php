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
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),

        // Narasi hasil analisis lebih panjang daripada label topik, jadi
        // batas 30 detik yang lama kadang terpotong.
        'timeout' => (int) env('GEMINI_TIMEOUT', 45),
        'retry' => (int) env('GEMINI_RETRY', 2),
    ],

    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

    'nlp_api' => [
        'url' => env('NLP_API_URL', 'http://localhost:8001'),
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
