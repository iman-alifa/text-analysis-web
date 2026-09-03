<?php

namespace Tests\Feature;

use App\Helpers\ChartHelper;
use Tests\TestCase;

/**
 * Mode topik otomatis bisa menghasilkan sampai 20 topik dan korpus kecil bisa
 * tidak menghasilkan frekuensi kata sama sekali. Keduanya dulu merusak
 * halaman hasil.
 */
class ChartHelperEdgeCaseTest extends TestCase
{
    public function test_setiap_topik_mendapat_warna_walau_lebih_dari_lima(): void
    {
        $topics = [];
        for ($i = 0; $i < 18; $i++) {
            $topics[] = ['topic_id' => $i, 'proportion' => 0.05, 'words' => ['a']];
        }

        $data = ChartHelper::prepareTopicChartData($topics);
        $warna = $data['datasets'][0]['backgroundColor'];

        $this->assertCount(18, $warna);
        $this->assertNotContains(null, $warna);
        // Palet diputar, jadi topik ke-6 memakai warna pertama lagi
        $this->assertSame($warna[0], $warna[5]);
    }

    public function test_topik_tanpa_proportion_tidak_membuat_error(): void
    {
        $data = ChartHelper::prepareTopicChartData([
            ['topic_id' => 0, 'words' => ['a']],
        ]);

        $this->assertEquals([0], $data['datasets'][0]['data']);
        $this->assertSame(['Topik #1'], $data['labels']);
    }

    public function test_word_cloud_kosong_mengembalikan_array_kosong(): void
    {
        // max() dulu melempar galat di sini dan halaman hasil ikut gagal
        $this->assertSame([], ChartHelper::prepareWordCloudData([]));
    }

    public function test_word_cloud_menghitung_ukuran_relatif(): void
    {
        $data = ChartHelper::prepareWordCloudData([
            ['word' => 'pajak', 'frequency' => 100],
            ['word' => 'rakyat', 'frequency' => 50],
        ]);

        $this->assertSame('pajak', $data[0]['word']);
        // assertEquals, bukan assertSame: PHP mengembalikan int untuk
        // pembagian yang habis sehingga tipenya bisa int atau float.
        $this->assertEquals(32, $data[0]['size']);
        $this->assertEquals(22, $data[1]['size']);
    }

    public function test_frekuensi_nol_tidak_menghasilkan_pembagian_nol(): void
    {
        $data = ChartHelper::prepareWordCloudData([
            ['word' => 'a', 'frequency' => 0],
        ]);

        $this->assertEquals(12, $data[0]['size']);
        $this->assertFalse(is_infinite($data[0]['size']));
    }
}
