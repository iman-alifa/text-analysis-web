<?php

namespace Tests\Feature;

use App\Models\EvaluationDataset;
use App\Models\EvaluationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menyatukan dua bentuk nama menjadi satu label aspek emas.
 *
 * Golden dataset memuat `prabowo subianto` (7 label) di samping `prabowo`
 * (25 label), sementara koreksi active learning tidak pernah sekali pun memakai
 * nama lengkap. Dua bentuk untuk satu rujukan menghukum model karena memakai
 * bentuk yang salah satu sisi anggap benar.
 */
class MergeAspectLabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<int, string>>  $aspekPerBaris
     */
    private function dataset(array $aspekPerBaris): EvaluationDataset
    {
        $dataset = EvaluationDataset::create([
            'name' => 'Hold-out studi kasus',
            'split' => 'test',
            'source' => 'sampling',
            'frozen_at' => now(),
        ]);

        foreach ($aspekPerBaris as $i => $aspek) {
            EvaluationItem::create([
                'evaluation_dataset_id' => $dataset->id,
                'text' => "komentar warga nomor {$i} tentang lembaga",
                'gold_sentiment' => 'positive',
                'gold_aspects' => $aspek,
                'gold_aspects_normalized' => array_map(fn ($a) => 'stem-'.$a, $aspek),
            ]);
        }

        return $dataset->fresh();
    }

    public function test_mode_rencana_tidak_mengubah_apa_pun(): void
    {
        $dataset = $this->dataset([['prabowo subianto', 'gizi']]);

        $this->artisan('evaluation:merge-aspect-label', [
            'dataset' => $dataset->id,
            '--from' => ['prabowo subianto'],
            '--to' => 'prabowo',
        ])->assertSuccessful();

        $item = $dataset->items()->first();

        $this->assertSame(['prabowo subianto', 'gizi'], $item->gold_aspects);
        $this->assertNotNull($item->gold_aspects_normalized);
        $this->assertNull($dataset->fresh()->label_changes);
    }

    public function test_apply_menggabungkan_dan_mengosongkan_bentuk_dasar(): void
    {
        $dataset = $this->dataset([['prabowo subianto', 'gizi']]);

        $this->artisan('evaluation:merge-aspect-label', [
            'dataset' => $dataset->id,
            '--from' => ['prabowo subianto'],
            '--to' => 'prabowo',
            '--apply' => true,
        ])->assertSuccessful();

        $item = $dataset->items()->first();

        $this->assertSame(['prabowo', 'gizi'], $item->gold_aspects);

        // Wajib dikosongkan: selama kosong, HoldoutEvaluationService MENOLAK
        // menghitung metrik aspek. Itu disengaja - membandingkan bentuk dasar
        // lama dengan label yang sudah berubah menghasilkan angka yang salah
        // tanpa gejala apa pun.
        $this->assertNull($item->gold_aspects_normalized);
    }

    public function test_baris_yang_sudah_punya_tujuan_tidak_menjadi_duplikat(): void
    {
        // Ketujuh baris nyata pada studi kasus persis begini: keduanya ada.
        $dataset = $this->dataset([['prabowo', 'prabowo subianto', 'presiden']]);

        $this->artisan('evaluation:merge-aspect-label', [
            'dataset' => $dataset->id,
            '--from' => ['prabowo subianto'],
            '--to' => 'prabowo',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(['prabowo', 'presiden'], $dataset->items()->first()->gold_aspects);
    }

    public function test_baris_tanpa_label_asal_tidak_tersentuh(): void
    {
        $dataset = $this->dataset([
            ['prabowo subianto'],
            ['gizi', 'mbg'],
        ]);

        $this->artisan('evaluation:merge-aspect-label', [
            'dataset' => $dataset->id,
            '--from' => ['prabowo subianto'],
            '--to' => 'prabowo',
            '--apply' => true,
        ])->assertSuccessful();

        $kedua = $dataset->items()->orderBy('id')->get()[1];

        $this->assertSame(['gizi', 'mbg'], $kedua->gold_aspects);
        $this->assertNotNull($kedua->gold_aspects_normalized);
    }

    public function test_perubahan_label_emas_dicatat(): void
    {
        // Setiap angka evaluasi yang diukur sebelum perubahan tidak lagi
        // sebanding dengan sesudahnya, jadi perubahannya wajib berjejak.
        $dataset = $this->dataset([['prabowo', 'prabowo subianto']]);

        $this->artisan('evaluation:merge-aspect-label', [
            'dataset' => $dataset->id,
            '--from' => ['prabowo subianto'],
            '--to' => 'prabowo',
            '--apply' => true,
        ])->assertSuccessful();

        $catatan = $dataset->fresh()->label_changes;

        $this->assertCount(1, $catatan);
        $this->assertSame(['prabowo subianto'], $catatan[0]['from']);
        $this->assertSame('prabowo', $catatan[0]['to']);
        $this->assertSame(1, $catatan[0]['rows']);
        $this->assertSame(1, $catatan[0]['merged_into_existing']);
    }

    public function test_label_tujuan_tidak_boleh_ikut_sebagai_asal(): void
    {
        $dataset = $this->dataset([['prabowo']]);

        $this->artisan('evaluation:merge-aspect-label', [
            'dataset' => $dataset->id,
            '--from' => ['prabowo'],
            '--to' => 'prabowo',
            '--apply' => true,
        ])->assertFailed();
    }
}
