<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use Tests\TestCase;

/**
 * Database menyimpan tiga bentuk aspect_results dari versi kode yang berbeda.
 */
class AspectResultNormalizationTest extends TestCase
{
    private function makeResult(array $aspectResults): AnalysisResult
    {
        $result = new AnalysisResult();
        $result->setRawAttributes(['aspect_results' => json_encode($aspectResults)]);

        return $result;
    }

    public function test_bentuk_sekarang_diteruskan_apa_adanya(): void
    {
        $rows = $this->makeResult([
            ['aspect' => 'harga', 'count' => 10, 'sentiments' => ['positive' => 60, 'neutral' => 20, 'negative' => 20]],
        ])->normalizedAspectResults();

        $this->assertCount(1, $rows);
        $this->assertSame('harga', $rows[0]['aspect']);
        $this->assertSame(10, $rows[0]['count']);
        $this->assertSame(60, $rows[0]['sentiments']['positive']);
    }

    public function test_bentuk_lama_dengan_count_dikonversi_ke_persentase(): void
    {
        $rows = $this->makeResult([
            'pelayanan' => ['total' => 4, 'positive' => 3, 'neutral' => 1, 'negative' => 0, 'mentions' => []],
        ])->normalizedAspectResults();

        $this->assertCount(1, $rows);
        $this->assertSame('pelayanan', $rows[0]['aspect']);
        $this->assertSame(4, $rows[0]['count']);
        $this->assertSame(75.0, $rows[0]['sentiments']['positive']);
        $this->assertSame(25.0, $rows[0]['sentiments']['neutral']);
    }

    public function test_baris_tanpa_nama_aspek_dilewati(): void
    {
        // Bentuk yang tersimpan pada analisis lama: tanpa nama, semua nol
        $rows = $this->makeResult([
            ['total' => 0, 'positive' => 0, 'neutral' => 0, 'negative' => 0, 'mentions' => []],
            ['total' => 0, 'positive' => 0, 'neutral' => 0, 'negative' => 0, 'mentions' => []],
        ])->normalizedAspectResults();

        $this->assertSame([], $rows);
    }

    public function test_nilai_tidak_terduga_tidak_membuat_error(): void
    {
        $this->assertSame([], $this->makeResult([true, false, 'teks'])->normalizedAspectResults());
    }
}
