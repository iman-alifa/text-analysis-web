<?php

namespace Tests\Feature;

use App\Services\AssociationInsightService;
use Tests\TestCase;

/**
 * Narasi asosiasi dulu ditulis di dalam blok @php pada show.blade.php, jadi
 * tidak pernah bisa diuji. Setelah pindah ke layanan, aturannya bisa dikunci.
 */
class AssociationInsightTest extends TestCase
{
    private AssociationInsightService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssociationInsightService;
    }

    private function data(array $overrides = []): array
    {
        return array_merge([
            'topics_label' => ['Kenaikan Tarif', 'Mutu Pelayanan'],
            'topics_desc' => ['tarif, naik, mahal', 'petugas, ramah'],
            'pmi' => [
                ['aspect' => 'Tarif', 'scores' => [0.71, -0.20]],
                ['aspect' => 'Petugas', 'scores' => [-0.15, 0.42]],
            ],
            'crosstab' => [
                ['aspect' => 'Tarif', 'mentions' => 12, 'topics' => [80, 20]],
                ['aspect' => 'Petugas', 'mentions' => 30, 'topics' => [10, 90]],
            ],
        ], $overrides);
    }

    public function test_menyebut_pasangan_dengan_pmi_tertinggi(): void
    {
        $insights = $this->service->build($this->data());

        $this->assertStringContainsString('tarif', $insights[0]);
        $this->assertStringContainsString('Kenaikan Tarif', $insights[0]);
        $this->assertStringContainsString('+0.71', $insights[0]);
        $this->assertStringContainsString('tarif, naik, mahal', $insights[0]);
    }

    public function test_kalimat_kedua_menyebut_aspek_paling_sering_disebut(): void
    {
        $insights = $this->service->build($this->data());

        $this->assertCount(2, $insights);
        $this->assertStringContainsString('petugas', $insights[1]);
        $this->assertStringContainsString('30 kali', $insights[1]);
        $this->assertStringContainsString('Mutu Pelayanan', $insights[1]);
    }

    public function test_tidak_mengulang_aspek_yang_sama_dua_kali(): void
    {
        // Tarif sekaligus paling sering disebut dan paling kuat asosiasinya.
        $insights = $this->service->build($this->data([
            'crosstab' => [
                ['aspect' => 'Tarif', 'mentions' => 50, 'topics' => [80, 20]],
                ['aspect' => 'Petugas', 'mentions' => 10, 'topics' => [10, 90]],
            ],
        ]));

        $this->assertCount(1, $insights);
    }

    public function test_pmi_negatif_bukan_asosiasi(): void
    {
        // Semua PMI negatif berarti tidak ada pasangan yang muncul bersama
        // lebih sering daripada kebetulan - tidak boleh diklaim sebagai asosiasi.
        $insights = $this->service->build($this->data([
            'pmi' => [
                ['aspect' => 'Tarif', 'scores' => [-0.10, -0.20]],
            ],
        ]));

        $this->assertStringNotContainsString('asosiasi terkuat', $insights[0]);
    }

    public function test_data_kosong_menghasilkan_keterangan_bukan_galat(): void
    {
        $insights = $this->service->build([]);

        $this->assertCount(1, $insights);
        $this->assertStringContainsString('tidak ditemukan pola dominan', $insights[0]);
    }

    public function test_nama_aspek_di_escape(): void
    {
        // Nama aspek bisa berasal dari masukan pengguna (predefined_aspects),
        // dan narasinya dicetak dengan {!! !!} di view.
        $insights = $this->service->build($this->data([
            'pmi' => [
                ['aspect' => '<script>alert(1)</script>', 'scores' => [0.9]],
            ],
            'crosstab' => [],
        ]));

        $this->assertStringNotContainsString('<script>', $insights[0]);
        $this->assertStringContainsString('&lt;script&gt;', $insights[0]);
    }
}
