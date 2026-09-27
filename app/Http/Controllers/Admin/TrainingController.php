<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomStopword;
use App\Models\EvaluationSnapshot;
use App\Models\ModelTraining;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Services\AutoRetrainService;
use App\Services\ModelEvaluationService;
use App\Services\NLPApiService;
use App\Services\RetrainDeferralService;
use App\Services\RetrainingService;
use App\Services\TrainingItemService;
use Illuminate\Http\Request;

class TrainingController extends Controller
{
    public function __construct(
        private TrainingItemService $trainingItemService,
        private ModelEvaluationService $modelEvaluationService
    ) {}

    /**
     * HALAMAN 1: INDEX (DASHBOARD)
     */
    public function index(AutoRetrainService $autoRetrain, RetrainDeferralService $tundaService)
    {
        // 1. Hitung Statistik Global dari TrainingItem
        // Hanya baris yang analisisnya masih ada. TextAnalysis memakai soft
        // delete sehingga cascade delete tidak pernah berjalan, dan baris milik
        // analisis yang sudah dihapus tetap tertinggal - dulu ikut terhitung di
        // sini padahal tidak muncul di daftar file mana pun dan tidak bisa
        // dikoreksi siapa pun.
        $itemsAktif = TrainingItem::whereHas('textAnalysis');

        $totalItems = (clone $itemsAktif)->count();
        $verifiedItems = (clone $itemsAktif)->where('is_corrected', true)->count();

        // Hitung akurasi (Bandingkan prediksi AI vs Koreksi Admin)
        $accurateItems = (clone $itemsAktif)
            ->where('is_corrected', true)
            ->whereColumn('predicted_sentiment', 'corrected_sentiment')
            ->count();

        $stats = [
            'accuracy' => $verifiedItems > 0 ? round(($accurateItems / $verifiedItems) * 100, 1) : 0,
            'total_texts' => $totalItems,
            'corrected_count' => $verifiedItems,
            'pending_count' => $totalItems - $verifiedItems,
        ];

        // 2. Ambil Daftar File (Batch)
        $batches = TextAnalysis::withCount(['trainingItems as verified_count' => function ($q) {
            $q->where('is_corrected', true);
        }])
            ->latest()
            ->paginate(10);

        // 3. Stopwords
        $stopwords = CustomStopword::latest()->get();

        // 4. Riwayat retraining model (loop active learning)
        // textAnalysis ikut dimuat: riwayat menampilkan analisis mana yang
        // dilatih, dan tanpa eager load itu satu query per baris.
        $trainings = ModelTraining::with(['user', 'textAnalysis'])->latest()->limit(10)->get();

        // Pelatihan yang bobotnya sedang dipakai layanan, per modul. Pelatihan
        // terbaru belum tentu yang aktif: model aspek pernah dikembalikan ke
        // iterasi sebelumnya karena iterasi baru lebih buruk.
        $modelAktif = app(\App\Services\ActiveModelService::class);
        $aktif = $modelAktif->aktif();
        $pelatihanAktif = collect($modelAktif->pelatihanAktif())->filter()->map->id->all();

        // Sidik jari kolam yang sama dengan putaran SEBELUMNYA pada modul yang
        // sama berarti kolamnya tidak bertambah - keliru menurut protokol kolam,
        // dan tidak terlihat dari angka mana pun yang lain. Dibandingkan per
        // modul karena sentimen dan aspek punya muatan yang berbeda: keduanya
        // hampir tidak pernah bersidik jari sama, dan membandingkannya silang
        // hanya akan menghasilkan peringatan yang tidak berarti.
        $sidikBerulang = [];
        $sidikTerakhir = [];

        foreach ($trainings->sortBy('id') as $satu) {
            $sidik = $satu->sidik_jari_kolam;

            if ($sidik) {
                $sidikBerulang[$satu->id] = ($sidikTerakhir[$satu->model_type] ?? null) === $sidik;
                $sidikTerakhir[$satu->model_type] = $sidik;
            }
        }

        // 5. Riwayat metrik evaluasi antar-iterasi
        $snapshots = EvaluationSnapshot::latest()->limit(20)->get();

        // Ringkasan pelatihan otomatis. Ditampilkan di sini, bukan kepada
        // pengguna: pelatihan ulang adalah urusan internal sistem, dan
        // halaman ini memang permukaan pengawasannya.
        $autoStatus = $autoRetrain->status();
        $tunda = $tundaService->status();

        // Koreksi per analisis, supaya pelatihan bisa dipicu per lembaga.
        // Putaran pertama studi kasus melatih gabungan dan hanya 19 dari 218
        // koreksi yang sampai - angka per analisis di sini membuat selisih
        // semacam itu terlihat sebelum tombol ditekan, bukan sesudahnya.
        // whereHas, bukan having: `having` atas kolom subquery jalan di MySQL
        // tetapi ditolak SQLite ("HAVING clause on a non-aggregate query"), dan
        // tes berjalan di SQLite. Perbedaan dialek semacam ini hanya ketahuan
        // kalau jalurnya benar-benar dirender - di sini tesnya yang menangkap.
        $perAnalisis = \App\Models\TextAnalysis::withCount([
            'trainingItems as koreksi_count' => fn ($q) => $q->where('is_corrected', true),
        ])
            ->whereHas('trainingItems', fn ($q) => $q->where('is_corrected', true))
            ->orderByDesc('koreksi_count')
            ->get();

        // Satu angka untuk kedua jalur. Dibaca dari konstanta, bukan ditulis
        // ulang di Blade: ambang yang ditampilkan harus persis ambang yang
        // ditegakkan, kalau tidak keterangannya berbohong.
        $ambangLatih = RetrainingService::MIN_LAYAK;

        // Ambang kalibrasi milik layanan, dipakai hanya untuk menandai baris
        // riwayat yang suhunya diwarisi alih-alih diukur ulang.
        $ambangValidasi = (int) config('analysis.auto_retrain.calibration_min_val', 50);

        return view('admin.training.index', compact('stats', 'batches', 'stopwords', 'trainings', 'snapshots', 'autoStatus', 'perAnalisis', 'ambangLatih', 'ambangValidasi', 'sidikBerulang', 'tunda', 'aktif', 'pelatihanAktif'));
    }

    /**
     * HALAMAN 2: SHOW (WORKSPACE KOREKSI)
     */
    public function show($id)
    {
        $analysis = TextAnalysis::with('result')->findOrFail($id);

        // LOGIC "LAZY LOAD":
        // Jika tabel training_items kosong untuk file ini, ekstrak dari JSON sekarang.
        if ($analysis->trainingItems()->count() === 0) {
            $this->trainingItemService->extractJsonToTable($analysis);
        }

        // Hitung statistik file + evaluasi model per dokumen
        $allItems = $analysis->trainingItems()->get();
        $correctedItems = $allItems->where('is_corrected', true)->values();

        $total = $allItems->count();
        $corrected = $correctedItems->count();
        $evaluation = $this->modelEvaluationService->buildEvaluationSummary($analysis, $correctedItems, $allItems);
        $accuracy = $evaluation['sentiment']['accuracy'] ?? 0;

        return view('admin.training.show', compact('analysis', 'accuracy', 'total', 'corrected', 'evaluation'));
    }

    /**
     * API: LOAD DATA TABLE (AJAX)
     */
    public function getData(Request $request, $id)
    {
        // Pastikan ID valid
        $exists = TextAnalysis::where('id', $id)->exists();
        if (! $exists) {
            return response()->json(['error' => 'Analysis not found'], 404);
        }

        $query = TrainingItem::where('text_analysis_id', $id);

        // Filter
        if ($request->status === 'pending') {
            $query->where('is_corrected', false);
        }
        if ($request->status === 'corrected') {
            $query->where('is_corrected', true);
        }
        if ($request->search) {
            $query->where('text_content', 'like', '%'.$request->search.'%');
        }
        if ($request->sentiment) {
            $query->where('predicted_sentiment', $request->sentiment);
        }

        $data = $query->latest()->paginate(20);

        // Mapping Data
        $formatted = $data->getCollection()->map(function ($item) {
            return [
                'id' => $item->id,
                // Gunakan utf8_encode jika perlu, atau pastikan string aman
                'text_content' => mb_convert_encoding($item->text_content, 'UTF-8', 'UTF-8'),
                'predicted_sentiment' => $item->predicted_sentiment ?? 'neutral',
                'confidence_score' => (float) $item->confidence_score,

                'corrected_sentiment' => $item->corrected_sentiment,
                'corrected_aspects' => $item->corrected_aspects,
                'correction_notes' => $item->correction_notes,

                'detected_aspects' => $item->detected_aspects ?? [],

                'is_corrected' => (bool) $item->is_corrected,
                'verified_at' => $item->verified_at ? $item->verified_at->format('d M Y, H:i') : null,
            ];
        });

        return response()->json([
            'data' => $formatted,
            'current_page' => $data->currentPage(),
            'last_page' => $data->lastPage(),
            'total' => $data->total(),
        ]);
    }

    /**
     * ACTION: UPDATE ITEM (SINGLE)
     */
    public function update(Request $request, $id)
    {
        $item = TrainingItem::findOrFail($id);

        $item->update([
            'corrected_sentiment' => $request->corrected_sentiment,
            'corrected_aspects' => $request->corrected_aspects,
            'correction_notes' => $request->correction_notes,
            'is_corrected' => true,
            'verified_at' => now(),
            'verified_by' => auth()->id(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * ACTION: EXPORT CSV
     */
    public function export()
    {
        // whereHas juga di sini: tanpa itu baris milik analisis yang sudah
        // dihapus ikut terekspor dengan source_file 'Unknown'.
        $data = TrainingItem::with('textAnalysis:id,title')
            ->whereHas('textAnalysis')
            ->where('is_corrected', true)
            ->get();

        $filename = 'training_dataset_'.date('Y-m-d').'.csv';

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$filename",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['text', 'label', 'aspects', 'source_file']);

            foreach ($data as $row) {
                fputcsv($file, [
                    $row->text_content,
                    $row->corrected_sentiment,
                    json_encode($row->corrected_aspects ?? []),
                    $row->textAnalysis->title ?? 'Unknown',
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // --- TOPIC STOPWORDS ---
    public function storeStopword(Request $request)
    {
        CustomStopword::create(['word' => $request->word, 'added_by' => auth()->id()]);

        return back()->with('success', 'Stopword ditambahkan');
    }

    public function destroyStopword($id)
    {
        CustomStopword::destroy($id);

        return back()->with('success', 'Stopword dihapus');
    }

    /**
     * Kirim seluruh data yang sudah dikoreksi ke endpoint retraining NLP API.
     *
     * Sebelumnya method ini hanya menampilkan flash message tanpa melakukan
     * apa pun, sehingga loop active learning tidak pernah benar-benar tertutup.
     */
    public function triggerTraining(Request $request, RetrainingService $retraining)
    {
        // `text_analysis_id` WAJIB, dan "gabungan" adalah nilai tersendiri -
        // bukan medan kosong. Selama gabungan diwakili string kosong, ia juga
        // mewakili "tidak memilih apa-apa", dan kedua pelatihan iterasi 1
        // tercatat `text_analysis_id = NULL` justru karena itu. Kini keduanya
        // tidak bisa tertukar: yang tidak memilih ditolak validasi.
        $validated = $request->validate([
            'model_type' => 'nullable|in:sentiment,aspect,both',
            'epochs' => 'nullable|integer|min:1|max:20',
            'text_analysis_id' => 'required|string',
            'paksa' => 'nullable|boolean',
        ]);

        $pilihan = $validated['text_analysis_id'];

        if ($pilihan !== 'gabungan') {
            if (! TextAnalysis::whereKey($pilihan)->exists()) {
                return back()->with('error', 'Analisis yang dipilih tidak ditemukan.');
            }

            $analysisId = (int) $pilihan;
        } else {
            $analysisId = null;
        }

        // Logikanya tinggal di RetrainingService supaya jalur otomatis, perintah
        // `retrain:analysis`, dan tombol admin ini menjalankan - dan menolak -
        // hal yang persis sama. Penjaga ambang 50 sampel dulu hanya ada di
        // perintah, sehingga tombol ini bisa memicu pelatihan yang perintahnya
        // sendiri tolak.
        //
        // `$analysisId` diteruskan sebagai PENCATATAN asal putaran, bukan
        // penyaring: yang dilatih selalu seluruh kolam koreksi.
        $hasil = $retraining->dispatch(
            $validated['model_type'] ?? 'both',
            (int) ($validated['epochs'] ?? 3),
            auth()->id(),
            $analysisId,
            (bool) ($validated['paksa'] ?? false),
        );

        if (! empty($hasil['error'])) {
            return back()->with('error', $hasil['error']);
        }

        $message = 'Training dikirim ke NLP API: '.implode(', ', $hasil['dispatched'])
                 .'. Pantau statusnya di tabel Riwayat Training.';

        if (! empty($hasil['skipped'])) {
            $message .= ' Dilewati: '.implode(', ', $hasil['skipped']).'.';
        }

        return back()->with('success', $message);
    }

    /**
     * Periksa kesiapan data latih sebelum retraining dijalankan (AJAX).
     *
     * Melatih pada data yang timpang bisa menghasilkan model yang hanya menebak
     * kelas mayoritas, dan akurasinya justru terlihat naik. Laporan ini membuat
     * kondisi itu terlihat sebelum tombol Latih Ulang ditekan.
     */
    public function previewTraining(NLPApiService $nlpService, RetrainingService $retraining)
    {
        // whereHas: koreksi milik analisis yang sudah dihapus tidak ikut dilatih,
        // supaya data yang dilatih sama persis dengan yang dilaporkan dashboard.
        $items = TrainingItem::whereHas('textAnalysis')->where('is_corrected', true)->get();

        if ($items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Belum ada data terkoreksi untuk diperiksa.',
            ], 422);
        }

        $payloads = [
            'sentiment' => $retraining->payloads($items)['sentiment'],
            'aspect' => $retraining->payloads($items)['aspect'],
        ];

        $reports = [];

        foreach ($payloads as $type => $data) {
            if (empty($data)) {
                $reports[$type] = [
                    'available' => false,
                    'message' => 'Belum ada koreksi untuk model '.$type.'.',
                ];

                continue;
            }

            try {
                $reports[$type] = ['available' => true] + $nlpService->retrainPreview($type, $data);
            } catch (\Exception $e) {
                \Log::warning("Pemeriksaan data {$type} gagal: ".$e->getMessage());

                $reports[$type] = [
                    'available' => false,
                    'message' => 'Tidak bisa memeriksa data '.$type.'. Pastikan NLP API berjalan.',
                ];
            }
        }

        return response()->json([
            'success' => true,
            'minimum_samples' => NLPApiService::MIN_RETRAIN_SAMPLES,
            'reports' => $reports,
        ]);
    }

    /**
     * ACTION: SINKRONISASI SEMUA DATA (MASS SYNC)
     */
    public function syncAll()
    {
        $analyses = TextAnalysis::with('result')
            ->where('status', 'completed')
            ->get();

        $count = 0;
        foreach ($analyses as $analysis) {
            if ($analysis->trainingItems()->exists()) {
                continue;
            }

            $this->trainingItemService->extractJsonToTable($analysis);
            $count++;
        }

        return back()->with('success', "Berhasil sinkronisasi {$count} file.");
    }

    // ------------------------------------------------ fase evaluasi kuesioner

    public function setTunda(Request $request, RetrainDeferralService $tunda)
    {
        $request->validate(['aktif' => 'required|boolean']);

        $tunda->setAktif($request->boolean('aktif'));

        return redirect(route('admin.training.index').'#fase-kuesioner')->with('success', $request->boolean('aktif')
            ? 'Mode tunda aktif. Pelatihan otomatis dicatat, tidak dijalankan.'
            : 'Mode tunda dimatikan. Pemicu berikutnya kembali menjalankan pelatihan langsung.');
    }

    public function putarUlang(RetrainDeferralService $tunda)
    {
        try {
            $tunda->mulai();
        } catch (\RuntimeException $e) {
            return redirect(route('admin.training.index').'#fase-kuesioner')->with('error', $e->getMessage());
        }

        return redirect(route('admin.training.index').'#fase-kuesioner')
            ->with('success', 'Pemutaran ulang dimulai. Putaran berikutnya berjalan otomatis setelah putaran sebelumnya terukur.');
    }

    public function hentikanPutarUlang(RetrainDeferralService $tunda)
    {
        $tunda->hentikan();

        return redirect(route('admin.training.index').'#fase-kuesioner')
            ->with('success', 'Pemutaran ulang dihentikan. Pemicu yang tersisa tetap tertunda.');
    }
}
