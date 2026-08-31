<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\LlmService;

$service = new LlmService();
$topics = [
    [
        'words' => ['harga', 'murah', 'diskon', 'promo', 'terjangkau', 'mahal', 'biaya', 'beli', 'ekonomis', 'dompet']
    ],
    [
        'words' => ['kualitas', 'bagus', 'awet', 'rusak', 'jelek', 'original', 'asli', 'mutu', 'tahan', 'kokoh']
    ]
];

echo "Calling Gemini 3.5 Flash...\n";
$result = $service->generateTopicInterpretations($topics);
print_r($result);
