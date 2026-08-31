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
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
    ],

    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

    'nlp_api' => [
        'url' => env('NLP_API_URL', 'http://localhost:8001'),
        'timeout' => env('NLP_API_TIMEOUT', 7200),
        'batch_size' => env('NLP_API_BATCH_SIZE', 50),
        'max_texts_single_request' => env('NLP_API_MAX_TEXTS', 100),
    ],

];
