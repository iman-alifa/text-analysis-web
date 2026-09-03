<?php

namespace App\Services;

use App\Models\CustomStopword;
use App\Models\PreprocessingConfig;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Menentukan konfigurasi preprocessing yang dikirim ke NLP API.
 *
 * Dipakai bersama oleh ProcessTextAnalysis dan pratinjau di formulir analisis.
 * Kalau keduanya menghitung sendiri-sendiri, pratinjau bisa menampilkan hasil
 * yang berbeda dari yang benar-benar dijalankan - persis masalah yang membuat
 * pratinjau tidak bisa dipercaya.
 */
class PreprocessingConfigResolver
{
    /**
     * Nilai cadangan bila tidak ada satu pun config di database.
     */
    public const FALLBACK = [
        'case_folding' => true,
        'remove_punctuation' => true,
        'remove_numbers' => false,
        'remove_stopwords' => true,
        'stemming' => true,
        'lemmatization' => false,
        'custom_stopwords' => [],
    ];

    /**
     * Urutan: config pilihan pengguna -> config default -> cadangan statis.
     * Stopword kustom dari admin selalu digabungkan ke config mana pun.
     */
    public function resolve(?int $configId = null): array
    {
        $config = null;

        try {
            if ($configId) {
                $config = PreprocessingConfig::find($configId);
            }

            if (!$config) {
                $config = PreprocessingConfig::where('is_default', true)->first();
            }
        } catch (Exception $e) {
            Log::warning('Gagal memuat preprocessing config: ' . $e->getMessage());
        }

        $resolved = $config ? $config->toApiFormat() : self::FALLBACK;

        $resolved['custom_stopwords'] = array_values(array_unique(array_merge(
            $resolved['custom_stopwords'] ?? [],
            $this->customStopwords()
        )));

        return $resolved;
    }

    /**
     * Nama config yang terpakai, untuk ditampilkan pada pratinjau.
     */
    public function resolveName(?int $configId = null): string
    {
        try {
            $config = $configId ? PreprocessingConfig::find($configId) : null;
            $config ??= PreprocessingConfig::where('is_default', true)->first();

            return $config->name ?? 'Bawaan sistem';
        } catch (Exception $e) {
            return 'Bawaan sistem';
        }
    }

    /**
     * Profil preprocessing yang dipakai tiap modul analisis di sisi Python.
     *
     * Modul memaksakan kebijakannya sendiri di atas pilihan pengguna, jadi
     * pratinjau harus mengirim task yang sama agar hasilnya jujur.
     */
    public function taskForAnalysisType(string $analysisType): ?string
    {
        return match ($analysisType) {
            'sentiment' => 'transformer',
            'topic' => 'bag_of_words',
            'aspect' => 'span',
            // Analisis gabungan menjalankan ketiga modul, masing-masing dengan
            // profilnya sendiri, sehingga tidak ada satu task yang mewakili.
            default => null,
        };
    }

    protected function customStopwords(): array
    {
        try {
            return CustomStopword::pluck('word')
                ->map(fn ($word) => strtolower(trim((string) $word)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Exception $e) {
            Log::warning('Gagal memuat custom stopwords: ' . $e->getMessage());
            return [];
        }
    }
}
