<?php

namespace App\Services;

use App\Jobs\RetrainModel;
use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use App\Models\TrainingItem;
use App\Services\Evaluation\GoldenDatasetService;
use App\Support\TextFingerprint;
use Illuminate\Support\Facades\Log;

/**
 * Menyusun data latih dari koreksi pengguna dan mengantrekan pelatihan ulang.
 *
 * Dipisahkan dari `Admin\TrainingController` supaya loop active learning tidak
 * lagi bergantung pada seorang admin yang menekan tombol. Logika yang sama kini
 * dipakai oleh tiga pemicu: koreksi pengguna (otomatis), penjadwal, dan tombol
 * di halaman admin. Kalau logikanya tetap di controller, jalur otomatisnya
 * harus menggandakan kode - dan dua salinan pasti berselisih.
 *
 * Penjaga kelayakan juga tinggal di sini, bukan di `retrain:analysis`. Selama
 * penjaganya hanya ada di perintah, tombol UI bisa memicu pelatihan yang
 * perintahnya sendiri tolak - dan pemilik proyek menjalankan active learning
 * dari antarmuka.
 */
class RetrainingService
{
    /**
     * Ambang kelayakan satu putaran active learning.
     *
     * Di bawah ini set validasi 20% tidak sampai 10 baris, dan "metrik tidak
     * memburuk" berhenti berarti apa-apa. Bukan batas API - itu
     * `NLPApiService::MIN_RETRAIN_SAMPLES` = 10, yang melindungi endpoint dari
     * muatan sepele - melainkan batas kelayakan mengambil keputusan menyimpan
     * checkpoint.
     *
     * Terukur pada putaran pertama studi kasus: val_size 4, weighted_f1_delta 0,
     * dan checkpoint tetap disimpan karena "tidak memburuk" secara teknis benar
     * pada n=4.
     */
    public const MIN_LAYAK = 50;

    /**
     * Berapa koreksi yang disingkirkan pada pemanggilan correctedItems() terakhir
     * karena bertabrakan dengan golden dataset. Ditampilkan di halaman admin.
     */
    private int $ditahanGolden = 0;

    public function __construct(
        private ModelEvaluationService $modelEvaluation,
        private GoldenDatasetService $golden,
    ) {}

    /**
     * Seluruh kolam koreksi yang layak dilatihkan.
     *
     * **Kolam, bukan per analisis.** Ini protokol active learning baku: tiap
     * putaran melatih ulang dari bobot dasar tetap atas SELURUH label yang
     * sudah terkumpul, lalu diukur pada hold-out set yang sama. Sisi layanan
     * memakai protokol yang sama (`retrain_from_baseline = true`), jadi kalau
     * di sini disaring per analisis, putaran kedua akan berangkat dari bobot
     * dasar tetapi hanya melihat lembaga kedua - lembaga pertama terlupakan
     * seluruhnya, dan kurva iterasinya mengukur "ganti korpus", bukan
     * "tambah data".
     *
     * Pilihan analisis di UI karena itu **bukan penyaring**. Artinya "lembaga
     * ini baru saja masuk ke kolam", dipakai untuk pencatatan `ModelTraining`
     * dan penamaan iterasi - lihat `dispatch()`.
     *
     * Dua penyaring yang tetap berlaku, keduanya wajib:
     *
     * 1. whereHas: koreksi milik analisis yang sudah dihapus tidak ikut dilatih,
     *    supaya data yang dilatih sama persis dengan yang dilaporkan dashboard.
     * 2. Baris yang teksnya ada di golden dataset beku disingkirkan. Tanpa ini,
     *    hold-out test kehilangan artinya begitu seorang pengguna kebetulan
     *    mengoreksi kalimat yang juga ada di test set - modelnya lalu diuji
     *    pada kalimat yang pernah dilatihkan, dan angkanya naik tanpa model
     *    benar-benar membaik. Disaring di sini, bukan diserahkan pada kehati-
     *    hatian pemanggil, karena satu jalur yang lupa saja sudah cukup.
     */
    public function correctedItems()
    {
        $hashGolden = $this->golden->frozenHashes();

        $items = TrainingItem::whereHas('textAnalysis')
            ->where('is_corrected', true)
            ->get();

        if (empty($hashGolden)) {
            $this->ditahanGolden = 0;

            return $items;
        }

        $sebelum = $items->count();

        $items = $items->reject(
            fn ($item) => isset($hashGolden[TextFingerprint::hash((string) $item->text_content)])
        )->values();

        $this->ditahanGolden = $sebelum - $items->count();

        if ($this->ditahanGolden > 0) {
            Log::info("Data latih: {$this->ditahanGolden} koreksi disingkirkan karena ada di golden dataset.");
        }

        return $items;
    }

    /**
     * Sumbangan satu analisis ke kolam - untuk **pelaporan saja**.
     *
     * Sengaja terpisah dari `correctedItems()` dan tidak pernah dipakai untuk
     * menyusun muatan pelatihan. Selama keduanya satu method dengan argumen
     * opsional, satu pemanggil yang lupa mengosongkan argumennya sudah cukup
     * untuk diam-diam melatih sepotong kolam saja.
     */
    public function contributionOf(int $analysisId)
    {
        $hashGolden = $this->golden->frozenHashes();

        $items = TrainingItem::whereHas('textAnalysis')
            ->where('is_corrected', true)
            ->where('text_analysis_id', $analysisId)
            ->get();

        if (empty($hashGolden)) {
            return $items;
        }

        return $items->reject(
            fn ($item) => isset($hashGolden[TextFingerprint::hash((string) $item->text_content)])
        )->values();
    }

    public function heldOutCount(): int
    {
        return $this->ditahanGolden;
    }

    /**
     * @return array{sentiment: array, aspect: array}
     */
    public function payloads($items): array
    {
        return [
            'sentiment' => $this->sentimentPayload($items),
            'aspect' => $this->aspectPayload($items),
        ];
    }

    /**
     * Jumlah sampel terbesar di antara muatan yang benar-benar akan dilatih.
     *
     * Dipakai penjaga kelayakan. Menyaring per `$modelType` penting: melatih
     * hanya modul aspek tidak boleh lolos karena kebetulan koreksi sentimennya
     * banyak.
     */
    public function sampleCount(array $payloads, string $modelType = 'both'): int
    {
        $terpakai = array_filter(
            $payloads,
            fn ($type) => $modelType === 'both' || $modelType === $type,
            ARRAY_FILTER_USE_KEY
        );

        return empty($terpakai) ? 0 : max(array_map('count', $terpakai));
    }

    /**
     * Pelatihan terakhir yang selesai tetapi belum ada evaluasi sesudahnya.
     *
     * Penjaga terpenting pada rangkaian enam iterasi: setiap kali bobot ditimpa,
     * iterasi sebelumnya hilang selamanya kalau belum sempat diukur. Enam
     * putaran berarti enam kesempatan melakukan kesalahan yang tidak bisa
     * dibatalkan.
     *
     * **Disaring per modul**, karena keduanya menulis ke berkas yang berbeda:
     * sentimen ke `models/sentiment_retrained/`, aspek ke
     * `models/aspect_retrained.pt`. Melatih sentimen tidak bisa menimpa bobot
     * aspek, jadi memblokirnya bukan cuma tidak perlu - ia mendorong ke keadaan
     * yang lebih buruk. Terukur pada iterasi 2: pelatihan sentimen gagal
     * (os error 1224) sementara aspek selesai, dan menuruti penjaga yang tidak
     * disaring berarti mengukur gabungan "aspek iterasi 2 + sentimen bobot
     * dasar" - satu titik campuran yang tidak sesuai iterasi mana pun.
     *
     * `both` disertakan di kedua arah dan itu wajib: pelatihan `both` yang
     * belum diukur memang menimpa keduanya, jadi ia tetap memblokir pelatihan
     * satu modul; dan meminta `both` tetap dihalangi oleh modul mana pun yang
     * belum diukur.
     *
     * Cakupan yang terlalu lebar bukan sekadar merepotkan: begitu "paksa"
     * menjadi kebiasaan setiap kali satu modul gagal, penjaga yang *benar* ikut
     * kehilangan daya.
     *
     * @param  string|null  $modelType  sentiment|aspect|both; null = periksa semua
     */
    public function unmeasuredTraining(?string $modelType = null): ?ModelTraining
    {
        $terakhir = ModelTraining::where('status', 'completed')
            ->when(
                $modelType && $modelType !== 'both',
                fn ($q) => $q->whereIn('model_type', [$modelType, 'both'])
            )
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        if (! $terakhir) {
            return null;
        }

        $sejak = $terakhir->completed_at ?? $terakhir->created_at;

        $sudahDiukur = EvaluationRun::where('status', 'completed')
            ->when($sejak, fn ($q) => $q->where('completed_at', '>', $sejak))
            ->exists();

        return $sudahDiukur ? null : $terakhir;
    }

    /**
     * Antrekan pelatihan ulang atas SELURUH kolam koreksi.
     *
     * `$analysisId` adalah **pencatatan asal, bukan penyaring**: ia menjawab
     * "lembaga mana yang baru saja masuk ke kolam pada putaran ini", dan itulah
     * yang membuat riwayat bisa dibaca sebagai "Iterasi 2 - setelah Presiden
     * ditambahkan". Data latihnya tetap seluruh kolam, apa pun isinya.
     *
     * @param  string  $modelType  sentiment|aspect|both
     * @param  int|null  $triggeredBy  id pengguna, atau null bila dipicu sistem
     * @param  int|null  $analysisId  analisis yang memicu putaran ini; dicatat, tidak menyaring
     * @param  bool  $paksa  lewati penjaga kelayakan (setara `--force`)
     * @return array{dispatched: array, skipped: array, trainings: array, error: string|null}
     */
    public function dispatch(
        string $modelType = 'both',
        int $epochs = 3,
        ?int $triggeredBy = null,
        ?int $analysisId = null,
        bool $paksa = false,
    ): array {
        $kosong = ['dispatched' => [], 'skipped' => [], 'trainings' => []];

        if (ModelTraining::running()->exists()) {
            return $kosong + ['error' => 'Masih ada proses training yang berjalan. Tunggu sampai selesai.'];
        }

        $items = $this->correctedItems();

        if ($items->isEmpty()) {
            return $kosong + ['error' => 'Belum ada data terkoreksi untuk dilatih.'];
        }

        $payloads = $this->payloads($items);

        if (! $paksa && ($penolakan = $this->tolakBilaTidakLayak($payloads, $modelType))) {
            return $kosong + ['error' => $penolakan];
        }

        $dispatched = [];
        $skipped = [];
        $trainings = [];

        foreach ($payloads as $type => $data) {
            if ($modelType !== 'both' && $modelType !== $type) {
                continue;
            }

            if (count($data) < NLPApiService::MIN_RETRAIN_SAMPLES) {
                $skipped[] = sprintf(
                    '%s (%d dari minimal %d sampel)',
                    $type,
                    count($data),
                    NLPApiService::MIN_RETRAIN_SAMPLES
                );

                continue;
            }

            $training = ModelTraining::create([
                'model_type' => $type,
                'text_analysis_id' => $analysisId,
                'status' => 'pending',
                'total_samples' => count($data),
                'epochs' => $epochs,
                'learning_rate' => 0.00002,
                // null berarti dipicu sistem, bukan orang. Riwayat training
                // menampilkannya sebagai "Otomatis".
                'triggered_by' => $triggeredBy,
            ]);

            RetrainModel::dispatch($training, $data);
            $trainings[] = $training;
            $dispatched[] = sprintf('%s (%d sampel)', $type, count($data));
        }

        if (empty($dispatched)) {
            return $kosong + [
                'skipped' => $skipped,
                'error' => 'Data belum cukup untuk training: '.implode(', ', $skipped),
            ];
        }

        // Potret metrik sebelum model dilatih ulang. Snapshot berikutnya menjadi
        // pembanding untuk melihat perbaikan antar iterasi active learning.
        $this->modelEvaluation->captureSnapshot(
            $trainings[0] ?? null,
            'Sebelum retraining: '.implode(', ', $dispatched)
        );

        return [
            'dispatched' => $dispatched,
            'skipped' => $skipped,
            'trainings' => $trainings,
            'error' => null,
        ];
    }

    /**
     * Dua penjaga kelayakan, dalam satu pesan yang menyebut angkanya.
     *
     * Keduanya sengaja berada di sini dan bukan di perintah: jalur UI dan jalur
     * perintah harus menolak hal yang sama, kalau tidak tombol bisa memicu
     * pelatihan yang perintahnya tolak.
     */
    private function tolakBilaTidakLayak(array $payloads, string $modelType): ?string
    {
        $terbesar = $this->sampleCount($payloads, $modelType);

        if ($terbesar < self::MIN_LAYAK) {
            return sprintf(
                'Kolam koreksi baru %d sampel, di bawah ambang kelayakan %d. Di bawah %d, set validasi 20%%-nya '
                .'tidak sampai 10 baris sehingga keputusan menyimpan checkpoint diambil dari '
                .'pengukuran yang tidak bermakna - persis yang terjadi pada putaran pertama '
                .'(val_size 4, delta 0, checkpoint tetap disimpan). Centang "paksa" bila memang disengaja.',
                $terbesar,
                self::MIN_LAYAK,
                self::MIN_LAYAK
            );
        }

        if ($belum = $this->unmeasuredTraining($modelType)) {
            return sprintf(
                'Pelatihan sebelumnya belum diukur: pelatihan #%d (%s%s) selesai %s dan belum ada '
                .'evaluasi sesudahnya. Melatih sekarang akan MENIMPA bobot itu, dan iterasinya '
                .'tidak bisa diukur belakangan karena bobot lamanya sudah hilang. '
                .'Jalankan evaluasi dulu di /admin/evaluation, atau centang "paksa".',
                $belum->id,
                $belum->model_type,
                $belum->text_analysis_id ? ', analisis #'.$belum->text_analysis_id : '',
                $belum->completed_at
            );
        }

        return null;
    }

    /**
     * Format sesuai SentimentRetrainRequest di NLP API: [{text, label}].
     */
    private function sentimentPayload($items): array
    {
        $validLabels = ['positive', 'neutral', 'negative'];

        return $items
            ->filter(fn ($item) => in_array(strtolower((string) $item->corrected_sentiment), $validLabels, true))
            ->filter(fn ($item) => filled($item->text_content))
            ->map(fn ($item) => [
                'text' => $item->text_content,
                'label' => strtolower($item->corrected_sentiment),
            ])
            ->values()
            ->all();
    }

    /**
     * Format sesuai AspectRetrainRequest di NLP API: [{text, aspects: []}].
     */
    private function aspectPayload($items): array
    {
        return $items
            ->filter(fn ($item) => ! empty($item->corrected_aspects) && filled($item->text_content))
            ->map(fn ($item) => [
                'text' => $item->text_content,
                'aspects' => array_values(array_filter(array_map(
                    fn ($aspect) => trim((string) $aspect),
                    (array) $item->corrected_aspects
                ))),
            ])
            ->filter(fn ($row) => ! empty($row['aspects']))
            ->values()
            ->all();
    }
}
