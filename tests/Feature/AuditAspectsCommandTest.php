<?php

namespace Tests\Feature;

use App\Models\EvaluationDataset;
use App\Models\EvaluationItem;
use App\Models\EvaluationRun;
use App\Models\EvaluationRunItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `evaluation:audit-aspects` memeriksa konsistensi label aspek.
 *
 * Dua mode, dan perbedaannya bukan soal selera:
 *
 * - **Menyeluruh**: setiap kemunculan istilah yang sudah diterima anotator
 *   ikut dilabeli. Tidak bergantung pada model, tetapi menganggap SETIAP
 *   kelalaian sebagai kekeliruan.
 * - **`--only-predicted`**: hanya baris yang aspeknya memang diprediksi model.
 *   Menghormati kemungkinan anotator sengaja tidak melabeli, TETAPI setiap
 *   label yang ditambahkan otomatis menjadi true positive - presisi dan recall
 *   naik tanpa satu pun false negative baru, dan label emasnya jadi sebagian
 *   ditentukan keluaran model.
 *
 * Mode mana pun yang dipakai, ia mengubah label emas dan wajib disebut di
 * naskah.
 */
class AuditAspectsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function buatDataset(): EvaluationDataset
    {
        $user = User::factory()->create();
        $dataset = EvaluationDataset::create([
            'name' => 'uji audit',
            'type' => 'test',
            'created_by' => $user->id,
            'item_count' => 3,
        ]);

        // `pajak` diterima anotator dua kali; baris ketiga memuatnya tapi
        // tidak dilabeli - itulah inkonsistensinya.
        $baris = [
            ['teks' => 'soal pajak naik lagi', 'emas' => ['pajak']],
            ['teks' => 'pajak memberatkan rakyat', 'emas' => ['pajak']],
            ['teks' => 'pajak dan korupsi jalan terus', 'emas' => ['korupsi']],
        ];

        foreach ($baris as $b) {
            EvaluationItem::create([
                'evaluation_dataset_id' => $dataset->id,
                'text' => $b['teks'],
                'text_hash' => \App\Support\TextFingerprint::hash($b['teks']),
                'gold_sentiment' => 'negative',
                'gold_aspects' => $b['emas'],
                'gold_aspects_normalized' => $b['emas'],
            ]);
        }

        return $dataset->fresh();
    }

    private function palsukanNormalisasi(): void
    {
        Http::fake([
            '*/api/normalize/aspects' => function ($request) {
                $peta = [];
                foreach ($request->data()['aspects'] as $a) {
                    $peta[$a] = mb_strtolower($a);
                }

                return Http::response(['status' => 'success',
                    'results' => ['normalized' => $peta]]);
            },
        ]);
    }

    public function test_mode_rencana_tidak_mengubah_label(): void
    {
        $dataset = $this->buatDataset();
        $this->palsukanNormalisasi();

        $this->artisan('evaluation:audit-aspects '.$dataset->id)->assertExitCode(0);

        $ketiga = $dataset->items()->orderBy('id')->get()->last();
        $this->assertSame(['korupsi'], $ketiga->gold_aspects);
    }

    public function test_menyeluruh_menambahkan_kemunculan_yang_terlewat(): void
    {
        $dataset = $this->buatDataset();
        $this->palsukanNormalisasi();

        $this->artisan('evaluation:audit-aspects '.$dataset->id.' --apply')->assertExitCode(0);

        $ketiga = $dataset->items()->orderBy('id')->get()->last();
        $this->assertContains('pajak', $ketiga->gold_aspects_normalized);
    }

    public function test_only_predicted_butuh_run_yang_menyimpan_prediksi(): void
    {
        $dataset = $this->buatDataset();
        $this->palsukanNormalisasi();

        $this->artisan('evaluation:audit-aspects '.$dataset->id.' --only-predicted')
            ->assertExitCode(1);
    }

    public function test_only_predicted_hanya_menambah_di_baris_yang_diprediksi(): void
    {
        $dataset = $this->buatDataset();
        $this->palsukanNormalisasi();
        $items = $dataset->items()->orderBy('id')->get();

        $run = EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'iteration' => 0, 'status' => 'completed', 'item_count' => 3,
        ]);
        // Model TIDAK memprediksi `pajak` pada baris ketiga.
        foreach ($items as $item) {
            EvaluationRunItem::create([
                'evaluation_run_id' => $run->id,
                'evaluation_item_id' => $item->id,
                'predicted_aspects' => [],
            ]);
        }

        $this->artisan('evaluation:audit-aspects '.$dataset->id.' --only-predicted --apply')
            ->assertExitCode(0);

        $ketiga = $dataset->items()->orderBy('id')->get()->last();
        $this->assertNotContains('pajak', (array) $ketiga->gold_aspects_normalized,
            'tanpa prediksi model, label tidak boleh ditambahkan');
    }

    public function test_only_predicted_menambah_ketika_model_memprediksinya(): void
    {
        $dataset = $this->buatDataset();
        $this->palsukanNormalisasi();
        $items = $dataset->items()->orderBy('id')->get();

        $run = EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'iteration' => 0, 'status' => 'completed', 'item_count' => 3,
        ]);
        foreach ($items as $i => $item) {
            EvaluationRunItem::create([
                'evaluation_run_id' => $run->id,
                'evaluation_item_id' => $item->id,
                // Baris ketiga: model MEMANG menemukan `pajak`.
                'predicted_aspects' => $i === 2 ? ['pajak'] : [],
            ]);
        }

        $this->artisan('evaluation:audit-aspects '.$dataset->id.' --only-predicted --apply')
            ->assertExitCode(0);

        $ketiga = $dataset->items()->orderBy('id')->get()->last();
        $this->assertContains('pajak', (array) $ketiga->gold_aspects_normalized);
    }

    public function test_kata_opini_tidak_pernah_disebarkan(): void
    {
        $dataset = $this->buatDataset();
        $dataset->items()->update(['gold_aspects' => ['bagus'], 'gold_aspects_normalized' => ['bagus']]);
        $this->palsukanNormalisasi();

        $this->artisan('evaluation:audit-aspects '.$dataset->id.' --apply')->assertExitCode(0);

        foreach ($dataset->items as $item) {
            $this->assertSame(['bagus'], $item->gold_aspects);
        }
    }

    public function test_exclude_menghormati_pilihan_anotator(): void
    {
        $dataset = $this->buatDataset();
        $this->palsukanNormalisasi();

        $this->artisan('evaluation:audit-aspects '.$dataset->id.' --apply --exclude=pajak')
            ->assertExitCode(0);

        $ketiga = $dataset->items()->orderBy('id')->get()->last();
        $this->assertNotContains('pajak', (array) $ketiga->gold_aspects_normalized);
    }

    public function test_restore_mengembalikan_label_ke_berkas_asli(): void
    {
        $dataset = $this->buatDataset();
        $this->palsukanNormalisasi();
        $this->artisan('evaluation:audit-aspects '.$dataset->id.' --apply')->assertExitCode(0);

        $csv = tempnam(sys_get_temp_dir(), 'anot').'.csv';
        file_put_contents($csv, "id,text,label,aspects\n"
            ."1,\"soal pajak naik lagi\",negative,pajak\n"
            ."2,\"pajak memberatkan rakyat\",negative,pajak\n"
            ."3,\"pajak dan korupsi jalan terus\",negative,korupsi\n");

        // Opsi dikirim sebagai larik, bukan disambung ke string: path Windows
        // memuat backslash dan akan rusak saat string perintahnya diurai.
        $this->artisan('evaluation:audit-aspects', [
            'dataset' => $dataset->id,
            '--restore' => $csv,
        ])->assertExitCode(0);

        $ketiga = $dataset->items()->orderBy('id')->get()->last();
        $this->assertSame(['korupsi'], $ketiga->gold_aspects);
        @unlink($csv);
    }
}
