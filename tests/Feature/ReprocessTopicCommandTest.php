<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `analysis:reprocess-topic` menghitung ulang HANYA pemodelan topik.
 *
 * Sifat yang harus dijaga, dan alasannya:
 *
 * - Sentimen dan aspek TIDAK ikut dihitung ulang. Itu seluruh alasan perintah
 *   ini ada: pada studi kasus enam lembaga keduanya berjalan atas ribuan
 *   komentar dan memakan puluhan menit, sedangkan topik saja selesai dalam
 *   hitungan menit.
 * - Id analisis dan id hasil tetap, sehingga koreksi pengguna dan golden
 *   dataset yang menunjuk ke sana tidak terputus.
 * - Penjajaran indeks diperiksa sebelum disimpan; hasil yang tidak sejajar
 *   ditolak, bukan disimpan lalu merusak pemasangan teks di seluruh baris.
 */
class ReprocessTopicCommandTest extends TestCase
{
    use RefreshDatabase;

    private function buatAnalisis(array $raw = ['satu', 'dua', 'tiga']): TextAnalysis
    {
        $user = User::factory()->create();
        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'uji topik',
            'input_type' => 'manual',
            'analysis_type' => 'combined',
            'raw_data' => $raw,
            'total_records' => count($raw),
            'status' => 'completed',
            'progress' => 100,
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => array_map(
                fn ($t) => ['sentiment' => 'neutral', 'confidence' => 0.9, 'method' => 'indobert'],
                $raw
            ),
            'document_aspects' => array_fill(0, count($raw), ['pajak']),
            'aspect_results' => [['aspect' => 'pajak', 'count' => count($raw)]],
            'sentiment_distribution' => ['positive' => 0, 'neutral' => 100, 'negative' => 0],
            'topic_results' => [
                'topics' => [['topic_id' => 0, 'words' => ['lama'], 'proportion' => 1.0]],
                'quality' => ['c_v' => 0.30],
                'document_topics' => array_fill(0, count($raw), 0),
            ],
            'metrics' => ['total_texts' => count($raw)],
        ]);

        return $analysis->fresh('result');
    }

    private function palsukanApi(array $documentTopics, float $cv = 0.55): void
    {
        Http::fake([
            '*/api/analyze/topic' => Http::response([
                'status' => 'success',
                'results' => [
                    'topics' => [
                        ['topic_id' => 0, 'words' => ['pajak', 'korupsi'], 'proportion' => 0.6],
                        ['topic_id' => 1, 'words' => ['utang', 'negara'], 'proportion' => 0.4],
                    ],
                    'quality' => ['c_v' => $cv, 'diversity' => 0.9],
                    'document_topics' => $documentTopics,
                ],
            ]),
            '*/api/analyze/association' => Http::response([
                'status' => 'success',
                'results' => ['pmi_top_associations' => [['aspect' => 'pajak', 'topic' => 0]]],
            ]),
        ]);
    }

    public function test_mode_rencana_tidak_mengubah_apa_pun(): void
    {
        $analysis = $this->buatAnalisis();
        Http::fake();

        $this->artisan('analysis:reprocess-topic')->assertExitCode(0);

        $this->assertSame(0.30, $analysis->fresh('result')->result->topic_results['quality']['c_v']);
        Http::assertNothingSent();
    }

    public function test_topik_diperbarui_dan_id_tidak_berubah(): void
    {
        $analysis = $this->buatAnalisis();
        $idHasil = $analysis->result->id;
        $this->palsukanApi([0, 1, 0]);

        $this->artisan('analysis:reprocess-topic --apply')->assertExitCode(0);

        $segar = $analysis->fresh('result');
        $this->assertSame($analysis->id, $segar->id);
        $this->assertSame($idHasil, $segar->result->id);
        $this->assertSame(0.55, $segar->result->topic_results['quality']['c_v']);
        $this->assertSame([0, 1, 0], $segar->result->topic_results['document_topics']);
    }

    public function test_sentimen_dan_aspek_tidak_disentuh(): void
    {
        $analysis = $this->buatAnalisis();
        $prediksiLama = $analysis->result->predictions;
        $aspekLama = $analysis->result->document_aspects;
        $this->palsukanApi([0, 1, 0]);

        $this->artisan('analysis:reprocess-topic --apply')->assertExitCode(0);

        $segar = $analysis->fresh('result')->result;
        $this->assertSame($prediksiLama, $segar->predictions);
        $this->assertSame($aspekLama, $segar->document_aspects);
        $this->assertSame(['positive' => 0, 'neutral' => 100, 'negative' => 0],
            $segar->sentiment_distribution);
    }

    public function test_asosiasi_ikut_dihitung_ulang(): void
    {
        $analysis = $this->buatAnalisis();
        $this->palsukanApi([0, 1, 0]);

        $this->artisan('analysis:reprocess-topic --apply')->assertExitCode(0);

        $segar = $analysis->fresh('result')->result;
        $this->assertNotEmpty($segar->association_results['pmi_top_associations'] ?? []);
    }

    public function test_hasil_yang_tidak_sejajar_ditolak(): void
    {
        $analysis = $this->buatAnalisis(['satu', 'dua', 'tiga']);
        // Layanan mengembalikan 2 id topik untuk 3 teks.
        $this->palsukanApi([0, 1]);

        $this->artisan('analysis:reprocess-topic --apply')->assertExitCode(1);

        // Hasil lama harus utuh; menyimpan yang tidak sejajar akan menggeser
        // pemasangan teks pada seluruh baris setelah titik gesernya.
        $this->assertSame(0.30, $analysis->fresh('result')->result->topic_results['quality']['c_v']);
    }

    public function test_hanya_id_yang_disebut_yang_diproses(): void
    {
        $a = $this->buatAnalisis();
        $b = $this->buatAnalisis();
        $this->palsukanApi([0, 1, 0]);

        $this->artisan('analysis:reprocess-topic --apply --id='.$a->id)->assertExitCode(0);

        $this->assertSame(0.55, $a->fresh('result')->result->topic_results['quality']['c_v']);
        $this->assertSame(0.30, $b->fresh('result')->result->topic_results['quality']['c_v']);
    }

    public function test_ambang_cv_dilaporkan_sebagai_kegagalan(): void
    {
        $this->buatAnalisis();
        $this->palsukanApi([0, 1, 0], 0.35);

        $this->artisan('analysis:reprocess-topic --apply --min-cv=0.4')->assertExitCode(1);
    }

    public function test_ambang_cv_terpenuhi_berhasil(): void
    {
        $this->buatAnalisis();
        $this->palsukanApi([0, 1, 0], 0.45);

        $this->artisan('analysis:reprocess-topic --apply --min-cv=0.4')->assertExitCode(0);
    }
}
