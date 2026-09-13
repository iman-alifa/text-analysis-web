<?php

namespace Tests\Feature;

use App\Jobs\RunHoldoutEvaluation;
use App\Models\EvaluationDataset;
use App\Models\User;
use App\Services\Evaluation\GoldenDatasetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tombol pada halaman evaluasi, diuji sebagai aksi - bukan sekadar halamannya
 * merender.
 *
 * Rangkaian enam iterasi berarti tombol "Jalankan Evaluasi" ditekan enam kali,
 * dan setiap penekanan yang gagal diam-diam berarti satu iterasi hilang tanpa
 * bisa diukur ulang. Menyisir rute GET saja tidak membuktikan apa pun tentang
 * tombol-tombol ini.
 */
class EvaluationButtonsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function dataset(bool $beku = true, bool $berlabel = true): EvaluationDataset
    {
        $dataset = app(GoldenDatasetService::class)->store([
            [
                'text' => 'Pelayanannya memuaskan sekali di kantor itu',
                'gold_sentiment' => $berlabel ? 'positive' : null,
            ],
            [
                'text' => 'Sistemnya error terus setiap kali dibuka',
                'gold_sentiment' => $berlabel ? 'negative' : null,
            ],
        ], ['name' => 'Hold-out studi kasus', 'split' => 'test']);

        if ($beku) {
            $dataset->update(['frozen_at' => now()]);
        }

        return $dataset->fresh();
    }

    public function test_tombol_jalankan_evaluasi_mengantrekan_pengukuran(): void
    {
        Queue::fake();

        $dataset = $this->dataset();

        $this->actingAs($this->admin())
            ->post(route('admin.evaluation.run', $dataset->id), ['label' => 'Iterasi 1 - TNI'])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(RunHoldoutEvaluation::class, function ($job) use ($dataset) {
            return (new \ReflectionProperty($job, 'datasetId'))->getValue($job) === $dataset->id;
        });
    }

    public function test_tombol_jalankan_ditolak_bila_dataset_belum_beku(): void
    {
        // Dataset yang masih bisa berubah membuat dua iterasi mengukur baris
        // yang berbeda - selisihnya lalu bukan perubahan model.
        Queue::fake();

        $dataset = $this->dataset(beku: false);

        $this->actingAs($this->admin())
            ->post(route('admin.evaluation.run', $dataset->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNotPushed(RunHoldoutEvaluation::class);
    }

    public function test_tombol_bekukan_dan_buka_pembekuan(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/api/normalize/aspects' => Http::response([
            'status' => 'success', 'results' => ['normalized' => []],
        ], 200)]);

        $dataset = $this->dataset(beku: false);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.evaluation.freeze', $dataset->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($dataset->fresh()->isFrozen());

        // Sidik jari isi ikut terisi saat pembekuan - itu yang membuktikan tiap
        // iterasi mengukur baris yang sama.
        $this->assertNotNull($dataset->fresh()->content_hash);

        $this->actingAs($admin)
            ->post(route('admin.evaluation.unfreeze', $dataset->id))
            ->assertRedirect();

        $this->assertFalse($dataset->fresh()->isFrozen());
    }

    public function test_pembekuan_ditolak_bila_masih_ada_baris_tanpa_label(): void
    {
        $dataset = $this->dataset(beku: false, berlabel: false);

        $this->actingAs($this->admin())
            ->post(route('admin.evaluation.freeze', $dataset->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($dataset->fresh()->isFrozen());
    }

    public function test_tombol_unggah_anotasi_mengisi_label(): void
    {
        Http::preventStrayRequests();

        $dataset = $this->dataset(beku: false, berlabel: false);
        $items = $dataset->items()->orderBy('id')->get();

        $csv = "id,text,label,label_2,aspects,segment,note\n";
        $csv .= "{$items[0]->id},\"Pelayanannya memuaskan sekali di kantor itu\",positif,,,,\n";
        $csv .= "{$items[1]->id},\"Sistemnya error terus setiap kali dibuka\",negatif,,,,\n";

        $this->actingAs($this->admin())
            ->post(route('admin.evaluation.annotation.upload', $dataset->id), [
                'file' => UploadedFile::fake()->createWithContent('anotasi.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('positive', $items[0]->fresh()->gold_sentiment);
        $this->assertSame('negative', $items[1]->fresh()->gold_sentiment);
    }

    public function test_tombol_hapus_dataset_menolak_bila_sudah_punya_run(): void
    {
        // Menghapus dataset yang sudah diukur akan menghapus angka yang mungkin
        // sudah dikutip di naskah.
        $dataset = $this->dataset();

        \App\Models\EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'status' => 'completed',
            'iteration' => 0,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.evaluation.destroy', $dataset->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(EvaluationDataset::find($dataset->id));
    }

    public function test_seluruh_tombol_evaluasi_tertutup_bagi_pengguna_biasa(): void
    {
        $dataset = $this->dataset();
        $biasa = User::factory()->create(['role' => 'user']);

        foreach ([
            ['post', route('admin.evaluation.run', $dataset->id)],
            ['post', route('admin.evaluation.freeze', $dataset->id)],
            ['post', route('admin.evaluation.unfreeze', $dataset->id)],
            ['post', route('admin.evaluation.drop-unlabeled', $dataset->id)],
            ['delete', route('admin.evaluation.destroy', $dataset->id)],
        ] as [$metode, $url]) {
            $this->actingAs($biasa)->{$metode}($url)->assertForbidden();
        }
    }
}
