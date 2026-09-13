<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunHoldoutEvaluation;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Services\Evaluation\ClassificationMetrics;
use App\Services\Evaluation\EvaluationComparisonService;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\Evaluation\GoldenSamplingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Permukaan pengawasan untuk evaluasi hold-out.
 *
 * Ada di area admin karena evaluasi memang pekerjaan sesekali yang dilakukan
 * peneliti, bukan bagian dari alur pengguna. Aplikasi tetap berjalan penuh
 * tanpa halaman ini - loop active learning tidak bergantung padanya.
 */
class EvaluationController extends Controller
{
    public function __construct(
        private GoldenDatasetService $golden,
        private EvaluationComparisonService $banding,
    ) {}

    public function index()
    {
        $datasets = EvaluationDataset::withCount('runs')->orderByDesc('id')->get();

        return view('admin.evaluation.index', [
            'datasets' => $datasets,
            'leakage' => $this->golden->leakageReport(),
        ]);
    }

    public function show(Request $request, $id)
    {
        $dataset = EvaluationDataset::with(['runs' => fn ($q) => $q->orderByDesc('id')])->findOrFail($id);

        $kurva = $this->banding->learningCurve($dataset);

        // Run yang dibatalkan tidak boleh menjadi pilihan pembanding, tidak boleh
        // menjadi "run terakhir", dan tidak boleh masuk kurva - angkanya tidak
        // dapat direproduksi. Barisnya tetap dirender di daftar riwayat, dengan
        // penanda BATAL, karena jejak auditnya justru yang perlu disimpan.
        $selesai = $dataset->runs
            ->where('status', 'completed')
            ->reject(fn ($run) => $run->batal)
            ->values();

        // Bawaan perbandingan: run pertama lawan run terakhir - pertanyaan yang
        // paling sering diajukan, yaitu apakah active learning membuahkan hasil.
        $sebelumId = (int) $request->query('before', optional($selesai->last())->id);
        $sesudahId = (int) $request->query('after', optional($selesai->first())->id);

        $perbandingan = null;

        if ($sebelumId && $sesudahId && $sebelumId !== $sesudahId) {
            try {
                $perbandingan = $this->banding->byIds($sebelumId, $sesudahId);
            } catch (Throwable $e) {
                $perbandingan = ['valid' => false, 'message' => $e->getMessage()];
            }
        }

        return view('admin.evaluation.show', [
            'dataset' => $dataset,
            'kurva' => $kurva,
            'runTerakhir' => $selesai->first(),
            'runSelesai' => $selesai,
            'perbandingan' => $perbandingan,
            'sebelumId' => $sebelumId,
            'sesudahId' => $sesudahId,
            'leakage' => $this->golden->leakageReport($dataset),
            'contohItem' => $dataset->items()->limit(8)->get(),
            // Metrik aspek baru menyala bila seluruh baris ditinjau, jadi
            // angkanya perlu terlihat selama anotasi berlangsung - bukan baru
            // ketahuan saat evaluasi menolak menghitung.
            'aspekBelumDitinjau' => $dataset->items()->whereNull('gold_aspects')->count(),
            'confidenceBounds' => app(\App\Services\ConfidenceReportService::class)->bandBounds(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'split' => 'required|in:test,dev',
            'test_ratio' => 'nullable|numeric|min:0.05|max:0.9',
            'seed' => 'nullable|integer|min:0',
        ]);

        try {
            $dibaca = $this->golden->readFile($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        if (empty($dibaca['rows'])) {
            return back()->with('error', 'Tidak ada baris sah di berkas itu.')->withInput();
        }

        $baris = $dibaca['rows'];
        $sisaCsv = null;

        // Bila diminta memecah, hanya bagian uji yang menjadi golden dataset dan
        // sisanya diunduh sebagai CSV - bukan disimpan diam-diam sebagai data
        // latih. Memasukkan data latih lewat halaman evaluasi akan mengaburkan
        // batas yang justru sedang dijaga halaman ini.
        if ($request->filled('test_ratio')) {
            $pecah = $this->golden->stratifiedSplit(
                $baris,
                (float) $request->test_ratio,
                (int) ($request->seed ?? 42),
            );
            $baris = $pecah['test'];
            $sisaCsv = count($pecah['train']);
        }

        $dataset = $this->golden->store($baris, [
            'name' => $request->name,
            'description' => $request->description,
            'split' => $request->split,
            'source' => $sisaCsv !== null ? 'split' : 'import',
            'created_by' => Auth::id(),
        ]);

        $pesan = "Golden dataset \"{$dataset->name}\" tersimpan dengan {$dataset->fresh()->item_count} baris.";

        if ($sisaCsv !== null) {
            $pesan .= " {$sisaCsv} baris sisa tidak disimpan - pakai perintah evaluation:split bila ingin berkas sisanya.";
        }

        if ($dibaca['errors']) {
            $pesan .= ' '.count($dibaca['errors']).' baris dilewati (duplikat atau label tidak dikenali).';
        }

        return redirect()
            ->route('admin.evaluation.show', $dataset->id)
            ->with('success', $pesan);
    }

    /**
     * Formulir pengambilan sampel dari korpus yang sudah ada di sistem.
     */
    public function sampling(GoldenSamplingService $sampling, ClassificationMetrics $metrics)
    {
        $sumber = $sampling->availableSources();

        // Margin selang kepercayaan untuk beberapa ukuran sampel, dihitung pada
        // akurasi 85%. Ditampilkan sebelum anotasi dimulai, saat menambah baris
        // masih murah - bukan setelah 200 baris terlanjur dianotasi.
        $margin = [];
        foreach ([100, 200, 300, 400, 500] as $n) {
            $ci = $metrics->wilson((int) round(0.85 * $n), $n);
            $margin[$n] = round(($ci['high'] - $ci['low']) / 2, 1);
        }

        return view('admin.evaluation.sampling', [
            'sumber' => $sumber,
            'margin' => $margin,
        ]);
    }

    public function storeSample(Request $request, GoldenSamplingService $sampling)
    {
        $request->validate([
            'analysis_ids' => 'required|array|min:1',
            'analysis_ids.*' => 'integer',
            'name' => 'required|string|max:255',
            'ukuran_mode' => 'required|in:jumlah,persen',
            'size' => 'required_if:ukuran_mode,jumlah|nullable|integer|min:10|max:5000',
            'percent' => 'required_if:ukuran_mode,persen|nullable|numeric|min:0.5|max:100',
            'seed' => 'required|integer|min:0',
            'stratify_by' => 'nullable|string|max:255',
            'min_length' => 'nullable|integer|min:0|max:500',
            'min_per_stratum' => 'nullable|integer|min:0|max:500',
        ]);

        try {
            $hasil = $sampling->sample([
                'analysis_ids' => $request->analysis_ids,
                'size' => $request->ukuran_mode === 'jumlah' ? (int) $request->size : 0,
                'percent' => $request->ukuran_mode === 'persen' ? (float) $request->percent : null,
                'seed' => (int) $request->seed,
                'stratify_by' => $request->stratify_by ?: null,
                'min_length' => (int) ($request->min_length ?? GoldenSamplingService::MIN_PANJANG_BAWAAN),
                'min_per_stratum' => (int) ($request->min_per_stratum ?? 0),
                // Tidak diekspos di formulir: memasukkan baris yang sudah
                // dikoreksi ke test set adalah kebocoran, dan tidak ada alasan
                // sah untuk menawarkannya lewat antarmuka.
                'exclude_corrected' => true,
            ]);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $dataset = $sampling->store($hasil['rows'], $hasil['plan'], [
            'name' => $request->name,
            'description' => 'Sampel acak berstrata, semai '.$hasil['plan']['seed'].'. Menunggu anotasi manusia.',
            'split' => 'test',
            'created_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.evaluation.show', $dataset->id)
            ->with('success', sprintf(
                '%d baris terambil dari %d kandidat. Semuanya BELUM berlabel - unduh berkas anotasi, isi kolom label, lalu unggah kembali.',
                $hasil['plan']['sampled'],
                $hasil['plan']['candidates'],
            ));
    }

    /**
     * Berkas anotasi kosong. Sengaja tanpa prediksi model.
     */
    public function downloadAnnotation(Request $request, $id, GoldenSamplingService $sampling)
    {
        $dataset = EvaluationDataset::findOrFail($id);
        $denganPrediksi = $request->boolean('prediksi');
        $baris = $sampling->annotationRows($dataset, $denganPrediksi);
        $header = $sampling->annotationHeader($denganPrediksi);

        return response()->streamDownload(function () use ($header, $baris) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);

            foreach ($baris as $satu) {
                fputcsv($out, $satu);
            }

            fclose($out);
        }, 'anotasi-golden-'.$dataset->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function uploadAnnotation(Request $request, $id, GoldenSamplingService $sampling)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        $dataset = EvaluationDataset::findOrFail($id);

        try {
            $hasil = $sampling->applyAnnotations($dataset, $request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = "{$hasil['filled']} label sentimen diisi, {$hasil['unchanged']} tidak berubah.";

        if (($hasil['aspects_filled'] ?? 0) > 0) {
            $pesan .= " {$hasil['aspects_filled']} baris diisi anotasi aspek.";
        }

        if ($hasil['unmatched'] > 0) {
            $pesan .= " {$hasil['unmatched']} baris tidak dikenali - kolom `id` mungkin tersunting.";
        }

        $segar = $dataset->fresh();

        if (($sisa = $segar->unlabeledCount()) > 0) {
            $pesan .= " Masih {$sisa} baris tanpa label sentimen.";
        }

        // Metrik aspek hanya dihitung bila SELURUH baris sudah ditinjau
        // aspeknya - termasuk yang memang tidak punya aspek. Tanpa itu, aspek
        // yang dikarang model pada baris yang tidak ditinjau tidak pernah
        // terhitung sebagai false positive.
        $tanpaAspek = $segar->items()->whereNull('gold_aspects')->count();

        if ($tanpaAspek > 0 && $tanpaAspek < $segar->item_count) {
            $pesan .= " {$tanpaAspek} baris belum ditinjau aspeknya, jadi metrik aspek belum akan dihitung.";
        }

        return back()->with('success', $pesan);
    }

    /**
     * Buang baris yang tidak berhasil dianotasi.
     *
     * Anotator menandai baris yang benar-benar tidak bisa diputuskan sebagai
     * "tidak jelas", dan baris itu pulang tanpa label. Membekukan dataset yang
     * sebagian barisnya tidak berlabel ditolak - jadi harus ada jalan resmi
     * untuk menyingkirkannya, bukan lewat basis data.
     *
     * Jumlahnya dicatat pada rencana pengambilan supaya bisa dilaporkan apa
     * adanya di naskah: berapa persen korpus yang bahkan manusia tidak bisa
     * memutuskannya adalah temuan, bukan aib.
     */
    public function dropUnlabeled($id)
    {
        $dataset = EvaluationDataset::findOrFail($id);

        if ($dataset->isFrozen()) {
            return back()->with('error', 'Dataset sudah dibekukan; isinya tidak bisa diubah lagi.');
        }

        $jumlah = $dataset->items()->whereNull('gold_sentiment')->count();

        if ($jumlah === 0) {
            return back()->with('success', 'Tidak ada baris tanpa label.');
        }

        $dataset->items()->whereNull('gold_sentiment')->delete();

        $rencana = $dataset->sampling_plan ?? [];
        $rencana['dropped_unlabeled'] = ($rencana['dropped_unlabeled'] ?? 0) + $jumlah;
        $dataset->update(['sampling_plan' => $rencana]);

        $this->golden->refreshStats($dataset->fresh());

        return back()->with('success', "{$jumlah} baris tanpa label dibuang. Jumlahnya dicatat pada rencana pengambilan untuk dilaporkan di naskah.");
    }

    public function freeze($id)
    {
        $dataset = EvaluationDataset::findOrFail($id);

        try {
            $this->golden->freeze($dataset);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dataset dibekukan. Isinya tidak akan berubah lagi, jadi hasil antar iterasi bisa dibandingkan.');
    }

    public function unfreeze($id)
    {
        $dataset = EvaluationDataset::findOrFail($id);

        try {
            $this->golden->unfreeze($dataset);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pembekuan dibuka.');
    }

    public function run(Request $request, $id)
    {
        $request->validate([
            'label' => 'nullable|string|max:255',
            'with_aspects' => 'nullable|boolean',
            'with_topic' => 'nullable|boolean',
        ]);

        $dataset = EvaluationDataset::findOrFail($id);

        if (! $dataset->isFrozen()) {
            return back()->with('error', 'Bekukan dataset lebih dulu. Evaluasi atas dataset yang masih bisa berubah tidak bisa dibandingkan antar iterasi.');
        }

        RunHoldoutEvaluation::dispatch($dataset->id, [
            'label' => $request->label,
            'with_aspects' => $request->boolean('with_aspects', true),
            'with_topic' => $request->boolean('with_topic'),
            'triggered_by' => Auth::id(),
        ]);

        return back()->with('success', 'Evaluasi diantrekan. Hasilnya muncul di sini setelah selesai - pastikan queue worker berjalan.');
    }

    public function destroy($id)
    {
        $dataset = EvaluationDataset::findOrFail($id);

        if ($dataset->runs()->exists()) {
            return back()->with('error', 'Dataset ini punya riwayat evaluasi. Menghapusnya akan menghapus angka yang mungkin sudah dikutip; hapus lewat basis data bila benar-benar diperlukan.');
        }

        $dataset->delete();

        return redirect()->route('admin.evaluation.index')->with('success', 'Golden dataset dihapus.');
    }

    /**
     * Unduh baris yang salah dinilai pada satu run - bahan analisis kesalahan
     * di naskah.
     */
    public function exportErrors($runId)
    {
        $run = EvaluationRun::with('dataset')->findOrFail($runId);

        $baris = $run->items()
            ->with('item')
            ->where('sentiment_correct', false)
            ->get();

        $nama = 'kesalahan-run-'.$run->id.'.csv';

        return response()->streamDownload(function () use ($baris) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['text', 'gold', 'predicted', 'confidence', 'segment', 'method']);

            foreach ($baris as $satu) {
                fputcsv($out, [
                    $satu->item->text ?? '',
                    $satu->item->gold_sentiment ?? '',
                    $satu->predicted_sentiment,
                    $satu->confidence,
                    $satu->item->segment ?? '',
                    $satu->method,
                ]);
            }

            fclose($out);
        }, $nama, ['Content-Type' => 'text/csv']);
    }
}
