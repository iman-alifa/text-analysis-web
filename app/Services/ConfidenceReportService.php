<?php

namespace App\Services;

use App\Models\AnalysisResult;
use App\Services\Evaluation\GoldenDatasetService;
use App\Support\TextFingerprint;
use App\Support\TextScorability;
use Illuminate\Support\Facades\Cache;

/**
 * Merangkum seberapa yakin model pada korpus yang dikirim pengguna, dan
 * berapa baris yang layak ditawarkan untuk dikoreksi.
 *
 * Dihitung di Laravel dari `predictions`, bukan hanya mengandalkan
 * `metrics.review_queue` dari NLP API. Alasannya konkret: pada basis data
 * proyek ini `review_queue` kosong untuk seluruh analisis yang sudah ada,
 * sehingga ajakan berpartisipasi tidak pernah muncul sama sekali. Keyakinan
 * per prediksi selalu tersimpan, jadi perhitungan ini berlaku juga untuk
 * analisis lama.
 *
 * Ambangnya tetap mendahulukan angka dari API bila ada - model lebih berwenang
 * menentukan batas keyakinannya sendiri daripada konfigurasi sisi web.
 */
class ConfidenceReportService
{
    /**
     * Berapa lama ambang dari /health disimpan. Nilainya nyaris tidak
     * pernah berubah, jadi satu panggilan per jam sudah cukup - halaman
     * hasil tidak boleh menunggu HTTP ke layanan NLP setiap kali dibuka.
     */
    private const CACHE_TTL = 3600;

    public function __construct(private NLPApiService $nlpApi, private GoldenDatasetService $golden) {}

    /**
     * @return array{
     *     scored: int, unscored: int, average: float, median: float,
     *     threshold: float, threshold_source: string,
     *     low_count: int, low_share: float,
     *     bands: array, verdict: array, actionable: bool
     * }|null
     */
    public function build(AnalysisResult $result): ?array
    {
        $skor = $this->skor($result);

        if (empty($skor['nilai'])) {
            return null;
        }

        $nilai = $skor['nilai'];
        sort($nilai);

        $threshold = $this->threshold($result);
        $rendah = array_values(array_filter($nilai, fn ($x) => $x < $threshold['value']));
        $bands = $this->bands($nilai, $threshold['value']);

        $rerata = array_sum($nilai) / count($nilai);

        return [
            'scored' => count($nilai),
            'unscored' => $skor['tanpa_nilai'],
            'average' => round($rerata, 4),
            'median' => round($this->median($nilai), 4),
            'threshold' => $threshold['value'],
            'threshold_source' => $threshold['source'],
            'low_count' => count($rendah),
            'low_share' => round((count($rendah) / count($nilai)) * 100, 1),
            'bands' => $bands,
            'band_bounds' => $this->batasPita($threshold['value']),
            'verdict' => $this->verdict($rerata, count($rendah) / count($nilai), $threshold['value']),
            // Ajakan active learning HANYA meminta tinjauan pita "Sangat ragu"
            // dulu - bukan seluruh yang di bawah ambang tinjauan (yang jauh
            // lebih banyak, gabungan pita "Ragu" dan "Sangat ragu"). Meminta
            // sekaligus banyak koreksi di awal menurunkan peluang orang
            // benar-benar menyelesaikannya; pita yang paling meragukan itu
            // juga yang paling berharga bagi pelatihan ulang per baris.
            'actionable' => $bands['rendah']['count'] > 0,
        ];
    }

    /**
     * Ambang tinjauan yang berlaku.
     *
     * `$result` boleh null: analisis yang belum punya hasil tetap harus memakai
     * ambang yang sama dengan sisanya. Dulu pemanggil yang tidak punya hasil
     * membaca `config('analysis.confidence.review_threshold')` sendiri - dan
     * begitu kunci itu dikosongkan (jalur normalnya kini toleransi galat),
     * hasilnya `(float) null` = 0,0 sehingga tidak ada satu baris pun yang
     * dianggap ragu. Satu-satunya jalan menuju ambang harus lewat sini.
     */
    public function threshold(?AnalysisResult $result = null): array
    {
        // 1. Pengaturan eksplisit menang atas segalanya. Nilai yang ditulis
        //    orang di .env harus berlaku; kalau nilai temuan otomatis yang
        //    menang, mengisi .env tidak berpengaruh apa-apa dan itu jauh lebih
        //    membingungkan daripada sekadar berbeda dari layanan.
        $eksplisit = config('analysis.confidence.review_threshold');

        if ($this->masukAkal($eksplisit)) {
            return ['value' => (float) $eksplisit, 'source' => 'konfigurasi'];
        }

        // 2. Ambang yang diturunkan dari toleransi galat. Inilah jalur normal.
        //
        //    Diletakkan di atas ambang layanan karena dasarnya lebih kuat:
        //    ambang layanan (0,94) DIPILIH dari tabel yield pada set uji yang
        //    juga dilaporkan sebagai hasil - selection-on-test - sementara
        //    toleransi galat adalah pernyataan risiko pada model terkalibrasi
        //    dan tidak menyentuh data uji sama sekali.
        $toleransi = config('analysis.confidence.error_tolerance');

        if (is_numeric($toleransi) && $toleransi > 0 && $toleransi < 1) {
            return ['value' => round(1 - (float) $toleransi, 4), 'source' => 'toleransi-galat'];
        }

        // 3. Ambang yang dipakai saat analisis itu benar-benar dijalankan.
        $dariHasil = $result?->metrics['review_queue']['threshold'] ?? null;

        if ($this->masukAkal($dariHasil)) {
            return ['value' => (float) $dariHasil, 'source' => 'nlp-api'];
        }

        // 4. Ambang yang sedang berlaku di layanan NLP. Diperlukan untuk
        //    analisis lama yang dijalankan sebelum review_queue disimpan -
        //    tanpa ini Laravel memakai angkanya sendiri dan melaporkan jumlah
        //    "baris ragu" yang berbeda untuk data yang sama. Terukur pada
        //    analisis 69: 20 baris pada 0,70 versus 42 baris pada 0,90.
        if ($this->masukAkal($dariLayanan = $this->thresholdLayanan())) {
            return ['value' => (float) $dariLayanan, 'source' => 'nlp-api'];
        }

        // 5. Layanan sedang mati. Halaman hasil tetap harus tampil.
        return [
            'value' => (float) config('analysis.confidence.fallback_threshold', 0.90),
            'source' => 'bawaan',
        ];
    }

    /**
     * Ambang yang sedang berlaku di layanan NLP, atau null bila tidak terjangkau.
     */
    private function thresholdLayanan(): ?float
    {
        // Menyimpan false, bukan null, saat layanan tidak terjangkau:
        // Cache::remember memperlakukan null sebagai cache miss, sehingga
        // layanan yang mati akan dihubungi ulang - dengan timeout 10 detik -
        // setiap kali halaman hasil dibuka.
        $tersimpan = Cache::remember('nlp.review_threshold', self::CACHE_TTL, function () {
            $health = $this->nlpApi->health();

            $nilai = $this->nlpApi->healthModelValue($health, 'sentiment_review_threshold');

            return $this->masukAkal($nilai) ? (float) $nilai : false;
        });

        return $tersimpan === false ? null : $tersimpan;
    }

    private function masukAkal(mixed $nilai): bool
    {
        return is_numeric($nilai) && $nilai > 0 && $nilai < 1;
    }

    /**
     * Indeks prediksi yang keyakinannya di bawah ambang, paling ragu lebih dulu.
     *
     * Memakai `original_index` supaya tetap menunjuk teks yang benar pada
     * korpus yang diproses per batch.
     *
     * @return array<int, int>
     */
    public function lowConfidenceIndices(AnalysisResult $result): array
    {
        $threshold = $this->threshold($result)['value'];
        $reserved = $this->golden->reservedHashes();
        $baris = [];

        foreach ($result->predictions ?? [] as $posisi => $prediksi) {
            $teks = $prediksi['text'] ?? $prediksi['original_text'] ?? null;

            if (TextScorability::isUnscorable($teks, $prediksi['method'] ?? null)) {
                continue;
            }

            if (isset($reserved[TextFingerprint::hash((string) $teks)])) {
                continue;
            }

            $nilai = $prediksi['confidence'] ?? null;

            if (! is_numeric($nilai) || $nilai >= $threshold) {
                continue;
            }

            $baris[] = [
                'index' => $prediksi['original_index'] ?? $posisi,
                'confidence' => (float) $nilai,
            ];
        }

        usort($baris, fn ($a, $b) => $a['confidence'] <=> $b['confidence']);

        return array_column($baris, 'index');
    }

    /**
     * @return array{nilai: array<int, float>, tanpa_nilai: int}
     */
    private function skor(AnalysisResult $result): array
    {
        $nilai = [];
        $tanpaNilai = 0;
        $reserved = $this->golden->reservedHashes();

        foreach ($result->predictions ?? [] as $prediksi) {
            $teks = $prediksi['text'] ?? $prediksi['original_text'] ?? null;

            // Baris method=empty TERSIMPAN dengan confidence 0.0 - angka
            // pengisi, bukan model benar-benar ragu (lihat NLPApiService /
            // SentimentBatchAggregationTest). is_numeric(0.0) bernilai true,
            // jadi pemeriksaan itu saja meloloskannya: dulu baris ini ikut
            // terhitung sebagai "sangat ragu", menggelembungkan ajakan active
            // learning di halaman hasil jauh melebihi baris yang benar-benar
            // ditawarkan di halaman koreksi (413 lawan 231 pada analisis
            // dengan 182 baris emotikon). TextScorability adalah definisi
            // yang sama dipakai TrainingItemService, jadi kedua halaman
            // sekarang menghitung himpunan yang identik.
            if (TextScorability::isUnscorable($teks, $prediksi['method'] ?? null)) {
                $tanpaNilai++;

                continue;
            }

            // Baris yang jadi kandidat golden dataset (reservedHashes -
            // dataset mana pun, termasuk yang masih dianotasi, bukan cuma
            // yang beku) tidak pernah ditawarkan di halaman koreksi. Tanpa
            // penyaringan ini di sini juga, panel keyakinan menjanjikan
            // "sangat ragu" lebih banyak daripada yang benar-benar bisa
            // dikoreksi - selisihnya persis sejumlah baris yang terpakai
            // golden dataset. TIDAK dihitung sebagai "tidak dinilai": baris
            // ini benar-benar dinilai model, hanya sedang dipakai sebagai
            // data uji, bukan ditawarkan untuk dikoreksi.
            if (isset($reserved[TextFingerprint::hash((string) $teks)])) {
                continue;
            }

            $skor = $prediksi['confidence'] ?? null;

            // Baris yang gagal dinilai (method=error) atau tidak punya kunci
            // confidence sama sekali tetap harus disingkirkan di sini.
            if (! is_numeric($skor)) {
                $tanpaNilai++;

                continue;
            }

            $nilai[] = (float) $skor;
        }

        return ['nilai' => $nilai, 'tanpa_nilai' => $tanpaNilai];
    }

    /**
     * @param  array<int, float>  $nilai  sudah terurut menaik
     */
    /**
     * Sebaran keyakinan, dengan batas pita yang DITURUNKAN dari ambang yang
     * sama - bukan dari konfigurasi terpisah.
     *
     * Sebelumnya batasnya berdiri sendiri (0,85 dan 0,70) sementara ambang
     * tinjauan 0,80, sehingga satu panel bisa menyebut sebuah teks "cukup
     * yakin" sekaligus "perlu ditinjau". Pita teratas kini selalu tepat sama
     * dengan ambang tinjauan, jadi "di luar pita teratas" dan "perlu ditinjau"
     * adalah himpunan yang sama persis.
     */
    private function bands(array $nilai, float $threshold): array
    {
        // Batas teratas dipaksa mengikuti ambang tinjauan, apa pun isi config -
        // itu yang membuat keduanya tidak mungkin berselisih.
        ['tinggi' => $batasTinggi, 'sedang' => $batasSedang] = $this->batasPita($threshold);

        $total = count($nilai);
        $hitung = ['tinggi' => 0, 'sedang' => 0, 'rendah' => 0];

        foreach ($nilai as $x) {
            if ($x >= $batasTinggi) {
                $hitung['tinggi']++;
            } elseif ($x >= $batasSedang) {
                $hitung['sedang']++;
            } else {
                $hitung['rendah']++;
            }
        }

        $persen = fn (float $x) => rtrim(rtrim(number_format($x * 100, 1, ',', ''), '0'), ',');

        return [
            'tinggi' => [
                'label' => 'Yakin',
                // Keterangannya menyebut arti angkanya, bukan angkanya saja:
                // pada model terkalibrasi, keyakinan adalah taksiran peluang benar.
                'keterangan' => 'keyakinan &ge;'.$persen($batasTinggi).'%, perkiraan salah &le;'.$persen(1 - $batasTinggi).'%',
                'count' => $hitung['tinggi'],
                'share' => $total > 0 ? round(($hitung['tinggi'] / $total) * 100, 1) : 0,
            ],
            'sedang' => [
                'label' => 'Ragu',
                'keterangan' => $persen($batasSedang).'%&ndash;'.$persen($batasTinggi).'%, perkiraan salah '
                    .$persen(1 - $batasTinggi).'%&ndash;'.$persen(1 - $batasSedang).'%',
                'count' => $hitung['sedang'],
                'share' => $total > 0 ? round(($hitung['sedang'] / $total) * 100, 1) : 0,
            ],
            'rendah' => [
                'label' => 'Sangat ragu',
                'keterangan' => 'di bawah '.$persen($batasSedang).'%, perkiraan salah &gt;'.$persen(1 - $batasSedang).'%',
                'count' => $hitung['rendah'],
                'share' => $total > 0 ? round(($hitung['rendah'] / $total) * 100, 1) : 0,
            ],
        ];
    }

    /**
     * Batas pita, dipisahkan dari daftar pitanya.
     *
     * Sempat diletakkan di dalam `bands` dan langsung menjatuhkan halaman hasil:
     * panel mengiterasi seluruh isi `bands` sebagai pita, jadi entri tanpa
     * `label` memicu "Undefined array key".
     */
    /**
     * Batas pita yang sedang berlaku, tanpa perlu hasil analisis apa pun.
     * Dipakai evaluasi hold-out supaya tabel reliabilitasnya memakai pita yang
     * sama persis dengan yang dilihat pengguna di halaman hasil.
     */
    public function bandBounds(): array
    {
        return $this->batasPita($this->threshold()['value']);
    }

    /**
     * Batas pita untuk SATU analisis tertentu - beda dari bandBounds() di
     * atas karena ikut memakai ambang milik analisis itu sendiri kalau ada
     * (sumber 'nlp-api' dari `review_queue` tersimpan), bukan cuma ambang
     * global. Dipakai halaman koreksi supaya pita "Sangat ragu" yang
     * ditawarkan persis sama dengan yang dilihat pengguna di halaman hasil
     * analisis yang sama.
     */
    public function bandBoundsFor(?AnalysisResult $result): array
    {
        return $this->batasPita($this->threshold($result)['value']);
    }

    private function batasPita(float $threshold): array
    {
        $tingkat = (array) config('analysis.confidence.band_error_levels', [0.10, 0.30]);
        sort($tingkat);

        $sedang = round(1 - (float) ($tingkat[1] ?? 0.30), 4);

        if ($sedang >= $threshold) {
            $sedang = round($threshold - 0.20, 4);
        }

        return ['tinggi' => $threshold, 'sedang' => $sedang];
    }

    /**
     * Penilaian ringkas atas korpus, memakai DUA ukuran sekaligus.
     *
     * Rerata saja menyesatkan: korpus dengan rerata 0,95 dan 15% baris ragu
     * berbeda kebutuhannya dari korpus dengan rerata sama dan 1% baris ragu.
     *
     * Batasnya diturunkan dari ambang yang sama, bukan ditulis tetap. Dulu
     * fungsi ini memakai 0,90 dan 0,80 sendiri, sehingga mengubah ambang di
     * .env tidak mengubah penilaiannya - satu panel bisa menyebut korpus
     * "Baik" sambil menandai sepertiga barisnya perlu ditinjau.
     */
    private function verdict(float $rerata, float $bagianRendah, float $threshold): array
    {
        // Rerata dibandingkan terhadap ambang itu sendiri: korpus yang reratanya
        // di atas ambang berarti prediksi kebanyakan berada di wilayah yang
        // memang layak dipercaya.
        $bawah = max($threshold - 0.20, 0.5);

        return match (true) {
            $rerata >= $threshold && $bagianRendah < 0.05 => [
                'label' => 'Sangat baik',
                'class' => 'text-green-800 bg-green-50 border-green-200',
                'pesan' => 'Model sangat yakin pada hampir seluruh teks Anda. Koreksi tetap berguna, tetapi hasilnya sudah bisa dipakai apa adanya.',
            ],
            $rerata >= $threshold && $bagianRendah < 0.15 => [
                'label' => 'Baik',
                'class' => 'text-green-800 bg-green-50 border-green-200',
                'pesan' => 'Model cukup yakin pada sebagian besar teks Anda. Sebagian kecil masih ragu dan paling berguna untuk dikoreksi.',
            ],
            $rerata >= $bawah => [
                'label' => 'Cukup',
                'class' => 'text-amber-800 bg-amber-50 border-amber-200',
                'pesan' => 'Model ragu pada cukup banyak teks Anda. Mengoreksi baris yang ragu akan terasa dampaknya.',
            ],
            default => [
                'label' => 'Perlu perhatian',
                'class' => 'text-red-800 bg-red-50 border-red-200',
                'pesan' => 'Model banyak ragu pada data ini - kemungkinan gaya bahasanya jauh berbeda dari data training. Koreksi Anda sangat dibutuhkan sebelum hasilnya dipakai.',
            ],
        };
    }

    /**
     * @param  array<int, float>  $nilai  sudah terurut menaik
     */
    private function median(array $nilai): float
    {
        $n = count($nilai);
        $tengah = intdiv($n, 2);

        return $n % 2 === 0
            ? ($nilai[$tengah - 1] + $nilai[$tengah]) / 2
            : $nilai[$tengah];
    }
}
