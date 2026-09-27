<?php

namespace App\Services\Evaluation;

use App\Models\EvaluationDataset;
use App\Models\EvaluationItem;
use App\Models\TrainingItem;
use App\Services\NLPApiService;
use App\Support\TextFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Pengelolaan golden dataset: impor, pemecahan train/test, pembekuan, dan
 * pemeriksaan kebocoran terhadap data latih.
 *
 * Tiga janji yang dijaga kelas ini, karena tanpa ketiganya angka hold-out
 * tidak berarti apa-apa:
 *
 * 1. Tidak ada teks duplikat di dalam satu dataset. Teks yang sama dihitung
 *    dua kali akan menaikkan atau menurunkan metrik tanpa alasan.
 * 2. Dataset yang sudah dibekukan tidak bisa berubah. Dua run pada iterasi
 *    berbeda harus mengukur baris yang persis sama, atau selisihnya bukan
 *    perbaikan model.
 * 3. Kebocoran ke data latih bisa diperiksa kapan saja, dan dicegah di sisi
 *    pelatihan (lihat RetrainingService).
 */
class GoldenDatasetService
{
    public const LABEL_SAH = ['positive', 'neutral', 'negative'];

    public function __construct(private ?NLPApiService $nlpApi = null) {}

    /**
     * Sinonim kolom yang diterima saat impor. Berkas anotasi biasanya dibuat
     * di spreadsheet dan nama kolomnya jarang seragam.
     */
    private const ALIAS = [
        'text' => ['text', 'teks', 'komentar', 'kalimat', 'content', 'text_content'],
        'gold_sentiment' => ['label', 'sentimen', 'sentiment', 'gold', 'gold_sentiment', 'label_1'],
        'second_sentiment' => ['label_2', 'label2', 'sentimen_2', 'anotator_2', 'annotator_2'],
        'gold_aspects' => ['aspects', 'aspek', 'gold_aspects'],
        'segment' => ['segment', 'segmen', 'lembaga', 'sumber', 'video', 'kelompok'],
        'source_ref' => ['source_ref', 'sumber_ref', 'url', 'link', 'video_id'],
        'note' => ['note', 'catatan', 'keterangan'],
    ];

    /**
     * Baca berkas anotasi menjadi baris ternormalisasi.
     *
     * Tidak langsung menyimpan: pemanggil boleh memecahnya lebih dulu menjadi
     * train/test, dan impor yang gagal separuh jalan lebih buruk daripada
     * impor yang ditolak sebelum menyentuh basis data.
     *
     * @return array{rows: array, errors: array, columns: array}
     */
    public function readFile(string $path): array
    {
        $baris = str_ends_with(strtolower($path), '.csv')
            ? $this->readCsv($path)
            : $this->readSpreadsheet($path);

        if (empty($baris)) {
            throw new RuntimeException('Berkas kosong atau tidak terbaca.');
        }

        $header = array_map(
            fn ($kolom) => mb_strtolower(trim((string) $kolom)),
            array_shift($baris)
        );

        $peta = $this->mapColumns($header);

        if (! isset($peta['text'])) {
            throw new RuntimeException(
                'Kolom teks tidak ditemukan. Nama yang diterima: '.implode(', ', self::ALIAS['text'])
            );
        }

        if (! isset($peta['gold_sentiment'])) {
            throw new RuntimeException(
                'Kolom label tidak ditemukan. Nama yang diterima: '.implode(', ', self::ALIAS['gold_sentiment'])
            );
        }

        $hasil = [];
        $galat = [];
        $terlihat = [];

        foreach ($baris as $nomor => $kolom) {
            // +2: satu untuk header, satu karena penomoran berkas mulai dari 1.
            $nomorBaris = $nomor + 2;
            $teks = trim((string) ($kolom[$peta['text']] ?? ''));

            if ($teks === '') {
                continue;
            }

            $label = $this->normalizeLabel($kolom[$peta['gold_sentiment']] ?? null);

            if ($label === null) {
                $galat[] = "Baris {$nomorBaris}: label tidak dikenali (harus positive/neutral/negative).";

                continue;
            }

            $hash = TextFingerprint::hash($teks);

            if (isset($terlihat[$hash])) {
                $galat[] = "Baris {$nomorBaris}: teks duplikat dengan baris {$terlihat[$hash]}, dilewati.";

                continue;
            }

            $terlihat[$hash] = $nomorBaris;

            $hasil[] = [
                'text' => $teks,
                'text_hash' => $hash,
                'gold_sentiment' => $label,
                'second_sentiment' => isset($peta['second_sentiment'])
                    ? $this->normalizeLabel($kolom[$peta['second_sentiment']] ?? null)
                    : null,
                'gold_aspects' => isset($peta['gold_aspects'])
                    ? $this->splitAspects($kolom[$peta['gold_aspects']] ?? null)
                    : null,
                'segment' => isset($peta['segment']) ? (trim((string) ($kolom[$peta['segment']] ?? '')) ?: null) : null,
                'source_ref' => isset($peta['source_ref']) ? (trim((string) ($kolom[$peta['source_ref']] ?? '')) ?: null) : null,
                'note' => isset($peta['note']) ? (trim((string) ($kolom[$peta['note']] ?? '')) ?: null) : null,
            ];
        }

        return ['rows' => $hasil, 'errors' => $galat, 'columns' => array_keys($peta)];
    }

    /**
     * Simpan baris menjadi satu golden dataset.
     */
    public function store(array $rows, array $atribut): EvaluationDataset
    {
        if (empty($rows)) {
            throw new RuntimeException('Tidak ada baris yang bisa disimpan.');
        }

        return DB::transaction(function () use ($rows, $atribut) {
            $dataset = EvaluationDataset::create([
                'name' => $atribut['name'],
                'description' => $atribut['description'] ?? null,
                'split' => $atribut['split'] ?? 'test',
                'source' => $atribut['source'] ?? 'import',
                'created_by' => $atribut['created_by'] ?? null,
            ]);

            foreach (array_chunk($rows, 200) as $potongan) {
                foreach ($potongan as $baris) {
                    $dataset->items()->create($baris);
                }
            }

            $this->refreshStats($dataset);

            return $dataset->fresh();
        });
    }

    /**
     * Pecah baris berlabel menjadi test dan sisa (train) secara berstrata.
     *
     * Berstrata terhadap label - dan terhadap segmen bila ada - supaya proporsi
     * kelas pada test set menyerupai korpusnya. Pemecahan acak biasa pada n
     * kecil mudah menghasilkan test set yang nyaris tanpa kelas minoritas,
     * dan metrik makro-nya lalu berayun liar.
     *
     * Semai wajib dan dicatat: pemecahan yang tidak bisa diulang membuat
     * seluruh angka evaluasi tidak bisa direproduksi.
     *
     * @return array{test: array, train: array, seed: int, strata: array}
     */
    public function stratifiedSplit(array $rows, float $testRatio = 0.2, int $seed = 42, bool $bySegment = true): array
    {
        if ($testRatio <= 0 || $testRatio >= 1) {
            throw new RuntimeException('Rasio test harus di antara 0 dan 1.');
        }

        $strata = [];

        foreach ($rows as $baris) {
            $kunci = $baris['gold_sentiment'];

            if ($bySegment && ! empty($baris['segment'])) {
                $kunci .= '|'.$baris['segment'];
            }

            $strata[$kunci][] = $baris;
        }

        ksort($strata);

        mt_srand($seed);
        $test = [];
        $train = [];
        $ringkasan = [];

        foreach ($strata as $kunci => $anggota) {
            // Diurutkan lebih dulu supaya hasilnya tidak bergantung pada urutan
            // baris di berkas sumber - semai saja tidak cukup untuk itu.
            usort($anggota, fn ($a, $b) => strcmp($a['text_hash'], $b['text_hash']));
            $urutan = range(0, count($anggota) - 1);
            $this->shuffleSeeded($urutan);

            $jumlahTest = (int) round(count($anggota) * $testRatio);

            // Strata yang sangat kecil tetap menyumbang minimal satu baris uji,
            // selama masih menyisakan baris untuk dilatih.
            if ($jumlahTest === 0 && count($anggota) >= 2) {
                $jumlahTest = 1;
            }

            foreach ($urutan as $posisi => $indeks) {
                if ($posisi < $jumlahTest) {
                    $test[] = $anggota[$indeks];
                } else {
                    $train[] = $anggota[$indeks];
                }
            }

            $ringkasan[$kunci] = ['total' => count($anggota), 'test' => $jumlahTest];
        }

        mt_srand();

        return ['test' => $test, 'train' => $train, 'seed' => $seed, 'strata' => $ringkasan];
    }

    /**
     * Bekukan dataset. Setelah ini isinya tidak boleh berubah lagi.
     */
    public function freeze(EvaluationDataset $dataset): EvaluationDataset
    {
        if ($dataset->items()->count() === 0) {
            throw new RuntimeException('Dataset kosong tidak bisa dibekukan.');
        }

        // Baris tanpa label emas tidak bisa dinilai, dan membekukannya berarti
        // mengunci test set yang sebagian barisnya percuma. Lebih baik ditolak
        // di sini daripada baru ketahuan saat metriknya menghitung n yang lebih
        // kecil dari jumlah baris.
        if (($kosong = $dataset->unlabeledCount()) > 0) {
            throw new RuntimeException(
                "Masih ada {$kosong} baris tanpa label emas. Selesaikan anotasinya, atau hapus baris itu, sebelum membekukan."
            );
        }

        $this->normalizeGoldAspects($dataset);
        $this->refreshStats($dataset);

        $dataset->update([
            'frozen_at' => now(),
            // Sidik jari isi dataset. Pembekuan sudah dijaga aplikasi, tetapi
            // hash ini membuktikan kepada pembaca naskah bahwa iterasi 0 dan
            // iterasi terakhir benar-benar mengukur baris yang sama - tanpa
            // perlu mempercayai aplikasinya.
            'content_hash' => $this->contentHash($dataset),
        ]);

        return $dataset->fresh();
    }

    /**
     * Sidik jari isi dataset: hash dari seluruh text_hash yang diurutkan.
     */
    public function contentHash(EvaluationDataset $dataset): string
    {
        $hash = $dataset->items()->orderBy('text_hash')->pluck('text_hash')->all();

        return hash('sha256', implode('', $hash));
    }

    /**
     * Isi bentuk dasar untuk label aspek emas, sekali seumur dataset.
     *
     * Dipanggil saat pembekuan karena itu satu-satunya saat isi dataset pasti
     * sudah final. Kalau layanan tidak terjangkau, kolomnya dibiarkan kosong
     * dan evaluasi aspek akan menolak menghitung - lebih baik tidak ada angka
     * daripada angka yang salah telak.
     *
     * @return bool berhasil atau tidak
     */
    public function normalizeGoldAspects(EvaluationDataset $dataset): bool
    {
        // whereNotNull, bukan yang tidak kosong: baris yang sudah dianotasi
        // TANPA aspek (`[]`) juga harus punya bentuk dasar `[]`, supaya ia ikut
        // dinilai dan aspek yang dikarang model di sana terhitung sebagai
        // false positive.
        $items = $dataset->items()->whereNotNull('gold_aspects')->get();

        if ($items->isEmpty()) {
            return true;
        }

        $semua = [];
        foreach ($items as $item) {
            foreach ((array) $item->gold_aspects as $aspek) {
                $semua[] = (string) $aspek;
            }
        }

        if (empty($semua)) {
            // Semua baris dianotasi tanpa aspek - tidak ada yang perlu ditanyakan
            // ke layanan, tetapi bentuk dasarnya tetap harus diisi.
            foreach ($items as $item) {
                $item->update(['gold_aspects_normalized' => []]);
            }

            return true;
        }

        $peta = ($this->nlpApi ?? app(NLPApiService::class))->normalizeAspectNames($semua);

        if ($peta === null) {
            Log::warning("Bentuk dasar aspek emas gagal diambil untuk dataset #{$dataset->id}; metrik aspek tidak akan dihitung.");

            return false;
        }

        foreach ($items as $item) {
            $dasar = [];

            foreach ((array) $item->gold_aspects as $aspek) {
                $nilai = $peta[(string) $aspek] ?? null;

                if (filled($nilai) && ! in_array($nilai, $dasar, true)) {
                    $dasar[] = $nilai;
                }
            }

            sort($dasar);
            $item->update(['gold_aspects_normalized' => $dasar]);
        }

        return true;
    }

    /**
     * Buka pembekuan - hanya selama belum pernah dievaluasi.
     *
     * Begitu ada run, mengubah isinya membuat run lama membicarakan dataset
     * yang tidak ada lagi, dan perbandingan antar iterasi jadi menyesatkan.
     */
    public function unfreeze(EvaluationDataset $dataset): EvaluationDataset
    {
        if ($dataset->runs()->exists()) {
            throw new RuntimeException(
                'Dataset ini sudah pernah dievaluasi. Membukanya akan membuat run lama tidak sebanding. Buat dataset baru bila perlu diubah.'
            );
        }

        $dataset->update(['frozen_at' => null]);

        return $dataset->fresh();
    }

    /**
     * Hitung ulang statistik dan reliabilitas anotasi.
     */
    public function refreshStats(EvaluationDataset $dataset): void
    {
        $items = $dataset->items()->get();

        $distribusi = [];
        foreach (self::LABEL_SAH as $label) {
            $distribusi[$label] = $items->where('gold_sentiment', $label)->count();
        }

        $berAspek = $items->filter(fn ($item) => ! empty($item->gold_aspects))->count();

        $dataset->update([
            'item_count' => $items->count(),
            'label_distribution' => $distribusi + [
                'dengan_aspek' => $berAspek,
                'segmen' => $items->pluck('segment')->filter()->unique()->count(),
                // Dataset hasil pengambilan sampel lahir tanpa label; angka ini
                // yang dipakai halaman evaluasi untuk menunjukkan kemajuan anotasi.
                'belum_dilabeli' => $items->whereNull('gold_sentiment')->count(),
            ],
            'annotation' => $this->annotationReliability($items),
        ]);
    }

    /**
     * Cohen kappa antar dua anotator, bila kolom anotator kedua diisi.
     *
     * Ini yang membuat golden dataset bisa dipertanggungjawabkan: tanpa angka
     * kesepakatan, label emas hanyalah pendapat satu orang, dan batas atas
     * kinerja model tidak diketahui.
     */
    public function annotationReliability($items): ?array
    {
        $berpasangan = $items->filter(fn ($item) => filled($item->second_sentiment));

        if ($berpasangan->count() < 2) {
            return null;
        }

        $labels = self::LABEL_SAH;
        $matriks = [];
        foreach ($labels as $a) {
            foreach ($labels as $b) {
                $matriks[$a][$b] = 0;
            }
        }

        $n = 0;
        $sepakat = 0;

        foreach ($berpasangan as $item) {
            $a = $item->gold_sentiment;
            $b = $item->second_sentiment;

            if (! in_array($a, $labels, true) || ! in_array($b, $labels, true)) {
                continue;
            }

            $matriks[$a][$b]++;
            $n++;

            if ($a === $b) {
                $sepakat++;
            }
        }

        if ($n === 0) {
            return null;
        }

        $pe = 0.0;
        foreach ($labels as $label) {
            $baris = array_sum($matriks[$label]);
            $kolom = 0;
            foreach ($labels as $lain) {
                $kolom += $matriks[$lain][$label];
            }
            $pe += ($baris / $n) * ($kolom / $n);
        }

        $po = $sepakat / $n;
        $kappa = $pe < 1.0 ? ($po - $pe) / (1 - $pe) : 0.0;

        return [
            'double_annotated' => $n,
            'agreement' => round($po * 100, 2),
            'cohen_kappa' => round($kappa, 4),
            'label' => $this->labelKappa($kappa),
        ];
    }

    /**
     * Rambu penafsiran kappa (Landis & Koch, 1977).
     */
    public function labelKappa(float $kappa): string
    {
        return match (true) {
            $kappa < 0.0 => 'Lebih buruk dari kebetulan',
            $kappa < 0.21 => 'Sangat lemah',
            $kappa < 0.41 => 'Lemah',
            $kappa < 0.61 => 'Sedang',
            $kappa < 0.81 => 'Kuat',
            default => 'Sangat kuat',
        };
    }

    /**
     * Sidik jari seluruh baris pada dataset beku - dipakai penjaga kebocoran
     * di RetrainingService.
     *
     * Hanya dataset yang sudah dibekukan yang dianggap mengikat. Dataset yang
     * masih disusun belum tentu jadi test set, dan memblokir data latih karena
     * berkas percobaan akan membuang koreksi yang sah.
     *
     * @return array<string, true>
     */
    public function frozenHashes(): array
    {
        $hashes = EvaluationItem::whereHas(
            'dataset',
            fn ($query) => $query->whereNotNull('frozen_at')->where('split', 'test')
        )->pluck('text_hash')->all();

        return array_fill_keys($hashes, true);
    }

    /**
     * Sidik jari SELURUH baris golden, termasuk dataset yang belum dibekukan.
     *
     * Sengaja berbeda cakupannya dari frozenHashes(), dan perbedaannya penting:
     *
     * - Untuk MENAHAN DATA LATIH, hanya dataset beku yang mengikat. Membuang
     *   koreksi yang sah karena ada berkas percobaan akan merugikan pelatihan.
     * - Untuk MENYEMBUNYIKAN DARI HALAMAN KOREKSI, begitu sebuah baris terpilih
     *   menjadi kandidat uji ia tidak boleh lagi ditawarkan - walau datasetnya
     *   masih dianotasi. Kalau tidak, ada jendela waktu ketika baris yang sedang
     *   dianotasi manusia juga dikoreksi manusia di halaman lain, dan pelatihan
     *   ulang bisa terpicu di antara keduanya.
     *
     * Menyembunyikan terlalu banyak hanya berbiaya beberapa ratus baris yang
     * tidak ditawarkan dari ribuan; menahan terlalu banyak berbiaya data latih.
     *
     * @return array<string, true>
     */
    public function reservedHashes(): array
    {
        return array_fill_keys(EvaluationItem::pluck('text_hash')->all(), true);
    }

    /**
     * Berapa banyak baris data latih yang bertabrakan dengan golden dataset.
     *
     * PENTING - laporan ini memuat DUA angka yang berbeda dan tidak boleh
     * tertukar saat dikutip di naskah:
     *
     *   overlap         jumlah BARIS DATA LATIH yang teksnya cocok dengan
     *                   golden dataset. Satu teks golden bisa muncul pada
     *                   beberapa training_items - teks yang sama ikut pada
     *                   lebih dari satu analisis, atau dikoreksi lebih dari
     *                   sekali - sehingga angka ini BISA MELEBIHI total_golden.
     *                   Ia menghitung sisi data latih, bukan sisi data uji.
     *
     *   golden_affected jumlah BARIS GOLDEN BERBEDA yang tersentuh oleh kolam
     *                   KANDIDAT. Angka ini mendekati 100% secara wajar, sebab
     *                   training_items memuat SETIAP baris hasil analisis dan
     *                   golden dataset justru diambil dari analisis yang sama.
     *                   Berada di kolam kandidat BUKAN berarti pernah dilatih.
     *
     *   corrected_overlap / corrected_golden_affected
     *                   bagian yang berstatus is_corrected, yaitu satu-satunya
     *                   yang benar-benar masuk muatan pelatihan lewat
     *                   RetrainingService::correctedItems(). INILAH angka
     *                   kebocoran yang sesungguhnya; nol berarti tidak ada
     *                   baris uji yang pernah dilatihkan.
     *
     * Tanpa pemisahan ini, laporan bisa terbaca seolah "seluruh 300 baris uji
     * bocor", padahal yang terjadi hanyalah baris uji ikut terdaftar sebagai
     * kandidat dan tidak satu pun pernah dikoreksi sehingga tidak pernah
     * dilatihkan.
     *
     * @return array{overlap: int, golden_affected: int, corrected_overlap: int, corrected_golden_affected: int, total_golden: int, examples: array}
     */
    public function leakageReport(?EvaluationDataset $dataset = null): array
    {
        $hashes = $dataset
            ? array_fill_keys($dataset->items()->pluck('text_hash')->all(), true)
            : $this->frozenHashes();

        if (empty($hashes)) {
            return [
                'overlap' => 0,
                'golden_affected' => 0,
                'corrected_overlap' => 0,
                'corrected_golden_affected' => 0,
                'total_golden' => 0,
                'examples' => [],
            ];
        }

        $tumpang = 0;
        $tumpangTerkoreksi = 0;
        $contoh = [];
        // Kunci hash golden yang tersentuh; dipakai agar satu teks golden yang
        // muncul berkali-kali di training_items tetap dihitung satu baris uji.
        $golderTersentuh = [];
        $golderTersentuhTerkoreksi = [];

        TrainingItem::whereHas('textAnalysis')
            ->select(['id', 'text_content', 'is_corrected'])
            ->chunk(500, function ($potongan) use (
                $hashes,
                &$tumpang,
                &$tumpangTerkoreksi,
                &$contoh,
                &$golderTersentuh,
                &$golderTersentuhTerkoreksi
            ) {
                foreach ($potongan as $item) {
                    $sidik = TextFingerprint::hash((string) $item->text_content);

                    if (! isset($hashes[$sidik])) {
                        continue;
                    }

                    $tumpang++;
                    $golderTersentuh[$sidik] = true;

                    if ($item->is_corrected) {
                        $tumpangTerkoreksi++;
                        $golderTersentuhTerkoreksi[$sidik] = true;
                    }

                    if (count($contoh) < 5) {
                        $contoh[] = mb_substr((string) $item->text_content, 0, 120);
                    }
                }
            });

        return [
            'overlap' => $tumpang,
            'golden_affected' => count($golderTersentuh),
            'corrected_overlap' => $tumpangTerkoreksi,
            'corrected_golden_affected' => count($golderTersentuhTerkoreksi),
            'total_golden' => count($hashes),
            'examples' => $contoh,
        ];
    }

    // ------------------------------------------------------------------ util

    private function mapColumns(array $header): array
    {
        $peta = [];

        foreach (self::ALIAS as $kanonik => $alias) {
            foreach ($header as $indeks => $nama) {
                if (in_array($nama, $alias, true)) {
                    $peta[$kanonik] = $indeks;
                    break;
                }
            }
        }

        return $peta;
    }

    /** Dipakai juga EvaluationMergeAnnotators saat membaca berkas ekspor anotator. */
    public function readCsv(string $path): array
    {
        $baris = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Berkas tidak bisa dibuka: {$path}");
        }

        // Pemisah ditebak dari baris pertama: berkas ekspor spreadsheet
        // Indonesia sering memakai titik koma.
        $pertama = fgets($handle);
        rewind($handle);
        $pemisah = substr_count((string) $pertama, ';') > substr_count((string) $pertama, ',') ? ';' : ',';

        while (($kolom = fgetcsv($handle, 0, $pemisah)) !== false) {
            $baris[] = $kolom;
        }

        fclose($handle);

        // BOM UTF-8 menempel di nama kolom pertama dan membuat pencocokan gagal.
        if (isset($baris[0][0])) {
            $baris[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $baris[0][0]);
        }

        return $baris;
    }

    /** Dipakai juga GoldenSamplingService saat membaca berkas anotasi. */
    public function readSpreadsheet(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();

        return $sheet->toArray(null, true, false, false);
    }

    public function normalizeLabel(mixed $nilai): ?string
    {
        $nilai = mb_strtolower(trim((string) $nilai));

        $peta = [
            'positive' => 'positive', 'positif' => 'positive', 'pos' => 'positive', '1' => 'positive',
            'neutral' => 'neutral', 'netral' => 'neutral', 'neu' => 'neutral', '0' => 'neutral',
            'negative' => 'negative', 'negatif' => 'negative', 'neg' => 'negative', '-1' => 'negative',
        ];

        return $peta[$nilai] ?? null;
    }

    /**
     * Daftar aspek emas dari satu sel.
     *
     * Membedakan tiga keadaan, dan perbedaan ketiganya menentukan keabsahan
     * metrik aspek:
     *
     * - `null`  : belum dianotasi. Baris ini tidak dinilai sama sekali.
     * - `[]`    : sudah dianotasi, dan memang TIDAK ADA aspek di teksnya.
     * - `[...]` : sudah dianotasi, ada aspeknya.
     *
     * Keadaan kedua wajib bisa dinyatakan. Tanpa itu, baris tanpa aspek tidak
     * pernah masuk hitungan, sehingga aspek yang dikarang model pada komentar
     * yang sebenarnya tidak membicarakan apa pun tidak pernah terhitung sebagai
     * false positive - dan presisi yang dilaporkan menjadi terlalu tinggi.
     *
     * Penandanya `-` (juga `tidak ada` / `none`), dipilih karena satu kolom
     * lebih mudah diisi di spreadsheet daripada kolom penanda terpisah.
     */
    public function splitAspects(mixed $nilai): ?array
    {
        $nilai = trim((string) $nilai);

        if ($nilai === '') {
            return null;
        }

        if (in_array(mb_strtolower($nilai), ['-', 'tidak ada', 'tidak', 'none', 'nihil'], true)) {
            return [];
        }

        $bagian = preg_split('/[;,|]/', $nilai) ?: [];
        $bersih = array_values(array_filter(array_map(
            fn ($aspek) => mb_strtolower(trim($aspek)),
            $bagian
        ), fn ($aspek) => $aspek !== ''));

        return $bersih;
    }

    /**
     * Fisher-Yates memakai mt_rand supaya urutannya ditentukan mt_srand.
     * shuffle() bawaan PHP tidak dijamin memakai semai yang sama antar versi.
     */
    private function shuffleSeeded(array &$data): void
    {
        for ($i = count($data) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$data[$i], $data[$j]] = [$data[$j], $data[$i]];
        }
    }
}
