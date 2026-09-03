<?php

namespace Tests\Feature;

use App\Helpers\ChartHelper;
use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssociationDisplayTest extends TestCase
{
    use RefreshDatabase;

    private array $topicResults = [
        'topics' => [
            ['topic_id' => 0, 'words' => ['pajak', 'rakyat', 'korupsi'], 'size' => 3, 'proportion' => 0.6],
            ['topic_id' => 1, 'words' => ['warga', 'patuh', 'dpr'], 'size' => 2, 'proportion' => 0.4],
        ],
        'document_topics' => [0, 0, 0, 1, 1],
        'num_topics' => 2,
    ];

    private array $association = [
        'pmi_top_associations' => [
            ['aspect' => 'pajak', 'topic_id' => 0, 'pmi' => 0.74, 'co_occurrences' => 3],
        ],
        'heatmap_matrix' => [
            ['aspect' => 'pajak', 'topic_0' => 0.74, 'topic_1' => -1.32],
            ['aspect' => 'pelayanan', 'topic_0' => -0.58, 'topic_1' => 0.66],
        ],
    ];

    private array $documentAspects = [
        ['pajak'], ['pajak'], ['pajak', 'pelayanan'], ['pelayanan'], ['pelayanan'],
    ];

    public function test_crosstab_dihitung_dari_dokumen_yang_tersimpan(): void
    {
        $data = ChartHelper::prepareAssociationData(
            $this->association,
            $this->documentAspects,
            $this->topicResults
        );

        $this->assertNotNull($data);
        $this->assertSame(['Topik #1', 'Topik #2'], $data['topics_label']);

        $pajak = collect($data['crosstab'])->firstWhere('aspect', 'Pajak');
        $this->assertSame(3, $pajak['mentions']);
        $this->assertSame([100, 0], $pajak['topics']); // ketiganya ada di topik 0

        $pelayanan = collect($data['crosstab'])->firstWhere('aspect', 'Pelayanan');
        $this->assertSame(3, $pelayanan['mentions']);
        $this->assertSame([33, 67], $pelayanan['topics']);

        $this->assertSame([0.74, -1.32], collect($data['pmi'])->firstWhere('aspect', 'Pajak')['scores']);
    }

    public function test_memakai_label_topik_dari_llm_kalau_ada(): void
    {
        $topicResults = $this->topicResults;
        $topicResults['interpretation'] = [
            0 => ['label' => 'Pajak & Korupsi', 'description' => '...'],
        ];

        $data = ChartHelper::prepareAssociationData($this->association, $this->documentAspects, $topicResults);

        $this->assertSame('Pajak & Korupsi', $data['topics_label'][0]);
    }

    public function test_null_kalau_data_asosiasi_tidak_ada(): void
    {
        $this->assertNull(ChartHelper::prepareAssociationData(null, [], $this->topicResults));
        $this->assertNull(ChartHelper::prepareAssociationData($this->association, [], null));
        $this->assertNull(ChartHelper::prepareAssociationData(
            $this->association,
            $this->documentAspects,
            ['topics' => $this->topicResults['topics']] // tanpa document_topics
        ));
    }

    private function makeCombinedAnalysis(?array $association, array $documentAspects): TextAnalysis
    {
        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis gabungan',
            'input_type' => 'manual',
            'analysis_type' => 'combined',
            'raw_data' => ['a', 'b', 'c', 'd', 'e'],
            'total_records' => 5,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => [['text' => 'a', 'sentiment' => 'positive', 'confidence' => 0.9]],
            'sentiment_distribution' => ['positive' => 100, 'neutral' => 0, 'negative' => 0],
            'aspect_results' => [
                ['aspect' => 'pajak', 'count' => 3, 'sentiments' => ['positive' => 50, 'neutral' => 25, 'negative' => 25]],
            ],
            'topic_results' => $this->topicResults,
            'association_results' => $association,
            'document_aspects' => $documentAspects,
        ]);

        return $analysis;
    }

    public function test_halaman_hasil_menampilkan_asosiasi_asli(): void
    {
        $analysis = $this->makeCombinedAnalysis($this->association, $this->documentAspects);

        $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->assertSee('Interpretasi Hasil')
            ->assertDontSee('Data asosiasi aspek&ndash;topik belum tersedia', false);
    }

    public function test_halaman_hasil_tidak_menampilkan_angka_contoh_saat_data_kosong(): void
    {
        $analysis = $this->makeCombinedAnalysis(null, []);

        $response = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->assertSee('Data asosiasi aspek', false);

        // Angka mock yang dulu tampil seolah-olah hasil analisis
        $response->assertDontSee('Koruptor');
        $response->assertDontSee('+0.71');
    }
}
