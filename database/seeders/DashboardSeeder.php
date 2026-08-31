<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\TextAnalysis;
use App\Models\AnalysisResult;
use App\Models\PreprocessingConfig;
use App\Models\Dataset;
use App\Models\AnalysisLog;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DashboardSeeder extends Seeder
{
    public function run(): void
    {
        // ===================================
        // 1. CREATE TEST USER
        // ===================================
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Iman Alifa Novansyah',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✓ User created: test@example.com / password');

        // ===================================
        // 2. CREATE PREPROCESSING CONFIGS
        // ===================================
        PreprocessingConfig::firstOrCreate(
            ['name' => 'Default'],
            [
                'description' => 'Konfigurasi preprocessing default untuk semua analisis',
                'case_folding' => true,
                'remove_punctuation' => true,
                'remove_numbers' => false,
                'remove_stopwords' => true,
                'stemming' => true,
                'lemmatization' => false,
                'is_default' => true,
            ]
        );

        PreprocessingConfig::firstOrCreate(
            ['name' => 'Minimal'],
            [
                'description' => 'Preprocessing minimal, hanya case folding',
                'case_folding' => true,
                'remove_punctuation' => false,
                'remove_numbers' => false,
                'remove_stopwords' => false,
                'stemming' => false,
                'lemmatization' => false,
                'is_default' => false,
            ]
        );

        PreprocessingConfig::firstOrCreate(
            ['name' => 'Lengkap'],
            [
                'description' => 'Preprocessing lengkap dengan lemmatization',
                'case_folding' => true,
                'remove_punctuation' => true,
                'remove_numbers' => true,
                'remove_stopwords' => true,
                'stemming' => false,
                'lemmatization' => true,
                'is_default' => false,
            ]
        );

        $this->command->info('✓ Preprocessing configs created');

        // ===================================
        // 3. SAMPLE TEXTS
        // ===================================
        $sampleTexts = [
            'Pelayanan di kantor ini sangat baik dan ramah. Staff sangat membantu dan responsif. Sangat puas dengan hasilnya!',
            'Kurang memuaskan, perlu ditingkatkan lagi pelayanannya. Waktu tunggu terlalu lama.',
            'Aplikasi ini sangat membantu pekerjaan saya sehari-hari. Fitur yang disediakan lengkap. Terima kasih!',
            'Fitur yang disediakan sudah cukup lengkap dan mudah digunakan. Interface juga user-friendly.',
            'Terkadang loading lama dan sering error ketika upload file besar. Perlu optimasi.',
            'Website sangat informatif dan mudah dinavigasi. Desainnya juga menarik.',
            'Proses pendaftaran sangat mudah dan cepat. Tidak ada kendala berarti.',
            'Kualitas produk sangat bagus dan sesuai dengan deskripsi. Packaging juga rapi.',
            'Harga sedikit mahal tapi sebanding dengan kualitas yang diberikan.',
            'Pengiriman cepat dan barang sampai dengan selamat. Terima kasih!',
        ];

        // ===================================
        // 4. CREATE DUMMY DATASETS
        // ===================================
        $datasets = [
            [
                'name' => 'Survei Kepuasan Pelanggan 2024',
                'description' => 'Data survei kepuasan pelanggan kuartal 1 tahun 2024',
                'file_type' => 'csv',
                'total_rows' => 150,
            ],
            [
                'name' => 'Feedback Aplikasi Mobile',
                'description' => 'Kumpulan feedback pengguna aplikasi mobile dari Play Store',
                'file_type' => 'xlsx',
                'total_rows' => 89,
            ],
            [
                'name' => 'Komentar Media Sosial',
                'description' => 'Komentar dari berbagai platform media sosial',
                'file_type' => 'txt',
                'total_rows' => 234,
            ],
        ];

        foreach ($datasets as $index => $datasetData) {
            Dataset::create([
                'user_id' => $user->id,
                'name' => $datasetData['name'],
                'description' => $datasetData['description'],
                'file_path' => 'datasets/' . $user->id . '/dataset_' . ($index + 1) . '.' . $datasetData['file_type'],
                'file_name' => 'dataset_' . ($index + 1) . '.' . $datasetData['file_type'],
                'file_type' => $datasetData['file_type'],
                'file_size' => rand(50000, 500000),
                'total_rows' => $datasetData['total_rows'],
                'columns' => ['id', 'text', 'timestamp'],
                'is_processed' => true,
            ]);
        }

        $this->command->info('✓ Datasets created');

        // ===================================
        // 5. CREATE DUMMY ANALYSES
        // ===================================
        $analysisTypes = ['sentiment', 'aspect', 'topic', 'combined'];
        $statuses = ['completed', 'completed', 'completed', 'completed', 'processing', 'failed'];
        $inputTypes = ['manual', 'csv', 'txt', 'xlsx'];

        $titles = [
            'sentiment' => [
                'Analisis Sentimen Ulasan Produk',
                'Sentimen Feedback Pelanggan',
                'Analisis Opini Publik',
            ],
            'aspect' => [
                'Ekstraksi Aspek Layanan',
                'Analisis Aspek Produk',
                'Identifikasi Aspek Kepuasan',
            ],
            'topic' => [
                'Identifikasi Topik Diskusi',
                'Topic Modeling Komentar',
                'Analisis Tema Utama',
            ],
            'combined' => [
                'Analisis Komprehensif',
                'Full Analysis Report',
                'Analisis Lengkap Dataset',
            ],
        ];

        for ($i = 0; $i < 20; $i++) {
            $status = $statuses[array_rand($statuses)];
            $analysisType = $analysisTypes[array_rand($analysisTypes)];
            $inputType = $inputTypes[array_rand($inputTypes)];
            
            $daysAgo = rand(0, 30);
            $startedAt = Carbon::now()->subDays($daysAgo)->subHours(rand(0, 23))->subMinutes(rand(0, 59));
            $completedAt = $status === 'completed' ? $startedAt->copy()->addSeconds(rand(2, 45)) : null;

            $titleList = $titles[$analysisType];
            $title = $titleList[array_rand($titleList)] . ' #' . ($i + 1);

            $totalRecords = rand(5, 50);
            $randomTexts = [];
            for ($j = 0; $j < min($totalRecords, 10); $j++) {
                $randomTexts[] = $sampleTexts[array_rand($sampleTexts)];
            }

            $analysis = TextAnalysis::create([
                'user_id' => $user->id,
                'title' => $title,
                'description' => 'Deskripsi detail untuk ' . strtolower($title),
                'input_type' => $inputType,
                'raw_data' => $randomTexts,
                'file_path' => $inputType !== 'manual' ? 'uploads/' . $user->id . '/file_' . $i . '.' . $inputType : null,
                'file_name' => $inputType !== 'manual' ? 'data_' . $i . '.' . $inputType : null,
                'total_records' => $totalRecords,
                'analysis_type' => $analysisType,
                'status' => $status,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'error_message' => $status === 'failed' ? 'Connection timeout to NLP API service' : null,
            ]);

            // Create result for completed analyses
            if ($status === 'completed') {
                $this->createAnalysisResult($analysis, $randomTexts, $analysisType);
            }

            // Create log
            AnalysisLog::createLog(
                'created',
                $user->id,
                $analysis->id,
                'Analysis created',
                ['analysis_type' => $analysisType, 'status' => $status]
            );

            if ($status === 'completed') {
                AnalysisLog::createLog(
                    'completed',
                    $user->id,
                    $analysis->id,
                    'Analysis completed successfully',
                    ['duration' => $startedAt->diffInSeconds($completedAt) . 's']
                );
            } elseif ($status === 'failed') {
                AnalysisLog::createLog(
                    'failed',
                    $user->id,
                    $analysis->id,
                    'Analysis failed',
                    ['error' => 'Connection timeout']
                );
            }
        }

        $this->command->info('✓ Analyses and results created');
        $this->command->info('');
        $this->command->info('========================================');
        $this->command->info('  SEEDER COMPLETED SUCCESSFULLY! ✓');
        $this->command->info('========================================');
        $this->command->info('');
        $this->command->info('Login Credentials:');
        $this->command->info('  Email: test@example.com');
        $this->command->info('  Password: password');
        $this->command->info('');
    }

    /**
     * Create analysis result based on type
     */
    private function createAnalysisResult($analysis, $texts, $type)
    {
        $predictions = [];
        $sentiments = ['positive', 'negative', 'neutral'];
        
        foreach ($texts as $text) {
            $sentiment = $sentiments[array_rand($sentiments)];
            $predictions[] = [
                'text' => $text,
                'sentiment' => $sentiment,
                'score' => round(rand(70, 99) / 100, 2),
                'confidence' => round(rand(75, 95) / 100, 2),
            ];
        }

        // Generate sentiment distribution
        $positiveCount = count(array_filter($predictions, fn($p) => $p['sentiment'] === 'positive'));
        $negativeCount = count(array_filter($predictions, fn($p) => $p['sentiment'] === 'negative'));
        $neutralCount = count(array_filter($predictions, fn($p) => $p['sentiment'] === 'neutral'));
        $total = count($predictions);

        $sentimentDistribution = [
            'positive' => $total > 0 ? round(($positiveCount / $total) * 100, 1) : 0,
            'negative' => $total > 0 ? round(($negativeCount / $total) * 100, 1) : 0,
            'neutral' => $total > 0 ? round(($neutralCount / $total) * 100, 1) : 0,
        ];

        // Generate aspect results
        $aspectResults = [
            'aspects' => [
                ['name' => 'pelayanan', 'sentiment' => 'positive', 'count' => rand(5, 20)],
                ['name' => 'kualitas', 'sentiment' => 'positive', 'count' => rand(5, 20)],
                ['name' => 'harga', 'sentiment' => 'neutral', 'count' => rand(3, 15)],
                ['name' => 'fitur', 'sentiment' => 'positive', 'count' => rand(4, 18)],
                ['name' => 'kecepatan', 'sentiment' => 'negative', 'count' => rand(2, 10)],
            ],
        ];

        // Generate topic results
        $topicResults = [
            'topics' => [
                [
                    'topic_id' => 0,
                    'words' => ['pelayanan', 'baik', 'ramah', 'staff', 'membantu'],
                    'weight' => 0.85,
                    'size' => rand(10, 30),
                ],
                [
                    'topic_id' => 1,
                    'words' => ['aplikasi', 'fitur', 'lengkap', 'mudah', 'interface'],
                    'weight' => 0.78,
                    'size' => rand(8, 25),
                ],
                [
                    'topic_id' => 2,
                    'words' => ['kualitas', 'produk', 'bagus', 'sesuai', 'deskripsi'],
                    'weight' => 0.72,
                    'size' => rand(7, 20),
                ],
                [
                    'topic_id' => 3,
                    'words' => ['harga', 'mahal', 'terjangkau', 'sebanding', 'kualitas'],
                    'weight' => 0.65,
                    'size' => rand(5, 15),
                ],
            ],
        ];

        // Generate metrics
        $metrics = [
            'accuracy' => round(rand(85, 98) / 100, 2),
            'precision' => round(rand(80, 95) / 100, 2),
            'recall' => round(rand(82, 94) / 100, 2),
            'f1_score' => round(rand(83, 96) / 100, 2),
        ];

        // Generate summary
        $dominantSentiment = array_keys($sentimentDistribution, max($sentimentDistribution))[0];
        $summary = "Analisis {$analysis->total_records} data teks menunjukkan kecenderungan sentimen {$dominantSentiment} " .
                   "dengan persentase {$sentimentDistribution[$dominantSentiment]}%. " .
                   "Akurasi model mencapai " . ($metrics['accuracy'] * 100) . "%. " .
                   "Aspek utama yang dibahas meliputi pelayanan, kualitas, dan fitur.";

        // Create result
        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'preprocessed_data' => [
                'original_count' => count($texts),
                'processed_count' => count($texts),
                'removed_stopwords' => rand(50, 200),
                'stemmed_words' => rand(100, 300),
            ],
            'predictions' => $predictions,
            'sentiment_distribution' => $sentimentDistribution,
            'aspect_results' => $aspectResults,
            'topic_results' => $topicResults,
            'metrics' => $metrics,
            'summary' => $summary,
            'visualizations' => [
                'sentiment_chart' => 'charts/sentiment_' . $analysis->id . '.png',
                'wordcloud' => 'charts/wordcloud_' . $analysis->id . '.png',
                'topic_chart' => 'charts/topics_' . $analysis->id . '.png',
            ],
        ]);
    }
}