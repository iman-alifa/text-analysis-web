<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$res = App\Models\AnalysisResult::where('text_analysis_id', 72)->first();
file_put_contents('test_output.json', json_encode([
    'predictions' => array_slice($res->predictions ?? [], 0, 1),
    'aspect_results' => array_slice($res->aspect_results ?? [], 0, 1),
    'topic_results' => $res->topic_results,
]));
echo 'Done';
