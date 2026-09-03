<?php

namespace Tests\Feature;

use App\Jobs\ProcessTextAnalysis;
use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * analysis:reprocess-aspect mengantre ulang analisis yang sentimen per-aspeknya
 * dihitung sebelum perbaikan klausa, dan yang penjajaran indeksnya bergeser.
 */
class ReprocessAspectCommandTest extends TestCase
{
    use RefreshDatabase;

    private function buatAnalisis(
        array $aspectResults,
        array $raw = ['a', 'b'],
        ?array $docAspects = null,
        ?int $version = null
    ): TextAnalysis {
        $user = User::factory()->create();
        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'uji',
            'input_type' => 'manual',
            'analysis_type' => 'aspect',
            'raw_data' => $raw,
            'total_records' => count($raw),
            'status' => 'completed',
            'progress' => 100,
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'aspect_results' => $aspectResults,
            'document_aspects' => $docAspects ?? array_fill(0, count($raw), []),
            'metrics' => $version === null ? [] : ['pipeline_version' => $version],
        ]);

        return $analysis->fresh('result');
    }

    public function test_hasil_tanpa_penanda_versi_terdeteksi(): void
    {
        Queue::fake();

        // hasil lama tidak punya metrics.pipeline_version sama sekali
        $this->buatAnalisis([
            ['aspect' => 'harga', 'count' => 2, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]],
        ]);

        $this->artisan('analysis:reprocess-aspect')
            ->expectsOutputToContain('pipeline v0')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_versi_lebih_lama_terdeteksi(): void
    {
        Queue::fake();

        $this->buatAnalisis(
            [['aspect' => 'harga', 'count' => 1, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]]],
            ['a', 'b'], null, ProcessTextAnalysis::PIPELINE_VERSION - 1
        );

        $this->artisan('analysis:reprocess-aspect')
            ->expectsOutputToContain('terbaru v'.ProcessTextAnalysis::PIPELINE_VERSION)
            ->assertExitCode(0);
    }

    public function test_analisis_benar_tanpa_kelas_netral_tidak_ikut_diantre(): void
    {
        Queue::fake();

        // Kalimat tegas memang tidak menghasilkan kelas netral. Heuristik lama
        // salah menandai hasil seperti ini sebagai versi lama.
        $this->buatAnalisis(
            [
                ['aspect' => 'harga', 'count' => 1, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]],
                ['aspect' => 'pelayanan', 'count' => 1, 'sentiments' => ['positive' => 100, 'neutral' => 0, 'negative' => 0]],
            ],
            ['a', 'b'], null, ProcessTextAnalysis::PIPELINE_VERSION
        );

        $this->artisan('analysis:reprocess-aspect')
            ->expectsOutputToContain('sudah memakai perhitungan terbaru')
            ->assertExitCode(0);
    }

    public function test_hasil_terbaru_tidak_ikut_diantre(): void
    {
        Queue::fake();

        $this->buatAnalisis(
            [['aspect' => 'harga', 'count' => 2, 'sentiments' => ['positive' => 0, 'neutral' => 33.3, 'negative' => 66.7]]],
            ['a', 'b'], null, ProcessTextAnalysis::PIPELINE_VERSION
        );

        $this->artisan('analysis:reprocess-aspect')
            ->expectsOutputToContain('sudah memakai perhitungan terbaru')
            ->assertExitCode(0);
    }

    public function test_penjajaran_bergeser_terdeteksi(): void
    {
        Queue::fake();

        // 3 teks tetapi document_aspects hanya 2 -> indeks bergeser
        $this->buatAnalisis(
            [['aspect' => 'harga', 'count' => 1, 'sentiments' => ['positive' => 0, 'neutral' => 50, 'negative' => 50]]],
            ['a', 'b', 'c'],
            [[], []],
            ProcessTextAnalysis::PIPELINE_VERSION
        );

        $this->artisan('analysis:reprocess-aspect')
            ->expectsOutputToContain('penjajaran bergeser')
            ->assertExitCode(0);
    }

    public function test_apply_mengantrekan_job_dan_mereset_status(): void
    {
        Queue::fake();

        $analysis = $this->buatAnalisis([
            ['aspect' => 'harga', 'count' => 2, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]],
            ['aspect' => 'pelayanan', 'count' => 1, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]],
        ]);

        $this->artisan('analysis:reprocess-aspect', ['--apply' => true])->assertExitCode(0);

        Queue::assertPushed(ProcessTextAnalysis::class, 1);
        $this->assertSame('pending', $analysis->fresh()->status);
        $this->assertSame(0, $analysis->fresh()->progress);
    }

    public function test_opsi_id_membatasi_sasaran(): void
    {
        Queue::fake();

        $lama = ['aspect' => 'harga', 'count' => 1, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]];
        $a = $this->buatAnalisis([$lama, $lama]);
        $this->buatAnalisis([$lama, $lama]);

        $this->artisan('analysis:reprocess-aspect', ['--apply' => true, '--id' => [$a->id]])
            ->assertExitCode(0);

        Queue::assertPushed(ProcessTextAnalysis::class, 1);
    }

    public function test_kolom_json_ter_encode_ganda_tidak_membuat_error(): void
    {
        Queue::fake();

        $analysis = $this->buatAnalisis([
            ['aspect' => 'harga', 'count' => 1, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]],
            ['aspect' => 'pajak', 'count' => 1, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]],
        ]);

        // Simulasikan baris lama: raw_data tersimpan sebagai string JSON
        \DB::table('text_analyses')->where('id', $analysis->id)
            ->update(['raw_data' => json_encode(json_encode(['a', 'b']))]);

        $this->artisan('analysis:reprocess-aspect')->assertExitCode(0);
    }
}
