<?php

namespace App\Services;

use App\Exceptions\LlmException;
use App\Helpers\ChartHelper;
use App\Models\AnalysisResult;
use App\Models\TextAnalysis;

/**
 * Membangkitkan narasi AI untuk tiap bagian halaman hasil.
 *
 * Prinsip yang dipegang:
 *
 * - Narasi AI adalah *lapisan tambahan*, bukan pengganti. Ringkasan template
 *   yang sudah ada tetap tampil, sehingga halaman hasil tetap bermakna ketika
 *   API key kosong atau kuota habis.
 * - Dibangkitkan saat diminta pengguna, bukan otomatis di dalam
 *   ProcessTextAnalysis. Job antrean tidak boleh bergantung pada layanan luar
 *   yang bisa gagal, dan tidak setiap analisis perlu narasi AI.
 * - Angka dikirim ke model, bukan diminta dihitung oleh model. LLM merangkai
 *   kalimat; seluruh statistik tetap berasal dari pipeline.
 */
class AnalysisInterpretationService
{
    public const SECTIONS = ['overview', 'sentiment', 'aspect', 'topic', 'association'];

    public function __construct(private LlmService $llm) {}

    /**
     * Bagian mana saja yang datanya tersedia untuk analisis ini.
     *
     * @return array<int, string>
     */
    public function availableSections(TextAnalysis $analysis): array
    {
        $result = $analysis->result;

        if (! $result) {
            return [];
        }

        $tersedia = [];

        if (! empty($result->sentiment_distribution)) {
            $tersedia[] = 'sentiment';
        }

        if (! empty($result->normalizedAspectResults())) {
            $tersedia[] = 'aspect';
        }

        if (! empty($result->topic_results['topics'])) {
            $tersedia[] = 'topic';
        }

        if ($this->associationData($result) !== null) {
            $tersedia[] = 'association';
        }

        // Ringkasan menyeluruh baru berguna bila ada lebih dari satu bagian.
        if (count($tersedia) > 1) {
            array_unshift($tersedia, 'overview');
        }

        return $tersedia;
    }

    /**
     * Ambil narasi tersimpan untuk satu bagian, atau null bila belum ada.
     */
    public function stored(AnalysisResult $result, string $section): ?array
    {
        $tersimpan = $result->ai_interpretations[$section] ?? null;

        return is_array($tersimpan) ? $tersimpan : null;
    }

    /**
     * Bangkitkan (atau bangkitkan ulang) narasi untuk satu bagian.
     *
     * @throws LlmException
     */
    public function generate(TextAnalysis $analysis, string $section, bool $regenerate = false): array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new LlmException("Bagian '{$section}' tidak dikenal.");
        }

        $result = $analysis->result;

        if (! $result) {
            throw new LlmException('Analisis ini belum memiliki hasil.');
        }

        if (! $regenerate) {
            $tersimpan = $this->stored($result, $section);

            if ($tersimpan !== null) {
                return $tersimpan;
            }
        }

        $narasi = $section === 'topic'
            ? $this->generateTopic($analysis, $result)
            : $this->generateNarrative($analysis, $result, $section);

        $this->store($result, $section, $narasi);

        return $narasi;
    }

    /**
     * Topik berbeda dari bagian lain: keluarannya label+deskripsi per topik,
     * bukan satu paragraf, dan hasilnya ikut dipakai heatmap serta export PDF.
     */
    private function generateTopic(TextAnalysis $analysis, AnalysisResult $result): array
    {
        $topics = $result->topic_results['topics'] ?? [];

        if (empty($topics)) {
            throw new LlmException('Hasil pemodelan topik tidak ditemukan.');
        }

        $interpretations = $this->llm->generateTopicInterpretations($topics, $this->konteks($analysis));

        if (empty($interpretations)) {
            throw new LlmException('AI tidak menghasilkan label topik yang dapat dipakai.');
        }

        // Tetap ditulis ke topic_results supaya heatmap asosiasi, export PDF,
        // dan analisis lama yang sudah menyimpan di sana melihat bentuk yang sama.
        $topicResults = $result->topic_results;
        $topicResults['interpretation'] = $interpretations;
        $result->topic_results = $topicResults;

        return array_merge($this->llm->provenance(), [
            'type' => 'topics',
            'topics' => $interpretations,
        ]);
    }

    private function generateNarrative(TextAnalysis $analysis, AnalysisResult $result, string $section): array
    {
        $fakta = $this->facts($analysis, $result, $section);

        if ($fakta === null) {
            throw new LlmException('Data untuk bagian ini belum tersedia.');
        }

        $prompt = $this->prompt($analysis, $section, $fakta);

        $schema = [
            'type' => 'object',
            'properties' => [
                'narrative' => ['type' => 'string'],
                'highlights' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['narrative', 'highlights'],
        ];

        $hasil = $this->llm->generate($prompt, $schema);

        $narrative = trim((string) ($hasil['narrative'] ?? ''));

        if ($narrative === '') {
            throw new LlmException('AI mengembalikan narasi kosong. Coba bangkitkan ulang.');
        }

        $highlights = array_values(array_filter(array_map(
            fn ($baris) => trim((string) $baris),
            $hasil['highlights'] ?? []
        )));

        return array_merge($this->llm->provenance(), [
            'type' => 'narrative',
            'narrative' => $narrative,
            'highlights' => array_slice($highlights, 0, 4),
        ]);
    }

    private function store(AnalysisResult $result, string $section, array $narasi): void
    {
        $semua = $result->ai_interpretations ?? [];
        $semua[$section] = $narasi;

        $result->ai_interpretations = $semua;
        $result->save();
    }

    /**
     * Kumpulkan angka yang boleh dilihat model. Sengaja dibatasi: makin sedikit
     * yang dikirim, makin kecil peluang model mengarang di luar data.
     */
    private function facts(TextAnalysis $analysis, AnalysisResult $result, string $section): ?array
    {
        return match ($section) {
            'sentiment' => $this->sentimentFacts($result),
            'aspect' => $this->aspectFacts($result),
            'association' => $this->associationFacts($result),
            'overview' => $this->overviewFacts($analysis, $result),
            default => null,
        };
    }

    private function sentimentFacts(AnalysisResult $result): ?array
    {
        $distribusi = $result->sentiment_distribution;

        if (empty($distribusi)) {
            return null;
        }

        $fakta = [
            'jumlah_teks' => count($result->predictions ?? []),
            'distribusi_persen' => $distribusi,
        ];

        // Contoh kalimat membantu model menyebut isi, bukan hanya angka.
        foreach (['positive', 'negative'] as $label) {
            $contoh = $this->contohTeks($result, $label, 3);

            if (! empty($contoh)) {
                $fakta['contoh_'.$label] = $contoh;
            }
        }

        $antrean = $result->metrics['review_queue']['count'] ?? null;

        if ($antrean) {
            $fakta['jumlah_perlu_ditinjau'] = $antrean;
        }

        return $fakta;
    }

    private function aspectFacts(AnalysisResult $result): ?array
    {
        $aspek = $result->normalizedAspectResults();

        if (empty($aspek)) {
            return null;
        }

        return [
            'jumlah_aspek' => count($aspek),
            // Sepuluh teratas sudah cukup; sisanya menambah token tanpa menambah makna.
            'aspek' => array_slice($aspek, 0, 10),
        ];
    }

    private function associationFacts(AnalysisResult $result): ?array
    {
        $data = $this->associationData($result);

        if ($data === null) {
            return null;
        }

        return [
            'label_topik' => $data['topics_label'] ?? [],
            'kata_kunci_topik' => $data['topics_desc'] ?? [],
            'pmi' => array_slice($data['pmi'] ?? [], 0, 8),
            'crosstab' => array_slice($data['crosstab'] ?? [], 0, 8),
        ];
    }

    private function overviewFacts(TextAnalysis $analysis, AnalysisResult $result): ?array
    {
        $fakta = array_filter([
            'jenis_analisis' => $analysis->analysis_type,
            'jumlah_teks' => $analysis->total_records,
            'ringkasan_sistem' => $result->summary,
            'distribusi_sentimen_persen' => $result->sentiment_distribution ?: null,
            'aspek_teratas' => array_slice($result->normalizedAspectResults(), 0, 5) ?: null,
            'topik' => $this->ringkasanTopik($result) ?: null,
        ], fn ($nilai) => $nilai !== null && $nilai !== []);

        return count($fakta) > 1 ? $fakta : null;
    }

    /**
     * @return array<int, array{label: string, kata_kunci: array, proporsi: float}>
     */
    private function ringkasanTopik(AnalysisResult $result): array
    {
        $topics = $result->topic_results['topics'] ?? [];
        $interpretation = $result->topic_results['interpretation'] ?? [];
        $ringkas = [];

        foreach (array_slice($topics, 0, 8) as $index => $topic) {
            $id = $topic['topic_id'] ?? $index;

            $ringkas[] = [
                'label' => $interpretation[$id]['label'] ?? ('Topik '.$id),
                'kata_kunci' => array_slice($topic['words'] ?? $topic['keywords'] ?? [], 0, 6),
                'proporsi' => round((float) ($topic['proportion'] ?? 0) * 100, 1),
            ];
        }

        return $ringkas;
    }

    /**
     * @return array<int, string>
     */
    private function contohTeks(AnalysisResult $result, string $label, int $jumlah): array
    {
        $contoh = [];

        foreach ($result->predictions ?? [] as $prediksi) {
            if (count($contoh) >= $jumlah) {
                break;
            }

            if (($prediksi['sentiment'] ?? null) !== $label) {
                continue;
            }

            $teks = trim((string) ($prediksi['original_text'] ?? $prediksi['text'] ?? ''));

            if ($teks !== '') {
                $contoh[] = mb_substr($teks, 0, 200);
            }
        }

        return $contoh;
    }

    private function associationData(AnalysisResult $result): ?array
    {
        return ChartHelper::prepareAssociationData(
            $result->association_results ?? ($result->topic_results['association'] ?? null),
            $result->document_aspects ?? [],
            $result->topic_results
        );
    }

    private function prompt(TextAnalysis $analysis, string $section, array $fakta): string
    {
        $tugas = match ($section) {
            'sentiment' => 'Jelaskan apa yang ditunjukkan distribusi sentimen ini: kecenderungan utamanya, '
                .'seberapa terbelah opininya, dan apa artinya bagi pihak yang membaca hasil ini.',
            'aspect' => 'Jelaskan aspek mana yang paling banyak dibicarakan, aspek mana yang sentimennya '
                .'paling bermasalah, dan aspek mana yang paling baik diterima.',
            'association' => 'Jelaskan aspek dan topik mana yang paling erat berkaitan menurut nilai PMI, '
                .'serta apa makna keterkaitan itu. PMI positif berarti pasangan itu muncul bersama lebih '
                .'sering daripada yang diharapkan secara kebetulan.',
            'overview' => 'Tulis ringkasan eksekutif yang menyatukan seluruh hasil analisis di bawah ini '
                .'menjadi satu gambaran utuh.',
            default => 'Jelaskan hasil analisis di bawah ini.',
        };

        return 'Anda seorang analis data yang menjelaskan hasil analisis teks berbahasa Indonesia '
            ."kepada pembaca non-teknis.\n\n"
            ."Konteks: {$this->konteks($analysis)}\n\n"
            ."Tugas: {$tugas}\n\n"
            ."Data hasil analisis (format JSON):\n"
            .json_encode($fakta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n\n"
            ."Aturan yang wajib dipatuhi:\n"
            ."- Tulis dalam bahasa Indonesia yang lugas, tanpa istilah teknis yang tidak dijelaskan.\n"
            .'- Gunakan hanya angka yang ada pada data di atas. Jangan menghitung ulang, menaksir, '
            ."atau menyebut angka yang tidak tertulis di sana.\n"
            ."- Jangan menyimpulkan sebab-akibat; data ini hanya menunjukkan pola, bukan sebab.\n"
            // Judul analisis sering berupa catatan teknis ("Final combined
            // 15:17:59") dan terbaca janggal kalau dikutip di dalam narasi.
            ."- Jangan mengutip judul analisis di dalam kalimat.\n"
            ."- 'narrative' berisi 2 sampai 4 kalimat dalam satu paragraf, tanpa markdown.\n"
            .'- \'highlights\' berisi maksimal 4 poin temuan, masing-masing satu kalimat pendek.';
    }

    private function konteks(TextAnalysis $analysis): string
    {
        $konteks = 'Analisis berjudul "'.$analysis->title.'"';

        if ($analysis->total_records) {
            $konteks .= ', mencakup '.$analysis->total_records.' teks';
        }

        return $konteks.'.';
    }
}
