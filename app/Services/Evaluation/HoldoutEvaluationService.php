<?php

namespace App\Services\Evaluation;

use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use App\Models\TextAnalysis;
use App\Services\NLPApiService;
use App\Services\PreprocessingConfigResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Menjalankan golden dataset terhadap model yang sedang aktif.
 *
 * Inilah satu-satunya angka yang layak dikutip sebagai kinerja model di
 * naskah: baris-baris ini tidak pernah ikut dilatih, dan isinya tidak berubah
 * antar iterasi sehingga selisih antar run benar-benar berasal dari modelnya.
 *
 * Dua hal yang dijaga ketat:
 *
 * 1. Preprocessing yang dipakai sama persis dengan yang dipakai pipeline
 *    analisis (PreprocessingConfigResolver). Evaluasi dengan pembersihan
 *    berbeda mengukur sistem yang tidak pernah dijalankan siapa pun.
 * 2. Identitas model saat run dicatat dari /health. Tanpa itu, dua run yang
 *    dibandingkan bisa saja berasal dari bobot yang berbeda tanpa ada
 *    jejaknya.
 */
class HoldoutEvaluationService
{
    public function __construct(
        private NLPApiService $nlpApi,
        private PreprocessingConfigResolver $resolver,
        private ClassificationMetrics $metrics,
    ) {}

    /**
     * @param  array{label?: string, iteration?: int, training?: ModelTraining|null,
     *               triggered_by?: int|null, with_aspects?: bool, config_id?: int|null}  $opsi
     */
    public function run(EvaluationDataset $dataset, array $opsi = []): EvaluationRun
    {
        if ($dataset->items()->count() === 0) {
            throw new RuntimeException('Golden dataset kosong.');
        }

        if (! $dataset->isFrozen()) {
            throw new RuntimeException(
                'Dataset belum dibekukan. Bekukan lebih dulu supaya hasil antar iterasi bisa dibandingkan.'
            );
        }

        $training = $opsi['training'] ?? $this->pelatihanTerakhir();
        $config = $this->resolver->resolve($opsi['config_id'] ?? null);

        $run = EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'model_training_id' => $training?->id,
            'iteration' => $opsi['iteration'] ?? $this->nextIteration($dataset),
            'label' => $opsi['label'] ?? null,
            'status' => 'running',
            'item_count' => $dataset->items()->count(),
            'preprocessing_config' => $config,
            'model_info' => $this->modelInfo(),
            'triggered_by' => $opsi['triggered_by'] ?? null,
            'started_at' => now(),
        ]);

        try {
            $items = $dataset->items()->orderBy('id')->get();
            $texts = $items->pluck('text')->map(fn ($t) => (string) $t)->all();

            $prediksiSentimen = $this->predictSentiment($texts, $config);

            // is_array, bukan ! empty: baris yang dianotasi TANPA aspek (`[]`)
            // wajib ikut dinilai. Kalau hanya baris beraspek yang dihitung,
            // aspek yang dikarang model pada komentar yang sebenarnya tidak
            // membicarakan apa pun tidak pernah terhitung sebagai false
            // positive, dan presisi yang dilaporkan menjadi terlalu tinggi.
            $berAspek = $items->filter(fn ($item) => is_array($item->gold_aspects));

            // Aspek hanya dinilai bila label emasnya sudah punya bentuk dasar.
            // Tanpa itu, `pelayanan` (tulisan anotator) dibandingkan dengan
            // `pelayanannya` (bentuk permukaan dari model) sebagai string
            // persis, dan tiap pasangan yang BENAR dihitung dua kali salah -
            // sekali sebagai false positive, sekali sebagai false negative.
            // Terukur: micro-F1 0,118 padahal seharusnya 0,941. Lebih baik
            // tidak ada angka aspek daripada angka yang salah telak.
            // Metrik aspek menuntut SELURUH baris sudah ditinjau, bukan
            // sebagian. Menghitung atas subhimpunan yang ditinjau membuat aspek
            // karangan model pada baris sisanya tak terhitung - masalah yang
            // sama persis dengan yang diperbaiki di atas, hanya berpindah dari
            // "baris tanpa aspek" ke "baris belum ditinjau".
            $semuaDitinjau = $berAspek->count() === $items->count();

            $emasSiap = $berAspek->isNotEmpty()
                && $semuaDitinjau
                && $berAspek->every(fn ($item) => is_array($item->gold_aspects_normalized));

            $perluAspek = ($opsi['with_aspects'] ?? true) && $emasSiap;

            $prediksiAspek = $perluAspek
                ? $this->predictAspects($texts, $config)
                : ['aspects' => [], 'normalized' => false];

            $this->storeRunItems($run, $items, $prediksiSentimen, $prediksiAspek);

            $pasanganSentimen = [];
            $pasanganAspek = [];

            foreach ($items as $posisi => $item) {
                $pasanganSentimen[] = [
                    'gold' => (string) $item->gold_sentiment,
                    'predicted' => $prediksiSentimen[$posisi]['sentiment'] ?? null,
                ];

                if ($perluAspek && is_array($item->gold_aspects_normalized)) {
                    // Kedua sisi dalam bentuk dasar. Nilai stem-nya sendiri
                    // tidak harus sama dengan bentuk dasar yang ditulis manusia
                    // (`pelayanan` -> `layan`); yang wajib benar adalah keduanya
                    // mendarat di token yang sama.
                    $pasanganAspek[] = [
                        'gold' => (array) $item->gold_aspects_normalized,
                        'predicted' => $prediksiAspek['aspects'][$posisi] ?? [],
                    ];
                }
            }

            $metrik = [
                'sentiment' => $this->metrics->sentiment(
                    $pasanganSentimen,
                    (int) config('analysis.evaluation.bootstrap_rounds', 1000),
                    (int) config('analysis.evaluation.bootstrap_seed', 42),
                ),
                'aspect' => $this->aspectMetrics(
                    $perluAspek,
                    $berAspek,
                    $emasSiap,
                    $pasanganAspek,
                    $prediksiAspek,
                    $items->count() - $berAspek->count(),
                ),
                'annotation' => $dataset->annotation,
                'dataset' => [
                    'id' => $dataset->id,
                    'name' => $dataset->name,
                    'item_count' => $dataset->item_count,
                    'frozen_at' => $dataset->frozen_at?->toIso8601String(),
                ],
            ];

            $metrik['segment'] = $this->perSegment($items, $prediksiSentimen);

            // Tabel reliabilitas pada golden dataset sendiri. Ambang tinjauan
            // menyatakan "tinjau bila perkiraan salahnya di atas X" - klaim itu
            // hanya sah bila keyakinan model memang terkalibrasi, dan di sinilah
            // hal itu diuji pada korpus studi kasus, bukan pada SmSA.
            $metrik['reliability'] = $this->reliability($items, $prediksiSentimen);

            if ($opsi['with_topic'] ?? true) {
                $metrik['topic'] = $this->topicQuality(
                    $opsi['topic_corpus_ids'] ?? ($dataset->sampling_plan['analysis_ids'] ?? [])
                );
            }

            $run->update([
                'status' => 'completed',
                'metrics' => $metrik,
                'completed_at' => now(),
            ]);

            return $run->fresh();
        } catch (Throwable $e) {
            Log::error('Evaluasi hold-out gagal: '.$e->getMessage());

            $run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Tabel reliabilitas, dengan pita yang sama persis dengan yang dipakai
     * halaman hasil - supaya angka di halaman evaluasi bisa langsung dipakai
     * membenarkan (atau menyanggah) ambang yang sedang berlaku.
     */
    private function reliability($items, array $prediksi): ?array
    {
        $laporan = app(\App\Services\ConfidenceReportService::class);
        $batas = $laporan->bandBounds();

        $baris = [];

        foreach ($items as $posisi => $item) {
            $p = $prediksi[$posisi] ?? null;

            if (! $p || $p['sentiment'] === null) {
                continue;
            }

            $baris[] = [
                'confidence' => $p['confidence'],
                'correct' => $p['sentiment'] === strtolower((string) $item->gold_sentiment),
            ];
        }

        return $this->metrics->reliability($baris, [0.5, $batas['sedang'], $batas['tinggi']]);
    }

    /**
     * Rincian akurasi per segmen (mis. per lembaga).
     *
     * Berguna untuk studi kasus: model bisa saja baik secara keseluruhan
     * tetapi jatuh pada satu lembaga tertentu, dan rerata menyembunyikannya.
     * Segmen bersisi sedikit ikut ditampilkan jumlah barisnya supaya tidak
     * disimpulkan berlebihan.
     */
    private function perSegment($items, array $prediksi): ?array
    {
        $bersegmen = $items->filter(fn ($item) => filled($item->segment));

        if ($bersegmen->isEmpty()) {
            return null;
        }

        $kelompok = [];

        foreach ($items as $posisi => $item) {
            if (! filled($item->segment)) {
                continue;
            }

            $kelompok[$item->segment][] = [
                'gold' => (string) $item->gold_sentiment,
                'predicted' => $prediksi[$posisi]['sentiment'] ?? null,
            ];
        }

        ksort($kelompok);
        $hasil = [];

        foreach ($kelompok as $segmen => $pasangan) {
            // Bootstrap dimatikan per segmen: 1000 putaran dikali puluhan
            // segmen mahal, dan pada n sekecil itu selangnya toh terlalu lebar
            // untuk dipakai menyimpulkan apa pun.
            $skor = $this->metrics->sentiment($pasangan, 0);

            $hasil[$segmen] = [
                'n' => $skor['n'] ?? 0,
                'accuracy' => $skor['accuracy'] ?? null,
                'macro_f1' => $skor['macro_f1'] ?? null,
                'accuracy_ci' => $skor['accuracy_ci'] ?? null,
            ];
        }

        return $hasil;
    }

    /**
     * Mutu pemodelan topik pada KORPUS STUDI KASUS, bukan pada test set.
     *
     * Sempat dihitung dengan menjalankan pemodelan topik atas teks golden
     * dataset. Itu keliru, dan selisihnya besar: pada korpus yang sama dan
     * konfigurasi yang sama, 300 teks memberi c_v 0,3597 sementara 1.139 teks
     * memberi 0,4498 - melintasi batas kategori penafsiran sistem sendiri, dari
     * "lemah" ke "baik untuk teks pendek". Sebabnya dua hal: golden dataset
     * adalah sampel acak sehingga kalimat pembentuk satu topik sebagian besar
     * tidak ikut tersampel, dan c_v dihitung dari ko-okurensi kata yang menjadi
     * jarang pada 300 dokumen pendek.
     *
     * Karena koherensi memang tidak butuh label emas, tidak ada alasan
     * membatasinya ke baris yang dianotasi. Yang dibaca di sini adalah angka
     * yang BENAR-BENAR dihasilkan sistem untuk pengguna: blok `quality` yang
     * tersimpan pada hasil analisis korpusnya. Tidak ada permintaan baru ke
     * layanan - selain lebih murah, itu menjamin angkanya sama persis dengan
     * yang tampil di halaman hasil.
     *
     * Model topik tidak ikut dilatih ulang oleh active learning, jadi angka ini
     * berperan sebagai kontrol: bila F1 naik sementara koherensi diam,
     * kenaikannya memang dari pelatihan ulang.
     */
    private function topicQuality(array $analysisIds): ?array
    {
        if (empty($analysisIds)) {
            return [
                'available' => false,
                'message' => 'Korpus sumber tidak diketahui. Golden dataset yang diambil lewat menu Ambil Sampel '
                    .'membawa daftar analisis sumbernya sendiri; untuk dataset hasil impor, sebutkan lewat opsi korpus.',
            ];
        }

        $analyses = TextAnalysis::with('result')->whereIn('id', $analysisIds)->get();
        $terpakai = [];
        $cv = [];
        $npmi = [];
        $diversity = [];
        $outlier = [];
        $jumlahTopik = 0;
        $jumlahTeks = 0;

        foreach ($analyses as $analysis) {
            $quality = $analysis->result?->topic_results['quality'] ?? null;

            if (! is_array($quality) || ! isset($quality['c_v'])) {
                continue;
            }

            $terpakai[] = ['id' => $analysis->id, 'title' => $analysis->title, 'c_v' => round((float) $quality['c_v'], 4)];
            $cv[] = (float) $quality['c_v'];

            foreach (['c_npmi' => &$npmi, 'diversity' => &$diversity, 'outlier_rate' => &$outlier] as $kunci => &$wadah) {
                if (isset($quality[$kunci])) {
                    $wadah[] = (float) $quality[$kunci];
                }
            }
            unset($wadah);

            $jumlahTopik += count($analysis->result?->topic_results['topics'] ?? []);
            $jumlahTeks += is_array($analysis->raw_data) ? count($analysis->raw_data) : 0;
        }

        if (empty($cv)) {
            return [
                'available' => false,
                'message' => 'Korpus sumber belum punya hasil pemodelan topik. Jalankan analisis bertipe topic atau '
                    .'combined atas korpus itu lebih dulu.',
            ];
        }

        $rerata = fn (array $nilai) => empty($nilai) ? null : round(array_sum($nilai) / count($nilai), 4);
        $cvRerata = $rerata($cv);

        return [
            'available' => true,
            'source' => 'korpus studi kasus (bukan test set)',
            'c_v' => $cvRerata,
            'c_v_label' => \App\Helpers\ResultPresenter::coherenceLabel($cvRerata),
            'c_npmi' => $rerata($npmi),
            'diversity' => $rerata($diversity),
            'outlier_rate' => $rerata($outlier),
            'topic_count' => $jumlahTopik,
            'corpus_texts' => $jumlahTeks,
            'analyses' => $terpakai,
        ];
    }

    /**
     * @return array<int, array{sentiment: ?string, confidence: ?float, method: ?string}>
     */
    private function predictSentiment(array $texts, array $config): array
    {
        $respons = $this->nlpApi->utuh()->analyzeSentiment($texts, $config);

        // Penjaga kedua, bila suatu saat jalur batch kembali memaafkan batch
        // yang gagal: hasil parsial ditolak di sini juga.
        $this->tolakBatchGagal('sentimen', $respons['results']['metrics']['failed_batches'] ?? []);

        $prediksi = $respons['results']['predictions'] ?? [];

        $hasil = [];

        foreach ($prediksi as $posisi => $baris) {
            // original_index ada pada jalur batch; pada jalur permintaan tunggal
            // urutan larik sudah benar. Mengandalkan urutan saja akan menggeser
            // seluruh pasangan emas-prediksi begitu korpusnya melewati 50 baris.
            $indeks = $baris['original_index'] ?? $posisi;

            $hasil[$indeks] = [
                'sentiment' => isset($baris['sentiment']) ? strtolower((string) $baris['sentiment']) : null,
                'confidence' => isset($baris['confidence']) ? (float) $baris['confidence'] : null,
                'method' => $baris['method'] ?? null,
            ];
        }

        ksort($hasil);

        // Setiap baris golden wajib punya prediksi yang sah. `method: error`
        // bukan prediksi: layanan mengisinya `neutral` dengan keyakinan 0 saat
        // teks itu gagal dinilai, dan menghitungnya berarti menilai model atas
        // tebakan yang tidak pernah dibuatnya.
        $tidakSah = [];

        foreach (array_keys($texts) as $indeks) {
            $p = $hasil[$indeks] ?? null;

            if ($p === null || $p['sentiment'] === null || $p['method'] === 'error') {
                $tidakSah[] = $indeks + 1;
            }
        }

        if ($tidakSah) {
            throw new RuntimeException(sprintf(
                'Evaluasi dibatalkan: %d dari %d baris tidak mendapat prediksi sentimen yang sah (baris ke-%s). '
                .'Hasil parsial tidak disimpan karena metriknya akan salah tanpa jejak. Jalankan ulang evaluasinya.',
                count($tidakSah),
                count($texts),
                implode(', ', array_slice($tidakSah, 0, 10)).(count($tidakSah) > 10 ? ', ...' : ''),
            ));
        }

        return $hasil;
    }

    /**
     * @param  array<int, array{batch?: int, texts?: int, error?: string}>  $gagal
     */
    private function tolakBatchGagal(string $jenis, array $gagal): void
    {
        if (empty($gagal)) {
            return;
        }

        $pertama = $gagal[0];

        throw new RuntimeException(sprintf(
            'Evaluasi dibatalkan: %d batch %s gagal (batch %s: %s). Hasil parsial tidak disimpan. Jalankan ulang evaluasinya.',
            count($gagal),
            $jenis,
            $pertama['batch'] ?? '?',
            $pertama['error'] ?? 'tanpa pesan',
        ));
    }

    /**
     * Aspek per dokumen dalam bentuk dasar.
     *
     * `document_aspects_normalized` dikirim berdampingan dengan bentuk
     * permukaan oleh layanan. Bentuk permukaan tetap yang ditampilkan ke
     * pengguna - `pelayanannya` memang kata yang ditulis penonton - tetapi
     * yang dibandingkan dengan label manusia harus bentuk dasar.
     *
     * @return array{aspects: array<int, array<int, string>>, normalized: bool}
     */
    private function predictAspects(array $texts, array $config): array
    {
        $respons = $this->nlpApi->utuh()->analyzeAspect($texts, $config);
        $hasil = $respons['results'] ?? [];

        // Jalur batch mengisi baris dari batch yang gagal dengan `[]`, yang
        // tidak bisa dibedakan dari "model tidak menemukan aspek". Recall yang
        // dihitung darinya salah tanpa jejak.
        $this->tolakBatchGagal('aspek', $hasil['failed_batches'] ?? []);

        $dokumenMentah = $hasil['document_aspects_normalized'] ?? $hasil['document_aspects'] ?? [];

        foreach (array_keys($texts) as $indeks) {
            if (! array_key_exists($indeks, $dokumenMentah)) {
                throw new RuntimeException(sprintf(
                    'Evaluasi dibatalkan: layanan mengembalikan aspek untuk %d dari %d baris. '
                    .'Hasil parsial tidak disimpan. Jalankan ulang evaluasinya.',
                    count($dokumenMentah),
                    count($texts),
                ));
            }
        }

        $ternormalisasi = isset($hasil['document_aspects_normalized']);
        $dokumen = $hasil['document_aspects_normalized'] ?? $hasil['document_aspects'] ?? [];

        if (! $ternormalisasi) {
            Log::warning('Layanan tidak mengirim document_aspects_normalized; metrik aspek dilewati.');
        }

        $daftar = [];

        foreach ($dokumen as $indeks => $aspek) {
            $baris = [];

            foreach ((array) $aspek as $satu) {
                // document_aspects pernah dikirim sebagai daftar string dan
                // sebagai daftar objek {aspect: ...}; keduanya masih beredar.
                $nama = is_array($satu) ? ($satu['aspect'] ?? null) : $satu;

                if (filled($nama)) {
                    $baris[] = mb_strtolower(trim((string) $nama));
                }
            }

            $daftar[$indeks] = array_values(array_unique($baris));
        }

        return ['aspects' => $daftar, 'normalized' => $ternormalisasi];
    }

    /**
     * Metrik aspek, atau penjelasan mengapa tidak dihitung.
     */
    private function aspectMetrics(
        bool $perlu,
        $berAspek,
        bool $emasSiap,
        array $pasangan,
        array $prediksi,
        int $belumDitinjau = 0,
    ): ?array {
        if ($berAspek->isEmpty()) {
            return null;
        }

        if ($belumDitinjau > 0) {
            return [
                'available' => false,
                'message' => "{$belumDitinjau} baris belum ditinjau aspeknya. Metrik aspek menuntut seluruh baris "
                    .'ditinjau - termasuk yang memang tidak punya aspek, yang ditandai "-" pada kolom aspects. '
                    .'Tanpa itu, aspek yang dikarang model pada baris yang belum ditinjau tidak pernah terhitung '
                    .'sebagai false positive dan presisinya jadi terlalu tinggi.',
            ];
        }

        if (! $emasSiap) {
            return [
                'available' => false,
                'message' => 'Label aspek emas belum punya bentuk dasar. Bekukan ulang dataset saat layanan NLP hidup, '
                    .'atau jalankan php artisan evaluation:normalize-aspects. Tanpa itu, angka aspek akan salah telak '
                    .'karena bentuk permukaan dibandingkan dengan bentuk dasar.',
            ];
        }

        if (! $perlu) {
            return null;
        }

        if (! ($prediksi['normalized'] ?? false)) {
            return [
                'available' => false,
                'message' => 'Layanan NLP tidak mengirim document_aspects_normalized. Perbarui nlp-api-service '
                    .'sebelum mengutip angka aspek apa pun.',
            ];
        }

        return $this->metrics->aspects($pasangan) + ['match' => 'bentuk dasar (stem Sastrawi) pada kedua sisi'];
    }

    private function storeRunItems(EvaluationRun $run, $items, array $sentimen, array $aspek): void
    {
        $baris = [];
        $daftarAspek = $aspek['aspects'] ?? [];

        foreach ($items as $posisi => $item) {
            $prediksi = $sentimen[$posisi]['sentiment'] ?? null;

            $baris[] = [
                'evaluation_run_id' => $run->id,
                'evaluation_item_id' => $item->id,
                'predicted_sentiment' => $prediksi,
                'confidence' => $sentimen[$posisi]['confidence'] ?? null,
                'predicted_aspects' => isset($daftarAspek[$posisi]) ? json_encode($daftarAspek[$posisi]) : null,
                'sentiment_correct' => $prediksi !== null
                    ? $prediksi === strtolower((string) $item->gold_sentiment)
                    : null,
                'method' => $sentimen[$posisi]['method'] ?? null,
            ];
        }

        foreach (array_chunk($baris, 200) as $potongan) {
            DB::table('evaluation_run_items')->insert($potongan);
        }
    }

    /**
     * Nomor iterasi berikutnya.
     *
     * Iterasi hanya maju bila modelnya BENAR-BENAR berubah - yaitu ada
     * pelatihan yang selesai setelah run terakhir. Mengukur ulang model yang
     * sama bukan iterasi baru.
     *
     * Dulu nomornya selalu bertambah setiap run. Akibatnya nyata: dua
     * pengukuran baseline berturut-turut - keduanya sebelum pelatihan apa pun,
     * angkanya identik sampai desimal terakhir - tercatat sebagai iterasi 0 dan
     * iterasi 1, sehingga kurva active learning menampilkan "iterasi tanpa
     * perbaikan" yang sebenarnya tidak pernah terjadi.
     */
    private function nextIteration(EvaluationDataset $dataset): int
    {
        $terakhir = $dataset->runs()->completed()
            ->orderByDesc('iteration')
            ->orderByDesc('id')
            ->first();

        if (! $terakhir) {
            return 0;
        }

        $sejak = $terakhir->completed_at ?? $terakhir->created_at;

        $adaPelatihanBaru = ModelTraining::where('status', 'completed')
            ->when($sejak, fn ($q) => $q->where('completed_at', '>', $sejak))
            ->exists();

        return (int) $terakhir->iteration + ($adaPelatihanBaru ? 1 : 0);
    }

    /**
     * Pelatihan yang menghasilkan bobot yang sedang diukur.
     *
     * Ditautkan otomatis supaya rantai "pelatihan -> pengukuran" terbaca tanpa
     * perlu mengingat urutannya, dan supaya `retrain:analysis` bisa menolak
     * menimpa bobot yang iterasinya belum sempat diukur.
     */
    private function pelatihanTerakhir(): ?ModelTraining
    {
        return ModelTraining::where('status', 'completed')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Identitas model saat run dijalankan, untuk reproduksibilitas.
     */
    private function modelInfo(): array
    {
        $health = $this->nlpApi->health();

        $info = [
            'sentiment_source' => $this->nlpApi->healthModelValue($health, 'sentiment_source'),
            'sentiment_base' => $this->nlpApi->healthModelValue($health, 'sentiment_base'),
            'sentiment_temperature' => $this->nlpApi->healthModelValue($health, 'sentiment_temperature'),
            'sentiment_review_threshold' => $this->nlpApi->healthModelValue($health, 'sentiment_review_threshold'),
            'sentiment_retrained' => $this->nlpApi->healthModelValue($health, 'sentiment_retrained'),
            'aspect_retrained' => $this->nlpApi->healthModelValue($health, 'aspect_retrained'),
            'checked_at' => now()->toIso8601String(),
        ];

        // Bobot mana yang diukur run ini. `aspect_retrained: true` tidak bisa
        // membedakan iterasi 1 dari iterasi 2, apalagi bobot yang dipulihkan;
        // sidik jari kolam bisa. Hanya disimpan bila layanan menyebutnya, supaya
        // "kunci ada, nilainya null" (bobot dasar) tetap berbeda dari "layanan
        // versi lama tidak melaporkannya" (ActiveModelService memakai aturan
        // cadangan untuk yang kedua).
        $models = is_array($health['models'] ?? null) ? $health['models'] : ($health ?? []);

        foreach ([
            'sentiment_pool_fingerprint', 'sentiment_pool_samples',
            'aspect_pool_fingerprint', 'aspect_pool_samples', 'aspect_restored_from',
        ] as $kunci) {
            if (array_key_exists($kunci, $models)) {
                $info[$kunci] = $models[$kunci];
            }
        }

        return $info;
    }
}
