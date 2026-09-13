<?php

namespace App\Http\Controllers;

use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Services\AutoRetrainService;
use App\Services\ConfidenceReportService;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\ModelEvaluationService;
use App\Services\TrainingItemService;
use App\Support\TextFingerprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AnalysisFeedbackController extends Controller
{
    /** Jumlah baris koreksi per halaman. */
    private const PER_PAGE = 25;

    public function __construct(
        private TrainingItemService $trainingItemService,
        private ModelEvaluationService $modelEvaluationService,
        private ConfidenceReportService $confidenceReport,
        private AutoRetrainService $autoRetrain
    ) {}

    /**
     * Show the feedback form for the given analysis.
     * Only the owner of the analysis may access this page.
     */
    public function create(Request $request, int $id)
    {
        $analysis = TextAnalysis::with('result')->findOrFail($id);

        $this->authorize('view', $analysis);

        // Ensure training items have been extracted from the result JSON.
        if ($analysis->trainingItems()->count() === 0) {
            $this->trainingItemService->extractJsonToTable($analysis);
        }

        // Evaluasi memerlukan seluruh baris, tetapi yang dirender hanya satu
        // halaman: menampilkan ratusan kartu koreksi sekaligus membuat halaman
        // ini mencapai 2,1 MB dan berat dibuka saat mengoreksi.
        //
        // Baris bermetode 'empty' (teks emotikon/kosong yang tidak dinilai
        // model sama sekali) disingkirkan di sini, di sumbernya - bukan cuma
        // di daftar yang dirender. Kalau tidak, tidak ada apa pun untuk
        // dikoreksi pada baris itu, tetapi ia tetap menggelembungkan hitungan
        // "jumlah_semua"/"jumlah_ragu" dan ikut pada perhitungan evaluasi.
        $semuaBaris = $analysis->trainingItems()
            ->where(fn ($q) => $q->whereNull('predicted_method')->orWhere('predicted_method', '!=', 'empty'))
            ->get();

        // Baris yang teksnya ada di golden dataset beku disembunyikan dari
        // daftar koreksi. RetrainingService memang sudah menyingkirkannya dari
        // pelatihan, tetapi tanpa ini pengguna tetap disodori baris yang
        // hasilnya dibuang diam-diam - tenaganya terbuang, dan pada studi kasus
        // dengan active learning nyata itu terasa.
        // reservedHashes, bukan frozenHashes: baris yang sudah terpilih menjadi
        // kandidat uji tidak ditawarkan lagi walau datasetnya masih dianotasi.
        $hashUji = app(GoldenDatasetService::class)->reservedHashes();

        $idUji = empty($hashUji) ? [] : $semuaBaris
            ->filter(fn ($item) => isset($hashUji[TextFingerprint::hash((string) $item->text_content)]))
            ->pluck('id')
            ->all();

        $allItems = $semuaBaris->whereNotIn('id', $idUji)->values();
        $correctedItems = $allItems->where('is_corrected', true)->values();
        $evaluation = $this->modelEvaluationService->buildEvaluationSummary($analysis, $correctedItems, $allItems);

        // Halaman hasil mengajak pengguna dengan tautan ?fokus=sangat-ragu,
        // jadi yang dibuka langsung berisi baris yang paling perlu ditinjau -
        // bukan seluruh daftar yang harus digulir dulu. 'ragu' (gabungan pita
        // "Ragu" + "Sangat ragu", di bawah ambang tinjauan utama) tetap ada
        // untuk pengguna yang sengaja membuka lebih banyak dari halaman
        // koreksi sendiri, tetapi ajakan active learning TIDAK memintanya
        // sekaligus - lihat ConfidenceReportService::build().
        $fokus = match ($request->query('fokus')) {
            'ragu' => 'ragu',
            'sangat-ragu' => 'sangat-ragu',
            default => 'semua',
        };
        // Selalu lewat layanan, termasuk saat analisisnya belum punya hasil.
        // Membaca config langsung di sini pernah membuat ambangnya jatuh ke 0,0
        // begitu kunci itu dikosongkan, dan halaman "baris yang ragu" menjadi
        // kosong tanpa pesan apa pun.
        $ambang = $this->confidenceReport->threshold($analysis->result)['value'];
        // Batas pita "Sangat ragu" - sama persis dengan yang dilihat pengguna
        // pada panel keyakinan di halaman hasil analisis ini.
        $ambangSangatRagu = $this->confidenceReport->bandBoundsFor($analysis->result)['sedang'];

        $kueri = $analysis->trainingItems()
            ->where(fn ($q) => $q->whereNull('predicted_method')->orWhere('predicted_method', '!=', 'empty'))
            ->when($idUji, fn ($q) => $q->whereNotIn('id', $idUji))
            // Baris kosong/emotikon selalu tersimpan dengan confidence_score
            // 0 (lihat NLPApiService), jadi tanpa penyaringan di atas ia akan
            // selalu muncul PALING ATAS pada fokus "baris yang ragu" - justru
            // baris yang paling tidak berguna untuk dikoreksi yang paling
            // menonjol.
            ->when($fokus === 'ragu', fn ($q) => $q->where('confidence_score', '<', $ambang))
            ->when($fokus === 'sangat-ragu', fn ($q) => $q->where('confidence_score', '<', $ambangSangatRagu));

        // Baris paling tidak yakin didahulukan supaya yang paling perlu
        // ditinjau langsung terlihat.
        $items = $kueri
            ->orderBy('confidence_score', 'asc')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $fokusInfo = [
            'aktif' => $fokus,
            'ambang' => $ambang,
            'ambang_sangat_ragu' => $ambangSangatRagu,
            'jumlah_ragu' => $allItems->filter(
                fn ($item) => $item->confidence_score !== null && $item->confidence_score < $ambang
            )->count(),
            'jumlah_sangat_ragu' => $allItems->filter(
                fn ($item) => $item->confidence_score !== null && $item->confidence_score < $ambangSangatRagu
            )->count(),
            'jumlah_semua' => $allItems->count(),
            'sudah_dikoreksi' => $correctedItems->count(),
            'disembunyikan_uji' => count($idUji),
        ];

        // Count how many corrections this user has already made (across all analyses).
        $userCorrectionCount = TrainingItem::where('verified_by', auth()->id())
            ->where('is_corrected', true)
            ->count();

        return view('analysis.feedback', compact(
            'analysis', 'items', 'userCorrectionCount', 'evaluation', 'fokusInfo'
        ));
    }

    /**
     * Baris yang teksnya ada di golden dataset beku - tidak boleh dikoreksi.
     *
     * Penjaga kedua, di samping penyembunyian di daftar: kiriman formulir lama
     * atau permintaan yang dibuat langsung tetap tidak boleh menandai baris uji
     * sebagai terverifikasi manusia.
     *
     * @return array<string, true>
     */
    private function hashGoldenBeku(): array
    {
        return app(GoldenDatasetService::class)->frozenHashes();
    }

    /**
     * Apakah pengguna benar-benar meninjau baris ini?
     *
     * Sengaja tidak memakai "berbeda dari prediksi": menegaskan bahwa prediksi
     * model sudah benar juga merupakan koreksi yang berharga bagi active
     * learning. Yang dicari adalah tanda pengguna melakukan sesuatu pada baris
     * itu - memilih sentimen, mengubah aspek, atau menulis catatan.
     */
    private function adaKoreksi(TrainingItem $item, array $correction, ?array $aspects): bool
    {
        if (filled($correction['corrected_sentiment'] ?? null)) {
            return true;
        }

        if (filled($correction['correction_notes'] ?? null)) {
            return true;
        }

        // Kolom aspek selalu terisi bawaan hasil deteksi model, jadi yang
        // dihitung hanya perubahannya.
        $sebelumnya = $item->corrected_aspects ?? $item->detected_aspects ?? [];

        return $aspects !== null && array_values($aspects) !== array_values((array) $sebelumnya);
    }

    /**
     * Kiriman koreksi, dari mana pun asalnya.
     *
     * `corrections_json` diutamakan, dan itu bukan sekadar kenyamanan.
     * Formulir mengirim empat medan per baris; pada 262 koreksi itu 1.048
     * variabel, sementara `max_input_vars` PHP bawaannya **1.000** - sisanya
     * dibuang DIAM-DIAM tanpa galat apa pun. Persis kelas kehilangan yang
     * sedang diperbaiki di sini, hanya berpindah dari sisi peramban ke sisi
     * PHP. Satu medan JSON tidak menyentuh batas itu sama sekali.
     *
     * Larik `corrections[]` biasa tetap diterima sebagai jalur tanpa
     * JavaScript, dan pada jalur itu jumlah barisnya selalu satu halaman
     * paginasi sehingga aman dari batas tadi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function kirimanKoreksi(Request $request): array
    {
        $mentah = $request->input('corrections_json');

        if (filled($mentah)) {
            $terurai = json_decode((string) $mentah, true);

            return is_array($terurai) ? array_values($terurai) : [];
        }

        return array_values((array) $request->input('corrections', []));
    }

    /**
     * Save the user's corrections for one or more training items.
     *
     * Menyimpan SELURUH koreksi milik satu analisis dalam satu kiriman, bukan
     * hanya baris pada halaman paginasi yang sedang tampil. Perilaku lama itu
     * penyebab 218 koreksi tersimpan menyusut menjadi 19 saat dilatihkan:
     * pengguna mengoreksi lintas halaman, drafnya tersimpan di peramban, tetapi
     * yang benar-benar sampai ke server hanya 25 baris terakhir.
     */
    public function store(Request $request, int $id)
    {
        $analysis = TextAnalysis::findOrFail($id);

        $this->authorize('view', $analysis);

        $koreksi = $this->kirimanKoreksi($request);

        $validator = Validator::make(['corrections' => $koreksi], [
            'corrections' => 'required|array|min:1',
            'corrections.*.item_id' => 'required|integer',
            'corrections.*.corrected_sentiment' => 'nullable|in:positive,negative,neutral',
            'corrections.*.corrected_aspects' => 'nullable|string',
            'corrections.*.correction_notes' => 'nullable|string|max:500',
        ]);

        $validator->validate();

        // Satu query untuk seluruh baris, bukan satu per koreksi. Aturan
        // `exists:training_items,id` per baris sengaja tidak dipakai: pada 262
        // koreksi itu 262 query hanya untuk validasi, dan pemeriksaan di bawah
        // lebih kuat - ia menuntut barisnya milik analisis INI, bukan sekadar
        // ada di tabel.
        $ids = array_values(array_unique(array_map(
            fn ($baris) => (int) ($baris['item_id'] ?? 0),
            $koreksi
        )));

        $items = TrainingItem::where('text_analysis_id', $analysis->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $userId = auth()->id();
        $hashUji = $this->hashGoldenBeku();

        $tersimpan = [];
        // Baris yang TIDAK AKAN PERNAH bisa disimpan, apa pun isinya: baris uji
        // golden, dan baris milik analisis lain. Drafnya di peramban harus ikut
        // dibuang - kalau tidak, tombol Kirim menyala selamanya untuk koreksi
        // yang setiap kali dilewati diam-diam, dan pengguna terus disuguhi
        // "tidak ada yang disimpan" tanpa tahu sebabnya.
        $takBisaDisimpan = [];
        $dilewati = 0;
        $bukanMilikAnalisis = 0;

        DB::transaction(function () use ($koreksi, $items, $hashUji, $userId, &$tersimpan, &$takBisaDisimpan, &$dilewati, &$bukanMilikAnalisis) {
            foreach ($koreksi as $baris) {
                $item = $items->get((int) ($baris['item_id'] ?? 0));

                $idDiminta = (int) ($baris['item_id'] ?? 0);

                if (! $item) {
                    $bukanMilikAnalisis++;
                    $takBisaDisimpan[] = $idDiminta;

                    continue;
                }

                // Baris uji tidak boleh ditandai terverifikasi manusia. Daftarnya
                // sudah menyembunyikan baris ini, tetapi draf lama di peramban
                // atau permintaan yang dibuat langsung tetap harus ditolak.
                if ($hashUji && isset($hashUji[TextFingerprint::hash((string) $item->text_content)])) {
                    $dilewati++;
                    $takBisaDisimpan[] = $item->id;

                    continue;
                }

                $aspects = null;
                if (! empty($baris['corrected_aspects'])) {
                    $aspects = array_values(array_filter(
                        array_map('trim', explode(',', (string) $baris['corrected_aspects']))
                    ));
                }

                // Baris yang tidak disentuh pengguna dilewati.
                //
                // Draf peramban memuat setiap baris yang pernah tersentuh,
                // termasuk yang isinya kemudian dikosongkan lagi. Menandainya
                // "sudah dikoreksi" dengan prediksi model sendiri sebagai
                // koreksinya berarti baris yang tidak pernah benar-benar
                // ditinjau ikut masuk data latih sebagai label hasil verifikasi
                // manusia - dan itu merusak angka yang justru dilaporkan naskah.
                if (! $this->adaKoreksi($item, $baris, $aspects)) {
                    $dilewati++;

                    continue;
                }

                $item->update([
                    'corrected_sentiment' => $baris['corrected_sentiment'] ?? $item->predicted_sentiment,
                    'corrected_aspects' => $aspects,
                    'correction_notes' => $baris['correction_notes'] ?? null,
                    'is_corrected' => true,
                    'verified_at' => now(),
                    'verified_by' => $userId,
                ]);

                $tersimpan[] = $item->id;
            }
        });

        if ($bukanMilikAnalisis > 0) {
            Log::warning("Koreksi analisis {$analysis->id}: {$bukanMilikAnalisis} baris diabaikan karena bukan milik analisis ini.");
        }

        // Loop active learning menutup dirinya sendiri di sini: begitu koreksi
        // baru mencapai ambang, pelatihan ulang untuk analisis ini langsung
        // diantrekan tanpa perlu membuka halaman admin. Dibatasi pada analisis
        // ini saja - melatih gabungan enam lembaga sekaligus mencampur korpus
        // yang sengaja dipisahkan studi kasusnya.
        //
        // Hanya dijalankan bila memang ada yang tersimpan: menekan Kirim pada
        // halaman tanpa satu pun baris ditinjau tidak boleh memicu putaran.
        $pelatihan = count($tersimpan) > 0
            ? $this->autoRetrain->run($analysis->id)
            : ['triggered' => false, 'blocking' => false, 'reason' => ''];

        $totalAnalisis = $analysis->trainingItems()->where('is_corrected', true)->count();

        $pesan = count($tersimpan) > 0
            ? sprintf(
                'Terima kasih! %d koreksi tersimpan. Analisis ini kini punya %d koreksi seluruhnya.',
                count($tersimpan),
                $totalAnalisis
            )
            : 'Belum ada baris yang ditinjau, jadi tidak ada yang disimpan.';

        // Putaran yang benar-benar jalan disebut satu kalimat; yang tertahan
        // penjaga disebut beserta alasannya. Keadaan rutin - koreksi belum
        // cukup, masih dalam jeda - tetap diam: itu alur normal, bukan kabar.
        if ($pelatihan['triggered']) {
            $pesan .= ' Putaran active learning untuk analisis ini sudah diantrekan.';
        } elseif (! empty($pelatihan['blocking'])) {
            $pesan .= ' Pelatihan ulang belum dijalankan: '.$pelatihan['reason'];
        }

        return redirect()
            ->route('analysis.feedback', array_filter([
                'id' => $analysis->id,
                'page' => $request->integer('page') ?: null,
                // Fokus ikut dibawa: pengguna yang datang dari ajakan "koreksi
                // baris yang sangat ragu" tidak boleh terlempar ke daftar penuh.
                'fokus' => in_array($request->input('fokus'), ['ragu', 'sangat-ragu'], true)
                    ? $request->input('fokus')
                    : null,
            ]))
            ->with('feedback_success', $pesan)
            // Dipakai peramban untuk menghapus draf HANYA milik baris yang
            // benar-benar tersimpan. Draf tidak pernah dihapus optimistis:
            // kalau kirimannya gagal di tengah jalan, pekerjaan pengguna harus
            // tetap ada saat halaman dibuka lagi.
            ->with('koreksi_tersimpan', array_values(array_unique(
                array_merge($tersimpan, $takBisaDisimpan)
            )));
    }
}
