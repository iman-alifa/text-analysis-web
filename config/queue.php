<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    // Koneksi yang dipakai RetrainModel. Lihat koneksi 'training' di bawah.
    // Tes menyetelnya ke 'sync' lewat phpunit.xml.
    'training_connection' => env('QUEUE_TRAINING_CONNECTION', 'training'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // WAJIB LEBIH BESAR dari ProcessTextAnalysis::$timeout (1800 detik).
            //
            // Dengan nilai lama 90 detik, worker menganggap job "hilang" setelah
            // 90 detik lalu MELEPASKANNYA KEMBALI ke antrean - padahal worker
            // pertama masih mengerjakannya. Worker kedua mengambilnya dan
            // analisis yang sama berjalan berkali-kali sekaligus.
            //
            // Terukur, analisis nyata memakan 95-235 detik (82-885 teks), jadi
            // PRAKTIS SETIAP analisis terduplikasi. Akibatnya beruntun: service
            // NLP berjalan dengan --workers 1 sehingga permintaan kembar
            // mengantre dan membuat semuanya makin lambat, hasil saling timpa,
            // dan job akhirnya ditandai gagal setelah 3 percobaan. Inilah sebab
            // "analisis gagal / timeout" yang tampak acak.
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 4200),
            'after_commit' => false,
        ],

        // Antrean khusus pelatihan ulang (RetrainModel), di tabel `jobs` yang
        // sama tetapi dengan nama antrean dan retry_after sendiri.
        //
        // Dipisah karena dua alasan:
        // 1. Pelatihan di CPU VPS 4 vCPU diperkirakan 41-61 menit per putaran
        //    (terukur 1,68 dtk/sampel sentimen di laptop, VPS 2-3x lebih
        //    lambat). Satu worker bersama berarti analisis pengguna bahkan
        //    tidak dimulai selama itu.
        // 2. retry_after antrean analisis (4200) lebih kecil daripada
        //    RetrainModel::$timeout (7200), sehingga pelatihan yang masih
        //    berjalan bisa dilepas kembali ke antrean.
        //
        // Rantainya: HTTP retrain (6600) < RetrainModel::$timeout (7200) <
        // retry_after ini (7500). Dijaga QueueTimeoutConfigTest.
        'training' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'training',
            'retry_after' => (int) env('DB_TRAINING_RETRY_AFTER', 7500),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
