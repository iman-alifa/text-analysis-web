<?php

namespace App\Services\Evaluation;

use App\Models\EvaluationDataset;
use App\Models\EvaluationItem;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Support\TextFingerprint;
use App\Support\TextScorability;
use RuntimeException;

/**
 * Mengambil sampel teks dari korpus yang sudah ada di sistem, untuk dianotasi
 * manusia menjadi golden dataset.
 *
 * Empat aturan yang membentuk kelas ini, semuanya berdampak langsung pada
 * keabsahan angka evaluasi:
 *
 * 1. **Acak di dalam strata, tidak pernah berdasarkan keyakinan model.**
 *    Menggoda sekali memakai antrean tinjauan (baris berkeyakinan rendah)
 *    sebagai test set - datanya sudah tersedia dan tampak "menantang". Tetapi
 *    sampel semacam itu memilih justru kasus yang paling sulit, sehingga
 *    akurasi yang terukur jauh di bawah kinerja sebenarnya pada korpus, dan
 *    tidak bisa digeneralisasi ke apa pun. Test set harus sampel peluang dari
 *    populasi yang ingin dibicarakan. Karena itu kelas ini sama sekali tidak
 *    menyediakan pilihan pengambilan berbasis keyakinan.
 *
 * 2. **Stratifikasi hanya pada sifat data, bukan pada tebakan model.**
 *    Lembaga atau video adalah sifat dokumen; label prediksi bukan.
 *    Menstratifikasi pada label prediksi akan mengubah proporsi kelas pada
 *    test set sehingga akurasi keseluruhannya tidak lagi menaksir akurasi
 *    korpus.
 *
 * 3. **Baris yang koreksinya SUDAH DILATIHKAN dikecualikan.** Baris itu
 *    pernah dilihat model; memasukkannya ke test set adalah kebocoran.
 *
 *    Koreksi yang belum pernah masuk pelatihan mana pun (mis. seluruh koreksi
 *    fase evaluasi kuesioner, saat pelatihan ditunda) BOLEH terambil. Begitu
 *    golden dataset dibekukan, penjaga di RetrainingService::correctedItems()
 *    menyingkirkannya dari setiap pelatihan sesudahnya, jadi tidak ada
 *    kebocoran. Mengecualikannya justru membuat sampel tidak acak: ajakan
 *    koreksi mendahulukan baris yang model paling ragu, sehingga test set
 *    kehilangan baris tersulit dan akurasinya menaksir terlalu tinggi.
 *    Harganya: koreksi yang terambil tidak pernah dilatihkan. Jumlahnya
 *    dicatat di rencana pengambilan (`koreksi_belum_dilatih_terambil`).
 *
 * 4. **Berkas anotasi tidak memuat prediksi model.** Anotator yang melihat
 *    tebakan model cenderung menyetujuinya, dan akurasi terukur naik tanpa
 *    modelnya berubah.
 */
class GoldenSamplingService
{
    /** Teks yang lebih pendek dari ini hampir selalu emoji atau "mantap". */
    public const MIN_PANJANG_BAWAAN = 20;

    /**
     * Analisis yang bisa dijadikan sumber sampel.
     */
    public function availableSources(): array
    {
        return TextAnalysis::query()
            ->whereNotNull('raw_data')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TextAnalysis $analysis) => [
                'id' => $analysis->id,
                'title' => $analysis->title,
                'type' => $analysis->analysis_type,
                'created_at' => $analysis->created_at,
                'text_count' => is_array($analysis->raw_data) ? count($analysis->raw_data) : 0,
                'segment_columns' => $this->segmentColumns($analysis),
            ])
            ->filter(fn ($baris) => $baris['text_count'] > 0)
            ->values()
            ->all();
    }

    public function segmentColumns(TextAnalysis $analysis): array
    {
        $data = $analysis->segment_data;

        if (! is_array($data) || empty($data['columns'])) {
            return [];
        }

        return array_values(array_filter(
            $data['columns'],
            fn ($kolom) => is_string($kolom) && $kolom !== ''
        ));
    }

    /**
     * Ambil sampel berstrata.
     *
     * @param  array{analysis_ids: array, size: int, seed?: int, stratify_by?: ?string,
     *               exclude_corrected?: bool, min_length?: int, min_per_stratum?: int}  $opsi
     * @return array{rows: array, plan: array}
     */
    public function sample(array $opsi): array
    {
        $ukuran = (int) ($opsi['size'] ?? 0);
        $persen = isset($opsi['percent']) ? (float) $opsi['percent'] : null;
        $semai = (int) ($opsi['seed'] ?? 42);
        $stratifikasi = $opsi['stratify_by'] ?? null;
        $minPanjang = (int) ($opsi['min_length'] ?? self::MIN_PANJANG_BAWAAN);
        $kecualikanKoreksi = $opsi['exclude_corrected'] ?? true;
        $minPerStrata = (int) ($opsi['min_per_stratum'] ?? 0);

        if ($persen !== null && ($persen <= 0 || $persen > 100)) {
            throw new RuntimeException('Persentase harus di antara 0 dan 100.');
        }

        if ($ukuran < 1 && $persen === null) {
            throw new RuntimeException('Sebutkan ukuran sampel, entah sebagai jumlah baris atau persentase.');
        }

        $analyses = TextAnalysis::whereIn('id', $opsi['analysis_ids'] ?? [])->get();

        if ($analyses->isEmpty()) {
            throw new RuntimeException('Tidak ada analisis sumber yang dipilih.');
        }

        $sudahDipakai = $this->hashSudahDipakai();
        $sudahDikoreksi = $kecualikanKoreksi ? $this->hashSudahDikoreksi() : [];
        $belumDilatih = $this->hashKoreksiBelumDilatih();

        $kandidat = [];
        $terlihat = [];
        $ditolak = [
            'tidak_dinilai' => 0,
            'terlalu_pendek' => 0,
            'duplikat' => 0,
            'sudah_di_golden' => 0,
            'sudah_dikoreksi' => 0,
        ];

        foreach ($analyses as $analysis) {
            $teksSemua = is_array($analysis->raw_data) ? $analysis->raw_data : [];
            $barisSegmen = $analysis->segment_data['rows'] ?? [];

            foreach ($teksSemua as $indeks => $teks) {
                $teks = trim((string) $teks);

                // Teks tanpa satu pun huruf atau angka tidak bisa dianotasi
                // manusia maupun dinilai model - memasukkannya ke test set
                // hanya menambah baris yang pasti terbuang. Diperiksa sebelum
                // panjang minimal, karena untaian emotikon panjang lolos dari
                // ambang 20 karakter.
                if (TextScorability::isUnscorable($teks)) {
                    $ditolak['tidak_dinilai']++;

                    continue;
                }

                if (mb_strlen($teks) < $minPanjang) {
                    $ditolak['terlalu_pendek']++;

                    continue;
                }

                $hash = TextFingerprint::hash($teks);

                if (isset($terlihat[$hash])) {
                    $ditolak['duplikat']++;

                    continue;
                }

                if (isset($sudahDipakai[$hash])) {
                    $ditolak['sudah_di_golden']++;

                    continue;
                }

                if (isset($sudahDikoreksi[$hash])) {
                    $ditolak['sudah_dikoreksi']++;

                    continue;
                }

                $terlihat[$hash] = true;

                $segmen = $stratifikasi
                    ? trim((string) ($barisSegmen[$indeks][$stratifikasi] ?? ''))
                    : '';

                $kandidat[] = [
                    'text' => $teks,
                    'text_hash' => $hash,
                    'gold_sentiment' => null,
                    'second_sentiment' => null,
                    'gold_aspects' => null,
                    'segment' => $segmen !== '' ? $segmen : $analysis->title,
                    'source_ref' => 'analisis#'.$analysis->id.' baris '.$indeks,
                    'note' => null,
                ];
            }
        }

        if (empty($kandidat)) {
            throw new RuntimeException('Tidak ada teks yang lolos penyaringan. Longgarkan panjang minimal atau pilih analisis lain.');
        }

        // Persentase baru bisa diterjemahkan menjadi jumlah baris SETELAH
        // penyaringan, karena penyebutnya adalah kolam yang benar-benar layak
        // dianotasi - bukan jumlah baris mentah di berkas. Pada korpus studi
        // kasus ini selisihnya 20,5%: 8.393 baris mentah menjadi 6.674 kandidat.
        // Kalau persentase dihitung dari angka mentah, "10%" akan berarti porsi
        // yang berbeda-beda tergantung berapa banyak emotikon dan duplikat yang
        // kebetulan ada.
        if ($persen !== null) {
            $ukuran = max(1, (int) round(count($kandidat) * $persen / 100));
        }

        $strata = [];
        foreach ($kandidat as $baris) {
            $strata[$baris['segment']][] = $baris;
        }
        ksort($strata);

        $alokasi = $this->allocate($strata, min($ukuran, count($kandidat)), $minPerStrata);

        mt_srand($semai);
        $terpilih = [];
        $rincianStrata = [];

        foreach ($strata as $nama => $anggota) {
            // Diurutkan lebih dulu supaya hasilnya tidak bergantung pada urutan
            // baris di basis data - semai saja tidak menjamin itu.
            usort($anggota, fn ($a, $b) => strcmp($a['text_hash'], $b['text_hash']));

            $urutan = range(0, count($anggota) - 1);
            $this->shuffleSeeded($urutan);

            $jatah = $alokasi[$nama] ?? 0;

            foreach (array_slice($urutan, 0, $jatah) as $indeks) {
                $terpilih[] = $anggota[$indeks];
            }

            $rincianStrata[$nama] = ['tersedia' => count($anggota), 'diambil' => $jatah];
        }
        mt_srand();

        // Berapa koreksi pengguna yang terambil. Karena belum pernah dilatih,
        // itu sah, tetapi koreksi itu tidak akan pernah dilatihkan sesudah
        // golden dataset dibekukan - angka yang perlu disebut di naskah.
        $belumDilatihTerambil = count(array_filter(
            $terpilih,
            fn ($baris) => isset($belumDilatih[$baris['text_hash']])
        ));

        return [
            'rows' => $terpilih,
            'plan' => [
                'analysis_ids' => $analyses->pluck('id')->all(),
                'analysis_titles' => $analyses->pluck('title')->all(),
                'requested_size' => $ukuran,
                'requested_percent' => $persen,
                'sampled' => count($terpilih),
                'candidates' => count($kandidat),
                'seed' => $semai,
                'stratify_by' => $stratifikasi,
                'min_per_stratum' => $minPerStrata,
                'min_length' => $minPanjang,
                'exclude_corrected' => $kecualikanKoreksi,
                'koreksi_belum_dilatih_terambil' => $belumDilatihTerambil,
                'rejected' => $ditolak,
                'strata' => $rincianStrata,
                'method' => 'acak berstrata, disemai; tidak memakai keyakinan model',
                'sampled_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Simpan hasil sampel sebagai dataset yang menunggu anotasi.
     */
    public function store(array $rows, array $plan, array $atribut): EvaluationDataset
    {
        $dataset = app(GoldenDatasetService::class)->store($rows, $atribut + ['source' => 'sampling']);
        $dataset->update(['sampling_plan' => $plan]);

        return $dataset->fresh();
    }

    /**
     * Isi label emas dari berkas hasil anotasi.
     *
     * Dicocokkan lewat kolom `id` yang ikut pada berkas anotasi, dengan sidik
     * jari teks sebagai cadangan. Pencocokan lewat id penting: tanpa itu,
     * anotator yang tanpa sengaja menyunting teksnya - menghapus emoji,
     * merapikan spasi - akan membuat barisnya tidak dikenali lagi dan diam-diam
     * hilang dari test set.
     *
     * @return array{filled: int, unchanged: int, unmatched: int, invalid: int}
     */
    public function applyAnnotations(EvaluationDataset $dataset, string $path): array
    {
        if ($dataset->isFrozen()) {
            throw new RuntimeException('Dataset sudah dibekukan, labelnya tidak bisa diubah lagi.');
        }

        $baris = $this->readAnnotationFile($path);

        $perId = $dataset->items()->get()->keyBy('id');
        $perHash = $perId->keyBy('text_hash');

        $terisi = 0;
        $tetap = 0;
        $tidakCocok = 0;
        $tidakSah = 0;
        $aspekTerisi = 0;

        foreach ($baris as $satu) {
            /** @var EvaluationItem|null $item */
            $item = ($satu['id'] !== null ? $perId->get($satu['id']) : null)
                ?? $perHash->get(TextFingerprint::hash((string) $satu['text']));

            if (! $item || $item->evaluation_dataset_id !== $dataset->id) {
                $tidakCocok++;

                continue;
            }

            // Aspek diperiksa terpisah dari sentimen. Dulu keduanya menumpang
            // pada satu perbandingan, sehingga unggahan yang hanya menambah
            // anotasi aspek - sementara sentimennya tidak berubah - dibuang
            // sebagai "tidak berubah" dan aspeknya hilang tanpa pesan apa pun.
            $aspekBaru = $satu['aspects'];
            $aspekBerubah = $aspekBaru !== null
                && $aspekBaru !== ($item->gold_aspects === null ? null : array_values((array) $item->gold_aspects));

            if ($satu['label'] === null) {
                // Baris tanpa label sentimen tetap boleh menyumbang anotasi
                // aspek; keduanya dinilai oleh metrik yang berbeda.
                if ($aspekBerubah) {
                    $item->update([
                        'gold_aspects' => $aspekBaru,
                        'note' => $satu['note'] ?? $item->note,
                    ]);
                    $aspekTerisi++;
                } elseif ($item->gold_sentiment === null) {
                    $tidakSah++;
                }

                continue;
            }

            $sentimenBerubah = $item->gold_sentiment !== $satu['label']
                || $item->second_sentiment !== $satu['second'];

            if (! $sentimenBerubah && ! $aspekBerubah) {
                $tetap++;

                continue;
            }

            $item->update([
                'gold_sentiment' => $satu['label'],
                'second_sentiment' => $satu['second'],
                'gold_aspects' => $aspekBaru ?? $item->gold_aspects,
                'note' => $satu['note'] ?? $item->note,
            ]);

            if ($sentimenBerubah) {
                $terisi++;
            }

            if ($aspekBerubah) {
                $aspekTerisi++;
            }
        }

        app(GoldenDatasetService::class)->refreshStats($dataset);

        return [
            'filled' => $terisi,
            'aspects_filled' => $aspekTerisi,
            'unchanged' => $tetap,
            'unmatched' => $tidakCocok,
            'invalid' => $tidakSah,
        ];
    }

    /**
     * Baris untuk berkas anotasi.
     *
     * Prediksi model TIDAK disertakan secara bawaan - lihat aturan 4 di atas.
     *
     * Bila `$denganPrediksi` dinyalakan, tiga kolom tambahan ikut: prediksi dan
     * keyakinan sentimen, serta aspek yang ditemukan model. Itu keputusan yang sah tetapi berkonsekuensi, dan
     * konsekuensinya harus disadari: anotator yang melihat tebakan model
     * cenderung menyetujuinya, sehingga akurasi yang terukur naik tanpa
     * modelnya berubah. Aplikasi anotasi karena itu menyembunyikan kolom ini
     * sampai anotator memilih labelnya sendiri, lalu mencatat apakah ia berubah
     * pikiran setelah melihatnya - angka yang justru layak dilaporkan.
     *
     * `model_aspects` memakai tiga keadaan yang sama dengan kolom `aspects`:
     * `-` berarti model memeriksa baris itu dan tidak menemukan aspek, kosong
     * berarti tidak ada data aspek (analisis sentimen saja, atau batch yang
     * gagal). Keduanya harus bisa dibedakan anotator.
     */
    public function annotationRows(EvaluationDataset $dataset, bool $denganPrediksi = false): array
    {
        $prediksi = $denganPrediksi ? $this->modelPredictions($dataset) : [];
        $aspekModel = $denganPrediksi ? $this->modelAspects($dataset) : [];

        return $dataset->items()
            ->orderBy('id')
            ->get()
            ->map(function (EvaluationItem $item) use ($denganPrediksi, $prediksi, $aspekModel) {
                $baris = [
                    $item->id,
                    $item->text,
                    $item->gold_sentiment,
                    $item->second_sentiment,
                    // `-` berarti "sudah dianotasi, tidak ada aspek"; kosong
                    // berarti "belum dianotasi". Keduanya harus bisa dibedakan.
                    is_array($item->gold_aspects)
                        ? (empty($item->gold_aspects) ? '-' : implode(';', $item->gold_aspects))
                        : '',
                    $item->segment,
                    $item->note,
                ];

                if ($denganPrediksi) {
                    $p = $prediksi[$item->text_hash] ?? null;
                    $baris[] = $p['sentiment'] ?? '';
                    $baris[] = $p['confidence'] ?? '';

                    $a = $aspekModel[$item->text_hash] ?? null;
                    $baris[] = $a === null ? '' : ($a === [] ? '-' : implode(';', $a));
                }

                return $baris;
            })
            ->all();
    }

    public function annotationHeader(bool $denganPrediksi = false): array
    {
        $header = ['id', 'text', 'label', 'label_2', 'aspects', 'segment', 'note'];

        if ($denganPrediksi) {
            $header[] = 'model_prediction';
            $header[] = 'model_confidence';
            $header[] = 'model_aspects';
        }

        return $header;
    }

    /**
     * Prediksi model untuk tiap baris golden, dicari lewat sidik jari teks.
     *
     * Dibaca dari `predictions` hasil analisis sumber, bukan dari
     * `training_items`: baris golden memang tidak selalu punya padanan di sana,
     * dan hasil analisis adalah sumber yang paling berwenang.
     *
     * @return array<string, array{sentiment: ?string, confidence: ?float}>
     */
    private function modelPredictions(EvaluationDataset $dataset): array
    {
        $ids = $dataset->sampling_plan['analysis_ids'] ?? [];

        if (empty($ids)) {
            return [];
        }

        $peta = [];

        foreach (TextAnalysis::with('result')->whereIn('id', $ids)->get() as $analysis) {
            foreach ($analysis->result?->predictions ?? [] as $p) {
                $teks = $p['text'] ?? null;

                if (! filled($teks)) {
                    continue;
                }

                $peta[TextFingerprint::hash((string) $teks)] = [
                    'sentiment' => isset($p['sentiment']) ? strtolower((string) $p['sentiment']) : null,
                    'confidence' => isset($p['confidence']) ? round((float) $p['confidence'], 4) : null,
                ];
            }
        }

        return $peta;
    }

    /**
     * Aspek yang ditemukan model untuk tiap baris golden, dicari lewat sidik
     * jari teks. Tidak ada panggilan baru ke layanan NLP.
     *
     * `document_aspects` dikunci indeks teks masukan (`raw_data`), bukan urutan
     * prediksi - NLPApiService memetakannya lewat original_index pada jalur
     * batch. Indeks yang tidak ada berarti tidak ada data, bukan "tidak ada
     * aspek", jadi baris itu tidak masuk peta sama sekali.
     *
     * Batasannya: jalur batch mengisi baris dari batch aspek yang GAGAL dengan
     * `[]`, bukan menghilangkannya, dan kegagalan itu tidak tersimpan pada hasil
     * analisis. Baris seperti itu karena itu tampil `-` di sini. Hanya
     * menyangkut tampilan tebakan di aplikasi anotasi; evaluasi hold-out
     * menolak hasil parsial sendiri (HoldoutEvaluationService).
     *
     * Bentuk permukaan yang disimpan, bukan bentuk dasar: kolom ini dibaca
     * manusia, dan "pelayanannya" lebih mudah dikenali di kalimatnya daripada
     * "layan".
     *
     * @return array<string, list<string>>
     */
    private function modelAspects(EvaluationDataset $dataset): array
    {
        $ids = $dataset->sampling_plan['analysis_ids'] ?? [];

        if (empty($ids)) {
            return [];
        }

        $peta = [];

        foreach (TextAnalysis::with('result')->whereIn('id', $ids)->get() as $analysis) {
            $dokumen = $analysis->result?->document_aspects;

            if (! is_array($dokumen)) {
                continue;
            }

            foreach ((array) $analysis->raw_data as $indeks => $teks) {
                if (! filled($teks) || ! array_key_exists($indeks, $dokumen)) {
                    continue;
                }

                $daftar = [];

                foreach ((array) $dokumen[$indeks] as $satu) {
                    // document_aspects pernah dikirim sebagai daftar string dan
                    // sebagai daftar objek {aspect: ...}; keduanya masih beredar.
                    $nama = is_array($satu) ? ($satu['aspect'] ?? null) : $satu;
                    $nama = trim(str_replace(';', ' ', (string) $nama));

                    if ($nama !== '') {
                        $daftar[mb_strtolower($nama)] ??= $nama;
                    }
                }

                $peta[TextFingerprint::hash((string) $teks)] = array_values($daftar);
            }
        }

        return $peta;
    }

    // ------------------------------------------------------------------ util

    /**
     * Alokasi proporsional dengan metode sisa terbesar.
     *
     * Proporsional dijaga karena itulah yang membuat taksiran keseluruhan tidak
     * bias terhadap korpus. `min_per_stratum` di atas nol memang membantu
     * segmen kecil punya cukup baris untuk dilaporkan sendiri, tetapi
     * konsekuensinya harus disadari: angka keseluruhan lalu menaksir rerata
     * antar-strata, bukan rerata korpus.
     */
    private function allocate(array $strata, int $ukuran, int $minPerStrata): array
    {
        $totalKandidat = array_sum(array_map('count', $strata));
        $alokasi = [];
        $sisa = [];

        foreach ($strata as $nama => $anggota) {
            $ideal = $ukuran * count($anggota) / $totalKandidat;
            $dasar = min((int) floor($ideal), count($anggota));
            $dasar = max($dasar, min($minPerStrata, count($anggota)));

            $alokasi[$nama] = $dasar;
            $sisa[$nama] = $ideal - $dasar;
        }

        // Sisa dibagikan ke strata dengan pecahan terbesar, sampai target
        // tercapai atau tidak ada lagi kandidat yang tersisa.
        arsort($sisa);

        while (array_sum($alokasi) < $ukuran) {
            $bertambah = false;

            foreach (array_keys($sisa) as $nama) {
                if (array_sum($alokasi) >= $ukuran) {
                    break;
                }

                if ($alokasi[$nama] < count($strata[$nama])) {
                    $alokasi[$nama]++;
                    $bertambah = true;
                }
            }

            if (! $bertambah) {
                break;
            }
        }

        // Bila floor minimum membuat totalnya melewati target, kelebihannya
        // diambil dari strata terbesar lebih dulu supaya proporsinya paling
        // sedikit terganggu.
        while (array_sum($alokasi) > $ukuran) {
            $terbesar = null;
            foreach ($alokasi as $nama => $jumlah) {
                if ($jumlah > $minPerStrata && ($terbesar === null || $jumlah > $alokasi[$terbesar])) {
                    $terbesar = $nama;
                }
            }

            if ($terbesar === null) {
                break;
            }

            $alokasi[$terbesar]--;
        }

        return $alokasi;
    }

    /**
     * @return array<string, true>
     */
    private function hashSudahDipakai(): array
    {
        return array_fill_keys(EvaluationItem::pluck('text_hash')->all(), true);
    }

    /**
     * @return array<string, true>
     */
    /**
     * Koreksi yang BELUM pernah masuk pelatihan mana pun.
     *
     * @return array<string, true>
     */
    private function hashKoreksiBelumDilatih(): array
    {
        $latihTerakhir = \App\Models\ModelTraining::latest('created_at')->value('created_at');
        $hash = [];

        TrainingItem::whereHas('textAnalysis')
            ->where('is_corrected', true)
            ->when($latihTerakhir, fn ($q) => $q->where('verified_at', '>', $latihTerakhir))
            ->select(['id', 'text_content'])
            ->chunk(500, function ($potongan) use (&$hash) {
                foreach ($potongan as $item) {
                    $hash[TextFingerprint::hash((string) $item->text_content)] = true;
                }
            });

        return $hash;
    }

    private function hashSudahDikoreksi(): array
    {
        $hash = [];

        // Hanya koreksi yang sudah pernah masuk pelatihan: terverifikasi
        // sebelum pelatihan TERAKHIR dibuat (koreksi tanpa verified_at adalah
        // data lama dan dianggap sudah dilatihkan). Tanpa pelatihan sama sekali,
        // tidak ada yang bocor.
        $latihTerakhir = \App\Models\ModelTraining::latest('created_at')->value('created_at');

        if (! $latihTerakhir) {
            return [];
        }

        TrainingItem::whereHas('textAnalysis')
            ->where('is_corrected', true)
            ->where(fn ($q) => $q->whereNull('verified_at')->orWhere('verified_at', '<=', $latihTerakhir))
            ->select(['id', 'text_content'])
            ->chunk(500, function ($potongan) use (&$hash) {
                foreach ($potongan as $item) {
                    $hash[TextFingerprint::hash((string) $item->text_content)] = true;
                }
            });

        return $hash;
    }

    private function readAnnotationFile(string $path): array
    {
        $mentah = str_ends_with(strtolower($path), '.csv')
            ? $this->readCsv($path)
            : (new GoldenDatasetService)->readSpreadsheet($path);

        if (empty($mentah)) {
            throw new RuntimeException('Berkas anotasi kosong atau tidak terbaca.');
        }

        $header = array_map(fn ($k) => mb_strtolower(trim((string) $k)), array_shift($mentah));
        $peta = array_flip($header);

        if (! isset($peta['text']) && ! isset($peta['id'])) {
            throw new RuntimeException('Berkas harus memuat kolom `id` atau `text`.');
        }

        $golden = new GoldenDatasetService;
        $hasil = [];

        foreach ($mentah as $kolom) {
            $hasil[] = [
                'id' => isset($peta['id']) && is_numeric($kolom[$peta['id']] ?? null)
                    ? (int) $kolom[$peta['id']]
                    : null,
                'text' => $kolom[$peta['text']] ?? '',
                'label' => $golden->normalizeLabel($kolom[$peta['label']] ?? null),
                'second' => isset($peta['label_2'])
                    ? $golden->normalizeLabel($kolom[$peta['label_2']] ?? null)
                    : null,
                'aspects' => isset($peta['aspects'])
                    ? $golden->splitAspects($kolom[$peta['aspects']] ?? null)
                    : null,
                'note' => isset($peta['note']) ? (trim((string) ($kolom[$peta['note']] ?? '')) ?: null) : null,
            ];
        }

        return $hasil;
    }

    private function readCsv(string $path): array
    {
        $baris = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Berkas tidak bisa dibuka: {$path}");
        }

        $pertama = fgets($handle);
        rewind($handle);
        $pemisah = substr_count((string) $pertama, ';') > substr_count((string) $pertama, ',') ? ';' : ',';

        while (($kolom = fgetcsv($handle, 0, $pemisah)) !== false) {
            $baris[] = $kolom;
        }

        fclose($handle);

        if (isset($baris[0][0])) {
            $baris[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $baris[0][0]);
        }

        return $baris;
    }

    private function shuffleSeeded(array &$data): void
    {
        for ($i = count($data) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$data[$i], $data[$j]] = [$data[$j], $data[$i]];
        }
    }
}
