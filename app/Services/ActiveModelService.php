<?php

namespace App\Services;

use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use Illuminate\Support\Facades\Cache;

/**
 * Bobot mana yang SEDANG DIPAKAI layanan NLP, per modul, dan run evaluasi mana
 * yang mengukurnya.
 *
 * Masalahnya: "run evaluasi terakhir" tidak sama dengan "model yang dipakai".
 * 27 Sep model aspek dikembalikan ke iterasi 1 karena iterasi 2 lebih buruk,
 * tetapi halaman depan tetap menampilkan angka iterasi 2 - angka dari bobot
 * yang sudah diarsipkan.
 *
 * Identitas bobot adalah sidik jari kolam (`pool_fingerprint`), yang dilaporkan
 * layanan lewat `/health` dari provenance bobot yang hidup dan sama persis
 * dengan `model_trainings.result.sidik_jari_kolam`. Bobot dibandingkan lewat
 * KUNCI, bukan lewat id pelatihan:
 *
 * - `dasar`       - bobot dasar, belum pernah dilatih ulang;
 * - `sj:<sidik>`  - bobot hasil pelatihan atas kolam dengan sidik jari itu;
 * - `latih:<id>`  - pelatihan lama yang belum mencatat sidik jari.
 *
 * Sidik jari dan bukan id, karena dua pelatihan atas kolam yang sama
 * menghasilkan bobot yang sama (terbukti pada pelatihan #5 dan #6).
 */
class ActiveModelService
{
    public const MODUL = ['sentiment', 'aspect'];

    /** Bacaan /health yang masih segar; menahan halaman publik dari memanggil layanan tiap kali. */
    public const CACHE_SEGAR = 'model-aktif:segar';

    /** Bacaan terakhir yang berhasil, dipakai bila layanan tidak terjangkau. */
    public const CACHE_TERAKHIR = 'model-aktif:terakhir';

    public const DETIK_SEGAR = 300;

    /** Pendek, karena dipanggil dari halaman depan yang terbuka untuk umum. */
    public const TIMEOUT_HEALTH = 3;

    public function __construct(private NLPApiService $nlp) {}

    /**
     * Kunci bobot aktif per modul.
     *
     * @return array{sentiment: string, aspect: string, sumber: string, pasti: bool}
     *                                                                               sumber: layanan | cache | tebakan
     */
    public function aktif(): array
    {
        $bacaan = $this->bacaan();
        $hasil = ['sumber' => $bacaan['sumber'], 'pasti' => true];

        foreach (self::MODUL as $modul) {
            $sidik = $bacaan[$modul] ?? ['dikenal' => false, 'sidik_jari' => null];

            if ($sidik['dikenal']) {
                $hasil[$modul] = $sidik['sidik_jari'] ? 'sj:'.$sidik['sidik_jari'] : 'dasar';

                continue;
            }

            // Layanan tidak menyebut sidik jari (versi lama, atau belum pernah
            // terjangkau). Tebakan terbaik adalah pelatihan terakhir yang
            // tersimpan - aturan lama, dan ditandai tidak pasti.
            $hasil[$modul] = $this->kunciPelatihan($this->pelatihanTersimpanTerakhir($modul));
            $hasil['pasti'] = false;
            $hasil['sumber'] = 'tebakan';
        }

        return $hasil;
    }

    /**
     * Pelatihan yang bobotnya sedang dipakai, untuk ditandai di riwayat.
     *
     * @return array<string, ModelTraining|null> null berarti bobot dasar
     *                                           (atau bobot yang tidak tercatat di aplikasi ini)
     */
    public function pelatihanAktif(): array
    {
        $aktif = $this->aktif();
        $hasil = [];

        foreach (self::MODUL as $modul) {
            $kunci = $aktif[$modul];

            $hasil[$modul] = match (true) {
                str_starts_with($kunci, 'sj:') => $this->tersimpan($modul)
                    ->where('result->sidik_jari_kolam', substr($kunci, 3))
                    ->latest('id')
                    ->first(),
                str_starts_with($kunci, 'latih:') => ModelTraining::find((int) substr($kunci, 6)),
                default => null,
            };
        }

        return $hasil;
    }

    /**
     * Kunci bobot yang diukur sebuah run.
     *
     * Run baru menyimpan sidik jari yang dilaporkan layanan saat itu. Run lama
     * belum, dan untuknya dipakai pelatihan tersimpan terakhir modul itu yang
     * selesai sebelum run dimulai - benar selama tidak ada pemulihan bobot di
     * antaranya, dan pemulihan pertama terjadi setelah run lama terakhir.
     */
    public function kunciRun(EvaluationRun $run, string $modul): string
    {
        $info = is_array($run->model_info) ? $run->model_info : [];
        $kunci = "{$modul}_pool_fingerprint";

        if (array_key_exists($kunci, $info)) {
            return filled($info[$kunci]) ? 'sj:'.$info[$kunci] : 'dasar';
        }

        $mulai = $run->started_at ?? $run->created_at;

        return $this->kunciPelatihan(
            $this->tersimpan($modul)
                ->when($mulai, fn ($q) => $q->where('completed_at', '<=', $mulai))
                ->latest('completed_at')
                ->latest('id')
                ->first()
        );
    }

    public function lupakan(): void
    {
        Cache::forget(self::CACHE_SEGAR);
    }

    // ------------------------------------------------------------------ dalam

    private function kunciPelatihan(?ModelTraining $pelatihan): string
    {
        if (! $pelatihan) {
            return 'dasar';
        }

        return $pelatihan->sidik_jari_kolam
            ? 'sj:'.$pelatihan->sidik_jari_kolam
            : 'latih:'.$pelatihan->id;
    }

    private function pelatihanTersimpanTerakhir(string $modul): ?ModelTraining
    {
        return $this->tersimpan($modul)->latest('completed_at')->latest('id')->first();
    }

    /**
     * Pelatihan yang checkpoint-nya benar-benar tersimpan. `rejected` (ditolak
     * karena memburuk), `failed`, dan `skipped` tidak pernah mengganti bobot.
     */
    private function tersimpan(string $modul)
    {
        return ModelTraining::where('model_type', $modul)->where('status', 'completed');
    }

    /**
     * @return array{sentiment?: array{dikenal: bool, sidik_jari: ?string}, aspect?: array{dikenal: bool, sidik_jari: ?string}, sumber: string}
     */
    private function bacaan(): array
    {
        try {
            $segar = Cache::get(self::CACHE_SEGAR);
        } catch (\Throwable) {
            $segar = null;
        }

        if (is_array($segar)) {
            return $segar;
        }

        $dariLayanan = $this->bacaHealth($this->nlp->health(self::TIMEOUT_HEALTH));

        if ($dariLayanan !== null) {
            $dariLayanan['sumber'] = 'layanan';
            $this->simpan(self::CACHE_SEGAR, $dariLayanan, self::DETIK_SEGAR);
            $this->simpan(self::CACHE_TERAKHIR, $dariLayanan);

            return $dariLayanan;
        }

        // Layanan tidak terjangkau: nilai terakhir yang diketahui. Disimpan
        // sebagai "segar" sebentar saja, supaya halaman publik tidak menunggu
        // batas waktu /health pada setiap kunjungan selama layanan mati.
        try {
            $terakhir = Cache::get(self::CACHE_TERAKHIR);
        } catch (\Throwable) {
            $terakhir = null;
        }

        $hasil = is_array($terakhir)
            ? array_merge($terakhir, ['sumber' => 'cache'])
            : ['sumber' => 'tebakan'];

        $this->simpan(self::CACHE_SEGAR, $hasil, 60);

        return $hasil;
    }

    /**
     * Null bila layanan tidak terjangkau ATAU tidak menyebut sidik jari sama
     * sekali (versi lama). Kunci yang ada dengan nilai null berarti bobot dasar.
     */
    private function bacaHealth(?array $health): ?array
    {
        if (! is_array($health)) {
            return null;
        }

        $models = is_array($health['models'] ?? null) ? $health['models'] : $health;
        $hasil = [];

        foreach (self::MODUL as $modul) {
            $kunci = "{$modul}_pool_fingerprint";
            $dikenal = array_key_exists($kunci, $models);
            $hasil[$modul] = [
                'dikenal' => $dikenal,
                'sidik_jari' => $dikenal && filled($models[$kunci]) ? (string) $models[$kunci] : null,
            ];
        }

        return ($hasil['sentiment']['dikenal'] || $hasil['aspect']['dikenal']) ? $hasil : null;
    }

    private function simpan(string $kunci, array $nilai, ?int $detik = null): void
    {
        try {
            $detik === null ? Cache::forever($kunci, $nilai) : Cache::put($kunci, $nilai, $detik);
        } catch (\Throwable) {
            // Cache yang rusak tidak boleh menjatuhkan halaman publik.
        }
    }
}
