<?php

namespace App\Services;

use App\Exceptions\NlpBusyException;
use App\Exceptions\RetrainBusyException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NLPApiService
{
    /** Minimal sampel yang diterima endpoint /api/retrain/* di sisi Python */
    public const MIN_RETRAIN_SAMPLES = 10;

    private string $apiUrl;

    private int $timeout;

    private int $batchSize;

    private string $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.nlp_api.url');
        $this->timeout = config('services.nlp_api.timeout', 300);
        $this->batchSize = config('services.nlp_api.batch_size', 50);
        $this->apiKey = (string) config('services.nlp_api.key', '');
    }

    /**
     * Klien HTTP untuk layanan NLP, lengkap dengan kunci API.
     *
     * Dibuat terpusat dan BUKAN ditempel di dua belas tempat pemanggilan.
     * Alasannya kegagalan-tertutup: satu pemanggilan yang terlupa headernya
     * akan gagal 401 pada saat dijalankan, bukan saat ditinjau - dan di kelas
     * ini sudah pernah ada perbaikan yang terlewat di sebagian jalur
     * (`predefined_aspects` dijatuhkan di dua tempat sekaligus).
     *
     * Kunci kosong berarti layanan NLP berjalan tanpa autentikasi (hanya sah
     * untuk pengembangan lokal); header tidak dikirim dan perilakunya sama
     * seperti sebelumnya.
     */
    private function http(?int $timeout = null)
    {
        $klien = Http::timeout($timeout ?? $this->timeout);

        return $this->apiKey === ''
            ? $klien
            : $klien->withHeaders(['X-API-Key' => $this->apiKey]);
    }

    /**
     * Kirim permintaan ANALISIS ke layanan NLP.
     *
     * Satu jalur untuk keempat endpoint analisis, supaya aturan "sedang
     * melatih" tidak terlewat di salah satunya. Bila layanan membalas 503
     * dengan `Retry-After` (model yang dibutuhkan sedang dilatih ulang), HTTP
     * TIDAK diulang - mengulang dalam hitungan detik hanya menghasilkan 503
     * lagi - dan NlpBusyException dilempar supaya job melepas dirinya kembali
     * ke antrean dengan jeda sebesar itu.
     *
     * 503 tanpa `Retry-After` tetap berarti "layanan belum siap" dan diulang
     * seperti galat lain.
     *
     * Permintaan yang KEHABISAN WAKTU juga tidak diulang. Layanan tidak
     * membatalkan handler ketika klien memutus (terukur dengan uvicorn
     * sungguhan), jadi perhitungan pertama tetap berjalan; mengirim ulang
     * berarti dua pemodelan topik serentak di 3 vCPU, masing-masing makin
     * lambat, dan yang kedua pun kehabisan waktu. Yang diulang hanya galat
     * yang memang belum sampai dikerjakan: koneksi ditolak/terputus dan 5xx.
     */
    private function kirimAnalisis(string $jalur, array $payload, int $timeout, int $kali, int $jedaMs): Response
    {
        try {
            return $this->http($timeout)
                ->retry(
                    $kali,
                    $jedaMs,
                    fn ($e) => ! ($e instanceof RequestException && NlpBusyException::cocok($e->response))
                        && ! self::kehabisanWaktu($e)
                )
                ->post("{$this->apiUrl}{$jalur}", $payload);
        } catch (RequestException $e) {
            if (NlpBusyException::cocok($e->response)) {
                throw NlpBusyException::dariRespons($e->response);
            }

            throw $e;
        }
    }

    /**
     * Apakah galat ini batas waktu klien habis (cURL 28), bukan koneksi gagal.
     *
     * Keduanya sama-sama ConnectionException di Laravel, tetapi artinya
     * berlawanan: koneksi yang gagal berarti layanan belum mengerjakan apa pun
     * (aman diulang), sedangkan batas waktu berarti layanan MASIH mengerjakan.
     */
    public static function kehabisanWaktu(\Throwable $e): bool
    {
        return $e instanceof ConnectionException
            && preg_match('/cURL error 28|timed out|timeout/i', $e->getMessage()) === 1;
    }

    /**
     * Panaskan bobot model sebelum analisis dimulai.
     *
     * Service NLP memuat bobot secara MALAS agar RAM saat start tetap rendah
     * (syarat kuota Railway), sehingga permintaan pertama tiap jenis membayar
     * biaya unduh dan muat model - di kontainer baru bisa memakan menit.
     * Dari sisi job, itu tidak bisa dibedakan dari analisis yang menggantung,
     * dan dulu berakhir sebagai timeout yang tampak acak.
     *
     * Kegagalan pemanasan sengaja TIDAK dilempar: analisis tetap bisa berjalan
     * (service punya rantai cadangan), hanya saja lebih lambat. Memanaskan
     * adalah optimasi, bukan prasyarat.
     *
     * @return bool True bila seluruh bobot siap.
     */
    /**
     * Status layanan apa adanya dari GET /health.
     *
     * Membawa `weights_loaded` per model dan provenance model sentimen.
     * Mengembalikan null bila layanan tidak bisa dihubungi, supaya pemanggil
     * bisa membedakan "tidak siap" dari "tidak tahu".
     */
    public function health(int $timeout = 10): ?array
    {
        try {
            $response = $this->http($timeout)->get("{$this->apiUrl}/health");

            return $response->successful() ? $response->json() : null;

        } catch (Exception $e) {
            Log::warning('Health check NLP API gagal: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Apakah kunci API diterima layanan?
     *
     * `/health` sengaja terbuka tanpa kunci karena platform hosting memakainya
     * sebagai healthcheck. Akibatnya `/health` yang membalas 200 TIDAK membuktikan
     * apa pun soal kunci: dengan kunci yang salah pun ia tetap hijau, sementara
     * setiap analisis ditolak 401. Pemeriksaan ini memanggil endpoint yang
     * memang berkunci.
     *
     * `/api/normalize/aspects` dipilih karena paling ringan: hanya stemmer,
     * tidak memuat model, dan tidak mengubah apa pun di layanan.
     *
     * @return array{ok: bool, status: int|null, pesan: string}
     */
    public function checkApiKey(): array
    {
        try {
            $response = $this->http(30)
                ->post("{$this->apiUrl}/api/normalize/aspects", ['aspects' => ['pelayanan']]);
        } catch (Exception $e) {
            return ['ok' => false, 'status' => null, 'pesan' => 'Layanan tidak terjangkau: '.$e->getMessage()];
        }

        $status = $response->status();

        return match (true) {
            $response->successful() => ['ok' => true, 'status' => $status, 'pesan' => 'Kunci diterima.'],
            in_array($status, [401, 403], true) => [
                'ok' => false,
                'status' => $status,
                'pesan' => $this->apiKey === ''
                    ? 'Layanan meminta kunci, tetapi NLP_API_KEY kosong.'
                    : 'Kunci ditolak. NLP_API_KEY harus sama persis dengan API_KEY di layanan NLP.',
            ],
            default => ['ok' => false, 'status' => $status, 'pesan' => "Layanan membalas HTTP {$status}."],
        };
    }

    /**
     * Ambil satu nilai dari blok `models` pada respons /health.
     *
     * Bentuk sebenarnya adalah `{"models": {"sentiment_review_threshold": 0.94,
     * ...}}` - bukan di tingkat atas. Kode lama membacanya di tingkat atas
     * sehingga selalu mendapat null: panel kesiapan model pada formulir
     * analisis menampilkan seluruh kolomnya kosong, dan pencarian ambang
     * tinjauan selalu jatuh ke nilai cadangan.
     *
     * Tingkat atas tetap diperiksa sebagai cadangan supaya versi layanan yang
     * lebih tua (bila ada) tidak ikut rusak.
     */
    public function healthModelValue(?array $health, string $key): mixed
    {
        return $health['models'][$key] ?? $health[$key] ?? null;
    }

    /**
     * Bentuk dasar nama aspek, memakai stemmer yang sama persis dengan yang
     * dipakai ekstraksi aspek di layanan.
     *
     * Wajib lewat layanan, bukan ditebak di PHP: stemmer Sastrawi hidup di
     * Python, dan menebak bentuk dasar di sini akan melahirkan definisi
     * kecocokan kedua yang berbeda dari definisi yang dipakai ekstraksinya -
     * persis kesalahan yang sedang diperbaiki.
     *
     * @param  array<int, string>  $aspects
     * @return array<string, string>|null peta nama asal -> bentuk dasar
     */
    public function normalizeAspectNames(array $aspects): ?array
    {
        $aspects = array_values(array_unique(array_filter(array_map(
            fn ($a) => trim((string) $a),
            $aspects
        ), fn ($a) => $a !== '')));

        if (empty($aspects)) {
            return [];
        }

        try {
            $response = $this->http(60)
                ->post("{$this->apiUrl}/api/normalize/aspects", ['aspects' => $aspects]);

            if (! $response->successful()) {
                Log::warning('Normalisasi aspek gagal: '.$response->status().' '.$response->body());

                return null;
            }

            $peta = $response->json('results.normalized');

            return is_array($peta) ? $peta : null;
        } catch (Exception $e) {
            Log::warning('Normalisasi aspek gagal: '.$e->getMessage());

            return null;
        }
    }

    public function warmUp(): bool
    {
        try {
            $response = $this->http($this->timeout)->post("{$this->apiUrl}/api/warmup");

            if (! $response->successful()) {
                Log::warning('Pemanasan model NLP gagal: '.$response->status());

                return false;
            }

            $models = $response->json('models', []);
            $siap = collect($models)->every(fn ($m) => ($m['loaded'] ?? false) === true);

            Log::info('Pemanasan model NLP selesai', [
                'total_seconds' => $response->json('total_seconds'),
                'semua_siap' => $siap,
            ]);

            return $siap;
        } catch (Exception $e) {
            Log::warning('Pemanasan model NLP dilewati: '.$e->getMessage());

            return false;
        }
    }

    public function testConnection(): array
    {
        try {
            $response = $this->http(10)->get("{$this->apiUrl}/health");

            if ($response->successful()) {
                return [
                    'status' => 'success',
                    'message' => 'Connected to NLP API',
                    'data' => $response->json(),
                ];
            }

            return [
                'status' => 'error',
                'message' => 'NLP API returned error',
                'data' => $response->json(),
            ];

        } catch (Exception $e) {
            Log::error('NLP API Connection Failed: '.$e->getMessage());

            return [
                'status' => 'error',
                'message' => 'Failed to connect to NLP API',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  string|null  $task  Profil modul (transformer|bag_of_words|span).
     *                             Tanpa ini pratinjau menampilkan hasil yang berbeda
     *                             dari yang dijalankan modul, karena tiap modul
     *                             memaksakan kebijakannya sendiri di atas config.
     */
    public function preprocessText(array $texts, array $config = [], ?string $task = null): array
    {
        try {
            $payload = [
                'texts' => $texts,
                'config' => $config,
            ];

            if ($task !== null) {
                $payload['task'] = $task;
            }

            $response = $this->http($this->timeout)
                ->post("{$this->apiUrl}/api/preprocess", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            throw new Exception('Preprocessing failed: '.$response->body());
        } catch (Exception $e) {
            Log::error('Preprocessing Error: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Analisis Sentimen dengan batch processing
     */
    /**
     * Salinan layanan yang MENOLAK hasil parsial: batch pertama yang gagal
     * langsung menggagalkan seluruh permintaan.
     *
     * Analisis pengguna sengaja memaafkan batch yang gagal (dilaporkan lewat
     * `failed_batches`), tetapi evaluasi hold-out tidak boleh: baris dari batch
     * yang gagal terbaca sebagai "tidak ada aspek" atau hilang, dan metrik yang
     * dihitung darinya salah tanpa jejak apa pun. Berhenti di batch pertama
     * juga membebaskan worker, yang dipakai bersama analisis pengguna, alih-alih
     * menunggu sisa batch dari pekerjaan yang toh akan ditolak.
     */
    public function utuh(): static
    {
        $salinan = clone $this;
        $salinan->wajibUtuh = true;

        return $salinan;
    }

    private bool $wajibUtuh = false;

    public function analyzeSentiment(array $texts, array $config = [], $progressCallback = null): array
    {
        try {
            if (count($texts) <= $this->batchSize) {
                return $this->executeSentimentAnalysis($texts, $config);
            }

            return $this->batchSentimentAnalysis($texts, $config, $progressCallback);

        } catch (Exception $e) {
            Log::error('Sentiment Analysis Error: '.$e->getMessage());
            throw $e;
        }
    }

    private function executeSentimentAnalysis(array $texts, array $config): array
    {
        $response = $this->kirimAnalisis('/api/analyze/sentiment', [
            'texts' => $texts,
            'preprocessing_config' => $config,
        ], $this->timeout, 3, 1000);

        if ($response->successful()) {
            $result = $response->json();

            if (! isset($result['status']) || $result['status'] !== 'success') {
                throw new Exception('Invalid response from NLP API');
            }

            return $result;
        }

        throw new Exception('Sentiment analysis failed: '.$response->body());
    }

    private function batchSentimentAnalysis(array $texts, array $config, $progressCallback = null): array
    {
        // preserve_keys supaya setiap prediksi bisa dipetakan balik ke posisi
        // teks aslinya, walaupun ada batch yang gagal di tengah jalan.
        $batches = array_chunk($texts, $this->batchSize, true);
        $totalBatches = count($batches);

        $allPredictions = [];
        $failedBatches = [];
        $reviewThreshold = null;

        Log::info("Processing {$totalBatches} batches for sentiment analysis");

        foreach ($batches as $index => $batch) {
            $batchNumber = $index + 1;
            $originalIndexes = array_keys($batch);

            Log::info("Processing batch {$batchNumber}/{$totalBatches}");

            if ($progressCallback) {
                $progress = 40 + (($batchNumber / $totalBatches) * 30);
                call_user_func($progressCallback, $progress, "Menganalisis sentimen batch {$batchNumber}/{$totalBatches}...");
            }

            try {
                $result = $this->executeSentimentAnalysis(array_values($batch), $config);

                $predictions = $result['results']['predictions'] ?? [];

                // Ambang review sama untuk semua batch; simpan yang pertama ada
                // supaya antrean bisa disusun ulang dengan aturan yang sama.
                $reviewThreshold ??= $result['results']['review_queue']['threshold'] ?? null;

                foreach ($predictions as $position => $prediction) {
                    if (isset($originalIndexes[$position])) {
                        $prediction['original_index'] = $originalIndexes[$position];
                    }
                    $allPredictions[] = $prediction;
                }

                usleep(100000);

            } catch (NlpBusyException $e) {
                // Bukan batch yang gagal: model sedang dilatih. Seluruh job
                // dilepas dan diulang nanti, bukan menyelesaikan analisis
                // dengan batch yang bolong.
                throw $e;
            } catch (Exception $e) {
                // Satu batch gagal tidak boleh membatalkan seluruh analisis.
                // Batch yang gagal dicatat dan dilaporkan lewat metrics.
                Log::error("Batch {$batchNumber} failed: ".$e->getMessage());

                if ($this->wajibUtuh) {
                    throw new Exception("Batch sentimen {$batchNumber} dari {$totalBatches} gagal: ".$e->getMessage(), 0, $e);
                }

                $failedBatches[] = [
                    'batch' => $batchNumber,
                    'texts' => count($batch),
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (empty($allPredictions)) {
            throw new Exception(
                'Analisis sentimen gagal: seluruh '.$totalBatches.' batch tidak berhasil diproses.'
            );
        }

        $distribution = $this->calculateSentimentDistribution($allPredictions);
        $metrics = $this->calculateSentimentMetrics($allPredictions, $distribution, $totalBatches, $failedBatches);
        $summary = $this->generateSentimentSummary($metrics, $distribution);

        $results = [
            'predictions' => $allPredictions,
            'distribution' => $distribution,
            'metrics' => $metrics,
            'summary' => $summary,
        ];

        // review_queue tiap batch memakai indeks lokal batch itu, jadi tidak
        // bisa digabung begitu saja - harus disusun ulang memakai indeks teks asli.
        $reviewQueue = $this->buildReviewQueue($allPredictions, $reviewThreshold);

        if ($reviewQueue !== null) {
            $results['review_queue'] = $reviewQueue;
        }

        return [
            'status' => 'success',
            'results' => $results,
        ];
    }

    /**
     * Susun ulang antrean tinjauan untuk hasil gabungan antar-batch.
     *
     * Mengikuti aturan _build_review_queue() di sisi Python: hanya baris yang
     * bisa dinilai, keyakinan di bawah ambang, diurutkan dari yang paling tidak
     * yakin, dan dibatasi 200 indeks. Indeks mengacu ke posisi teks masukan.
     *
     * Mengembalikan null bila API tidak menyertakan ambang - lebih baik tidak
     * menampilkan antrean daripada mengarang ambang sendiri.
     */
    private function buildReviewQueue(array $predictions, ?float $threshold): ?array
    {
        if ($threshold === null) {
            Log::info('review_queue dilewati: API tidak mengirim threshold');

            return null;
        }

        $scorable = $this->scorablePredictions($predictions);

        if (empty($scorable)) {
            return null;
        }

        $flagged = [];

        // Ditelusuri dari $predictions (bukan $scorable) supaya posisi cadangan
        // tetap benar - scorablePredictions() mengindeks ulang hasilnya.
        foreach ($predictions as $position => $prediction) {
            if (($prediction['method'] ?? null) === 'empty') {
                continue;
            }

            $confidence = (float) ($prediction['confidence'] ?? 0.0);

            if ($confidence < $threshold) {
                $flagged[] = [
                    'index' => $prediction['original_index'] ?? $position,
                    'confidence' => $confidence,
                ];
            }
        }

        usort($flagged, fn ($a, $b) => $a['confidence'] <=> $b['confidence']);

        return [
            'threshold' => $threshold,
            'count' => count($flagged),
            'share' => round(count($flagged) / count($scorable), 4),
            'indices' => array_map(fn ($row) => $row['index'], array_slice($flagged, 0, 200)),
        ];
    }

    /**
     * Hitung distribusi sentimen sebagai PERSENTASE, sama seperti yang
     * dikembalikan endpoint Python untuk request tunggal.
     *
     * Sebelumnya kode ini menjumlahkan persentase antar-batch seolah-olah count,
     * sehingga dataset >batch_size menghasilkan total ratusan persen.
     */
    /**
     * Saring baris yang tidak punya isi untuk dinilai.
     *
     * Service Python menandainya `method: 'empty'` dan sudah mengeluarkannya
     * dari distribusi. Jalur batch di sini harus melakukan hal yang sama, atau
     * dataset besar (>batchSize) melaporkan persentase yang berbeda dari
     * dataset kecil pada data yang sama - baris kosong akan menggelembungkan
     * kategori netral.
     */
    private function scorablePredictions(array $predictions): array
    {
        return array_values(array_filter(
            $predictions,
            fn ($prediction) => ($prediction['method'] ?? null) !== 'empty'
        ));
    }

    private function calculateSentimentDistribution(array $predictions): array
    {
        $counts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];

        foreach ($this->scorablePredictions($predictions) as $prediction) {
            $label = strtolower((string) ($prediction['sentiment'] ?? 'neutral'));

            if (array_key_exists($label, $counts)) {
                $counts[$label]++;
            }
        }

        $total = max(1, array_sum($counts));

        return [
            'positive' => round(($counts['positive'] / $total) * 100, 2),
            'neutral' => round(($counts['neutral'] / $total) * 100, 2),
            'negative' => round(($counts['negative'] / $total) * 100, 2),
        ];
    }

    private function calculateSentimentMetrics(
        array $predictions,
        array $distribution,
        int $totalBatches = 0,
        array $failedBatches = []
    ): array {
        $scorable = $this->scorablePredictions($predictions);

        $confidences = array_filter(
            array_map(fn ($prediction) => $prediction['confidence'] ?? null, $scorable),
            fn ($confidence) => $confidence !== null
        );

        // Pemotongan dan kegagalan dilaporkan, bukan dibiarkan senyap: keduanya
        // menurunkan mutu hasil tanpa memunculkan galat apa pun ke pengguna.
        $truncated = count(array_filter(
            $predictions,
            fn ($prediction) => ! empty($prediction['truncated'])
        ));
        $failed = count(array_filter(
            $predictions,
            fn ($prediction) => ($prediction['method'] ?? null) === 'error'
        ));

        $metrics = [
            'total_texts' => count($predictions),
            'total_analyzed' => count($scorable),
            'total_empty' => count($predictions) - count($scorable),
            'total_truncated' => $truncated,
            'total_failed' => $failed,
            'positive_percentage' => $distribution['positive'],
            'neutral_percentage' => $distribution['neutral'],
            'negative_percentage' => $distribution['negative'],
            'avg_confidence' => $confidences ? round(array_sum($confidences) / count($confidences), 4) : 0.0,
            'min_confidence' => $confidences ? round(min($confidences), 4) : 0.0,
            'max_confidence' => $confidences ? round(max($confidences), 4) : 0.0,
        ];

        if (! empty($failedBatches)) {
            $metrics['failed_batches'] = $failedBatches;
            $metrics['batch_summary'] = sprintf(
                '%d dari %d batch berhasil diproses.',
                $totalBatches - count($failedBatches),
                $totalBatches
            );
        }

        return $metrics;
    }

    /**
     * Ekstraksi Aspek dengan batch processing - FIXED untuk Python AspectService
     */
    public function analyzeAspect(
        array $texts,
        array $config = [],
        ?array $predefinedAspects = null,
        string $mode = 'automatic',
        $progressCallback = null
    ): array {
        try {
            if (count($texts) <= $this->batchSize) {
                return $this->executeAspectAnalysis($texts, $config, $predefinedAspects, $mode);
            }

            return $this->batchAspectAnalysis($texts, $config, $predefinedAspects, $mode, $progressCallback);

        } catch (Exception $e) {
            Log::error('Aspect Analysis Error: '.$e->getMessage());
            throw $e;
        }
    }

    private function executeAspectAnalysis(
        array $texts,
        array $config,
        ?array $predefinedAspects,
        string $mode
    ): array {
        $payload = [
            'texts' => $texts,
            'preprocessing_config' => $config,
            'mode' => $mode,
        ];

        if ($predefinedAspects) {
            $payload['predefined_aspects'] = $predefinedAspects;
        }

        $response = $this->kirimAnalisis('/api/analyze/aspect', $payload, $this->timeout, 3, 1000);

        if ($response->successful()) {
            $result = $response->json();

            if (! isset($result['status']) || $result['status'] !== 'success') {
                throw new Exception('Invalid response from NLP API');
            }

            return $result;
        }

        throw new Exception('Aspect analysis failed: '.$response->body());
    }

    /**
     * Batch aspect analysis - FIXED untuk Python AspectService format
     *
     * Python AspectService returns:
     * {
     *   'status': 'success',
     *   'results': {
     *     'aspect_sentiments': [
     *       {'aspect': 'harga', 'count': 10, 'sentiments': {'positive': 60, 'neutral': 20, 'negative': 20}}
     *     ]
     *   }
     * }
     */
    private function batchAspectAnalysis(
        array $texts,
        array $config,
        ?array $predefinedAspects,
        string $mode,
        $progressCallback = null
    ): array {
        $batches = array_chunk($texts, $this->batchSize, true);
        $totalBatches = count($batches);

        // Aggregated aspect data
        $aggregatedAspects = [];
        // Aspek per dokumen dari Python, dipetakan ke indeks teks aslinya.
        // Dipakai untuk mengisi training_items tanpa menebak lewat keyword.
        $documentAspects = array_fill(0, count($texts), []);

        // Bentuk dasar tiap aspek, sejajar indeks dengan $documentAspects.
        // WAJIB ikut dikumpulkan di jalur batch. Sebelumnya hanya bentuk
        // permukaan yang dirakit, sehingga korpus di atas batch_size (50)
        // kehilangan `document_aspects_normalized` sama sekali - dan evaluasi
        // hold-out, yang golden dataset-nya 300 baris, SELALU melewati jalur
        // ini. Akibatnya metrik aspek tidak bisa dihitung sama sekali.
        $documentAspectsNormalized = array_fill(0, count($texts), []);
        $adaNormalisasi = false;
        $failedBatches = [];

        Log::info("Processing {$totalBatches} batches for aspect analysis", [
            'total_texts' => count($texts),
            'batch_size' => $this->batchSize,
            'mode' => $mode,
        ]);

        foreach ($batches as $index => $batch) {
            $batchNumber = $index + 1;

            Log::info("Processing batch {$batchNumber}/{$totalBatches}");

            if ($progressCallback) {
                $progress = 40 + (($batchNumber / $totalBatches) * 30);
                call_user_func($progressCallback, $progress, "Mengekstrak aspek batch {$batchNumber}/{$totalBatches}...");
            }

            $originalIndexes = array_keys($batch);

            try {
                $result = $this->executeAspectAnalysis(array_values($batch), $config, $predefinedAspects, $mode);

                if (isset($result['results']['document_aspects']) && is_array($result['results']['document_aspects'])) {
                    foreach ($result['results']['document_aspects'] as $position => $aspectsOfDocument) {
                        if (isset($originalIndexes[$position])) {
                            $documentAspects[$originalIndexes[$position]] = array_values((array) $aspectsOfDocument);
                        }
                    }
                }

                // Dipetakan lewat $originalIndexes yang sama persis, supaya
                // bentuk dasar dan bentuk permukaan menunjuk dokumen yang sama.
                if (isset($result['results']['document_aspects_normalized'])
                    && is_array($result['results']['document_aspects_normalized'])) {
                    $adaNormalisasi = true;
                    foreach ($result['results']['document_aspects_normalized'] as $position => $aspectsOfDocument) {
                        if (isset($originalIndexes[$position])) {
                            $documentAspectsNormalized[$originalIndexes[$position]] = array_values((array) $aspectsOfDocument);
                        }
                    }
                }

                // Log untuk debugging
                Log::info("Batch {$batchNumber} response structure", [
                    'status' => $result['status'] ?? 'unknown',
                    'has_results' => isset($result['results']),
                    'result_keys' => isset($result['results']) ? array_keys($result['results']) : [],
                ]);

                if (isset($result['results']['aspect_sentiments']) && is_array($result['results']['aspect_sentiments'])) {
                    $batchAspects = $result['results']['aspect_sentiments'];

                    Log::info("Batch {$batchNumber} found aspects", [
                        'count' => count($batchAspects),
                        'sample' => array_slice($batchAspects, 0, 2),
                    ]);

                    // Aggregate aspects
                    foreach ($batchAspects as $aspectData) {
                        // Validate aspect data structure
                        if (! isset($aspectData['aspect']) || ! isset($aspectData['count'])) {
                            Log::warning('Invalid aspect data', ['data' => $aspectData]);

                            continue;
                        }

                        $aspectName = $aspectData['aspect'];
                        $count = $aspectData['count'];
                        $sentiments = $aspectData['sentiments'] ?? ['positive' => 0, 'neutral' => 0, 'negative' => 0];

                        if (! isset($aggregatedAspects[$aspectName])) {
                            $aggregatedAspects[$aspectName] = [
                                'aspect' => $aspectName,
                                'count' => 0,
                                'positive' => 0,
                                'neutral' => 0,
                                'negative' => 0,
                            ];
                        }

                        // Aggregate count
                        $aggregatedAspects[$aspectName]['count'] += $count;

                        // sentiments dari Python adalah percentages (e.g. 60.5)
                        // Convert ke counts untuk aggregasi
                        $positiveCount = round(($sentiments['positive'] / 100) * $count);
                        $neutralCount = round(($sentiments['neutral'] / 100) * $count);
                        $negativeCount = round(($sentiments['negative'] / 100) * $count);

                        $aggregatedAspects[$aspectName]['positive'] += $positiveCount;
                        $aggregatedAspects[$aspectName]['neutral'] += $neutralCount;
                        $aggregatedAspects[$aspectName]['negative'] += $negativeCount;
                    }
                } else {
                    Log::warning("Batch {$batchNumber} has no aspect_sentiments in results", [
                        'available_keys' => isset($result['results']) ? array_keys($result['results']) : [],
                    ]);
                }

                usleep(100000);

            } catch (NlpBusyException $e) {
                throw $e;
            } catch (Exception $e) {
                Log::error("Batch {$batchNumber} failed: ".$e->getMessage());

                if ($this->wajibUtuh) {
                    throw new Exception("Batch aspek {$batchNumber} dari {$totalBatches} gagal: ".$e->getMessage(), 0, $e);
                }

                $failedBatches[] = [
                    'batch' => $batchNumber,
                    'texts' => count($batch),
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (empty($aggregatedAspects) && count($failedBatches) === $totalBatches) {
            throw new Exception(
                'Ekstraksi aspek gagal: seluruh '.$totalBatches.' batch tidak berhasil diproses.'
            );
        }

        Log::info('Aggregation complete', [
            'total_aspects' => count($aggregatedAspects),
            'aspects' => array_keys($aggregatedAspects),
        ]);

        // Convert to final format
        $finalAspects = [];
        foreach ($aggregatedAspects as $aspectData) {
            $total = $aspectData['count'];

            if ($total == 0) {
                Log::warning('Skipping aspect with zero count', ['aspect' => $aspectData['aspect']]);

                continue;
            }

            // Calculate percentages
            $finalAspects[] = [
                'aspect' => $aspectData['aspect'],
                'count' => $total,
                'sentiments' => [
                    'positive' => round(($aspectData['positive'] / $total) * 100, 1),
                    'neutral' => round(($aspectData['neutral'] / $total) * 100, 1),
                    'negative' => round(($aspectData['negative'] / $total) * 100, 1),
                ],
            ];
        }

        // Sort by count (descending)
        usort($finalAspects, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        // Generate summary
        $summary = count($finalAspects) > 0
            ? $this->generateAspectSummary($finalAspects)
            : 'Tidak ada aspek yang teridentifikasi dari analisis.';

        $results = [
            'aspect_sentiments' => $finalAspects,
            'document_aspects' => $documentAspects,
            'summary' => $summary,
        ];

        // Hanya disertakan bila layanan benar-benar mengirimnya. Mengirim
        // larik kosong akan membuat evaluasi mengira normalisasi tersedia lalu
        // membandingkan label emas dengan daftar kosong - nol untuk semua
        // metrik aspek, tanpa satu pun tanda bahwa penyebabnya versi layanan.
        if ($adaNormalisasi) {
            $results['document_aspects_normalized'] = $documentAspectsNormalized;
        }

        if (! empty($failedBatches)) {
            $results['failed_batches'] = $failedBatches;
            $results['batch_summary'] = sprintf(
                '%d dari %d batch berhasil diproses.',
                $totalBatches - count($failedBatches),
                $totalBatches
            );
        }

        return [
            'status' => 'success',
            'results' => $results,
        ];
    }

    public function analyzeTopic(array $texts, array $config = [], int $numTopics = 5): array
    {
        try {
            // Model topik tidak dilatih lewat API, jadi layanan tidak pernah
            // menolak jalur ini - tetap lewat kirimAnalisis demi satu aturan.
            $response = $this->kirimAnalisis('/api/analyze/topic', [
                'texts' => $texts,
                'preprocessing_config' => $config,
                'num_topics' => $numTopics,
            ], (int) config('services.nlp_api.topic_timeout', 2400), 2, 2000);

            if ($response->successful()) {
                $result = $response->json();

                if (! isset($result['status']) || $result['status'] !== 'success') {
                    throw new Exception('Invalid response from NLP API');
                }

                return $result;
            }

            throw new Exception('Topic analysis failed: '.$response->body());
        } catch (Exception $e) {
            Log::error('Topic Analysis Error: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Analisis gabungan (sentimen + aspek + topik + asosiasi).
     *
     * $predefinedAspects/$mode dulu tidak ada di sini, dan KEDUA jalur memaksa
     * mode 'automatic'. Akibatnya daftar aspek yang dideklarasikan pengguna
     * dibuang diam-diam pada tipe analisis 'combined' - padahal formulir
     * menampilkan pilihan mode aspek untuk tipe itu juga.
     */
    public function analyzeCombined(
        array $texts,
        array $config = [],
        $progressCallback = null,
        ?array $predefinedAspects = null,
        string $mode = 'automatic',
        int $numTopics = 5
    ): array {
        try {
            Log::info('Starting combined analysis for '.count($texts).' texts');

            if (count($texts) <= $this->batchSize) {
                return $this->executeCombinedAnalysis($texts, $config, $predefinedAspects, $mode, $numTopics);
            }

            $sentimentResult = $this->analyzeSentiment($texts, $config, function ($progress, $message) use ($progressCallback) {
                if ($progressCallback) {
                    call_user_func($progressCallback, 40 + ($progress - 40) * 0.33, $message);
                }
            });

            $aspectResult = $this->analyzeAspect($texts, $config, $predefinedAspects, $mode, function ($progress, $message) use ($progressCallback) {
                if ($progressCallback) {
                    call_user_func($progressCallback, 50 + ($progress - 40) * 0.33, $message);
                }
            });

            if ($progressCallback) {
                call_user_func($progressCallback, 60, 'Mengidentifikasi topik...');
            }

            $topicResult = $this->analyzeTopic($texts, $config, $numTopics);

            $sentiment = $sentimentResult['results'] ?? $sentimentResult;
            $aspect = $aspectResult['results'] ?? $aspectResult;
            $topic = $topicResult['results'] ?? $topicResult;

            // Endpoint /api/analyze/combined menghitung PMI aspek-topik sendiri,
            // tapi jalur batch ini memanggil ketiga analisis terpisah. Jadi PMI
            // diminta lewat endpoint association agar hasilnya tetap ada.
            if ($progressCallback) {
                call_user_func($progressCallback, 68, 'Menghitung asosiasi aspek-topik...');
            }

            $association = $this->analyzeAssociation(
                $aspect['document_aspects'] ?? [],
                $topic['document_topics'] ?? [],
                (int) ($topic['num_topics'] ?? 0)
            );

            return [
                'status' => 'success',
                'results' => [
                    'sentiment' => $sentiment,
                    'aspect' => $aspect,
                    'topic' => $topic,
                    'association' => $association,
                ],
            ];

        } catch (Exception $e) {
            Log::error('Combined Analysis Error: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Kirim data hasil koreksi user untuk melatih ulang model sentimen.
     * Payload mengikuti SentimentRetrainRequest: [{text, label}], minimal 10 sampel.
     */
    public function retrainSentiment(array $trainingData, int $epochs = 3, float $learningRate = 0.00002): array
    {
        return $this->executeRetrain('sentiment', $trainingData, $epochs, $learningRate);
    }

    /**
     * Kirim data hasil koreksi user untuk melatih ulang model ekstraksi aspek.
     * Payload mengikuti AspectRetrainRequest: [{text, aspects: []}], minimal 10 sampel.
     */
    public function retrainAspect(array $trainingData, int $epochs = 3, float $learningRate = 0.00002): array
    {
        return $this->executeRetrain('aspect', $trainingData, $epochs, $learningRate);
    }

    /**
     * Laporan kesiapan data latih TANPA melatih apa pun.
     *
     * Fine-tuning pada data yang sangat timpang bisa menghasilkan model yang
     * hanya menebak kelas mayoritas - dengan akurasi yang justru terlihat naik.
     * Karena itu komposisi datanya perlu terlihat sebelum tombol latih ditekan.
     *
     * Tidak ada batas minimal sampel di sini: justru data yang belum cukup pun
     * perlu bisa dilihat.
     */
    public function retrainPreview(string $modelType, array $trainingData): array
    {
        $response = $this->http($this->timeout)
            ->post("{$this->apiUrl}/api/retrain/preview", [
                'model_type' => $modelType,
                'training_data' => array_values($trainingData),
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        throw new Exception("Pemeriksaan data {$modelType} gagal: ".$response->body());
    }

    private function executeRetrain(
        string $type,
        array $trainingData,
        int $epochs,
        float $learningRate
    ): array {
        if (count($trainingData) < self::MIN_RETRAIN_SAMPLES) {
            throw new Exception(sprintf(
                'Data training %s belum cukup: %d sampel, minimal %d.',
                $type,
                count($trainingData),
                self::MIN_RETRAIN_SAMPLES
            ));
        }

        Log::info("Mengirim {$type} retraining ke NLP API", [
            'samples' => count($trainingData),
            'epochs' => $epochs,
        ]);

        // Timeout sendiri, bukan kelipatan timeout analisis: lihat
        // services.nlp_api.retrain_timeout. Klien yang menyerah lebih dulu
        // tidak menghentikan pelatihan di layanan, hanya membuat catatannya
        // salah.
        $response = $this->http((int) config('services.nlp_api.retrain_timeout', 6600))
            ->post("{$this->apiUrl}/api/retrain/{$type}", [
                'training_data' => array_values($trainingData),
                'epochs' => $epochs,
                'learning_rate' => $learningRate,
            ]);

        if ($response->successful()) {
            $result = $response->json();

            if (($result['status'] ?? null) !== 'success') {
                throw new Exception("Retraining {$type} ditolak NLP API: ".$response->body());
            }

            return $result['results'] ?? [];
        }

        // 409 berarti layanan sedang melatih model yang sama. Sejak lock
        // ditambahkan di sisi Python, ini keadaan yang wajar - bukan kerusakan.
        if ($response->status() === 409) {
            throw new RetrainBusyException(
                "Layanan NLP sedang melatih model {$type}. Pelatihan ini dilewati."
            );
        }

        throw new Exception("Retraining {$type} gagal: ".$response->body());
    }

    /**
     * Hitung asosiasi aspek-topik (PMI) untuk hasil yang sudah dianalisis.
     * Mengembalikan null (bukan exception) kalau data belum lengkap atau
     * endpoint tidak tersedia — asosiasi bersifat pelengkap, bukan inti analisis.
     */
    public function analyzeAssociation(
        array $documentAspects,
        array $documentTopics,
        int $numTopics,
        int $minMentions = 3
    ): ?array {
        if (empty($documentAspects) || empty($documentTopics) || $numTopics <= 0) {
            Log::info('Association analysis dilewati: document_aspects/document_topics tidak lengkap');

            return null;
        }

        if (count($documentAspects) !== count($documentTopics)) {
            Log::warning('Association analysis dilewati: jumlah dokumen tidak sama', [
                'aspects' => count($documentAspects),
                'topics' => count($documentTopics),
            ]);

            return null;
        }

        try {
            $response = $this->http($this->timeout)
                ->post("{$this->apiUrl}/api/analyze/association", [
                    'document_aspects' => array_values($documentAspects),
                    'document_topics' => array_values($documentTopics),
                    'num_topics' => $numTopics,
                    'min_mentions' => $minMentions,
                ]);

            if ($response->successful()) {
                return $response->json()['results'] ?? null;
            }

            Log::warning('Association analysis failed: '.$response->body());

            return null;

        } catch (Exception $e) {
            Log::warning('Association analysis error: '.$e->getMessage());

            return null;
        }
    }

    private function executeCombinedAnalysis(
        array $texts,
        array $config,
        ?array $predefinedAspects = null,
        string $mode = 'automatic',
        int $numTopics = 5
    ): array {
        // num_topics wajib ikut: tanpa ini endpoint combined memakai nilai
        // bawaannya (5) sehingga pilihan pengguna - termasuk mode otomatis (0) -
        // diabaikan diam-diam pada analisis gabungan.
        $payload = [
            'texts' => $texts,
            'preprocessing_config' => $config,
            'mode' => $mode,
            'num_topics' => $numTopics,
        ];

        // Mode rule-based tanpa daftar aspek tidak ada artinya bagi Python.
        if ($mode === 'rule-based' && $predefinedAspects) {
            $payload['predefined_aspects'] = $predefinedAspects;
        }

        $response = $this->kirimAnalisis('/api/analyze/combined', $payload, $this->timeout * 2, 2, 2000);

        if ($response->successful()) {
            $result = $response->json();

            if (! isset($result['status']) || $result['status'] !== 'success') {
                throw new Exception('Invalid response from NLP API');
            }

            return $result;
        }

        throw new Exception('Combined analysis failed: '.$response->body());
    }

    private function generateSentimentSummary(array $metrics, array $distribution): string
    {
        $dominant = array_keys($distribution, max($distribution))[0];
        $percentage = $metrics["{$dominant}_percentage"];

        return "Dari {$metrics['total_texts']} teks yang dianalisis, mayoritas ({$percentage}%) memiliki sentimen {$dominant}.";
    }

    private function generateAspectSummary(array $aspectResults): string
    {
        if (empty($aspectResults)) {
            return 'Tidak ada aspek yang teridentifikasi.';
        }

        $topAspects = array_slice($aspectResults, 0, 3);
        $aspectNames = array_column($topAspects, 'aspect');

        $totalMentions = array_sum(array_column($aspectResults, 'count'));

        return 'Teridentifikasi '.count($aspectResults)." aspek dari {$totalMentions} mentions. ".
               'Aspek utama: '.implode(', ', $aspectNames).'.';
    }
}
