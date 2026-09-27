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
    public const SECTIONS = ['overview', 'sentiment', 'aspect', 'topic', 'association', 'segment'];

    public function __construct(
        private LlmService $llm,
        private SegmentAnalysisService $segmenter
    ) {}

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
    public function generate(
        TextAnalysis $analysis,
        string $section,
        bool $regenerate = false,
        ?string $column = null
    ): array {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new LlmException("Bagian '{$section}' tidak dikenal.");
        }

        $result = $analysis->result;

        if (! $result) {
            throw new LlmException('Analisis ini belum memiliki hasil.');
        }

        // Narasi segmen terikat pada satu kolom pemisah, jadi disimpan
        // per kolom. Kalau semuanya berbagi satu kunci, berpindah kolom akan
        // menampilkan narasi tentang kolom yang lain.
        $kunci = $section === 'segment' ? 'segment:'.$column : $section;

        if ($section === 'segment' && ($column === null || $column === '')) {
            throw new LlmException('Kolom pemisah belum dipilih.');
        }

        if (! $regenerate) {
            $tersimpan = $this->stored($result, $kunci);

            if ($tersimpan !== null) {
                return $tersimpan;
            }
        }

        $narasi = $section === 'topic'
            ? $this->generateTopic($analysis, $result)
            : $this->generateNarrative($analysis, $result, $section, $column);

        $this->store($result, $kunci, $narasi);

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

    private function generateNarrative(
        TextAnalysis $analysis,
        AnalysisResult $result,
        string $section,
        ?string $column = null
    ): array {
        $fakta = $this->facts($analysis, $result, $section, $column);

        if ($fakta === null) {
            throw new LlmException('Data untuk bagian ini belum tersedia.');
        }

        $prompt = $this->prompt($analysis, $fakta);

        // Empat medan, bukan dua. Narasi saja menjawab "apa isinya" tetapi
        // meninggalkan pembaca non-teknis pada pertanyaan yang sebenarnya:
        // kenapa angka ini perlu dilihat, dan besok harus berbuat apa.
        $schema = [
            'type' => 'object',
            'properties' => [
                'narrative' => ['type' => 'string'],
                'highlights' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'why' => ['type' => 'string'],
                'how' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['narrative', 'highlights', 'why', 'how'],
        ];

        $hasil = $this->llm->generate($prompt, $schema, systemInstruction: $this->systemInstruction($section));

        $narrative = trim((string) ($hasil['narrative'] ?? ''));

        if ($narrative === '') {
            throw new LlmException('AI mengembalikan narasi kosong. Coba bangkitkan ulang.');
        }

        $bersihkan = fn ($daftar) => array_values(array_filter(array_map(
            fn ($baris) => trim((string) $baris),
            is_array($daftar) ? $daftar : []
        )));

        return array_merge($this->llm->provenance(), [
            'type' => 'narrative',
            'narrative' => $narrative,
            'highlights' => array_slice($bersihkan($hasil['highlights'] ?? []), 0, 4),
            'why' => trim((string) ($hasil['why'] ?? '')),
            'how' => array_slice($bersihkan($hasil['how'] ?? []), 0, 4),
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
    private function facts(
        TextAnalysis $analysis,
        AnalysisResult $result,
        string $section,
        ?string $column = null
    ): ?array {
        return match ($section) {
            'sentiment' => $this->sentimentFacts($result),
            'aspect' => $this->aspectFacts($result),
            'association' => $this->associationFacts($result),
            'overview' => $this->overviewFacts($analysis, $result),
            'segment' => $this->segmentFacts($analysis, $column),
            default => null,
        };
    }

    /**
     * Rincian per segmen, ditambah distribusi keseluruhan sebagai pembanding.
     * Tanpa pembanding itu model tidak bisa menyebut segmen mana yang
     * menyimpang - hanya bisa membacakan ulang angkanya satu per satu.
     */
    private function segmentFacts(TextAnalysis $analysis, ?string $column): ?array
    {
        if (! $column) {
            return null;
        }

        $breakdown = $this->segmenter->breakdown($analysis, $column);

        if ($breakdown === null) {
            return null;
        }

        return [
            'kolom_pemisah' => $column,
            'distribusi_keseluruhan_persen' => $analysis->result->sentiment_distribution,
            'jumlah_segmen' => $breakdown['total_segments'],
            'segmen' => array_map(fn ($segmen) => [
                'nilai' => $segmen['label'],
                'jumlah_teks' => $segmen['count'],
                'distribusi_persen' => $segmen['distribution'],
                'aspek_teratas' => $segmen['top_aspects'],
                // Kosong untuk analisis tanpa topic modeling; dikirim apa
                // adanya, bukan dihilangkan, supaya modelnya sendiri yang
                // memutuskan tidak ada yang bisa dikatakan soal topik.
                'topik_dominan' => array_map(fn ($t) => $t['label'], $segmen['top_topics']),
            ], array_slice($breakdown['segments'], 0, 15)),
        ];
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

        // Aspek dengan penyebutan terbanyak dulu - konsisten dengan urutan
        // yang dilihat pengguna di grafik dan kartu Detail Aspek.
        usort($aspek, fn ($a, $b) => $b['count'] <=> $a['count']);

        $fakta = [
            'jumlah_aspek' => count($aspek),
            // Sepuluh teratas sudah cukup; sisanya menambah token tanpa menambah makna.
            'aspek' => array_slice($aspek, 0, 10),
        ];

        // Contoh kalimat untuk aspek paling negatif, sama seperti sentimen -
        // tanpa ini model hanya bisa melaporkan persentase, tidak bisa
        // menunjuk isi keluhannya. Diabaikan kalau penyebutannya terlalu
        // sedikit (persentase dari 1-2 baris tidak berarti apa-apa).
        $palingBermasalah = collect($aspek)
            ->filter(fn ($a) => $a['count'] >= 3)
            ->sortByDesc(fn ($a) => $a['sentiments']['negative'] ?? 0)
            ->first();

        if ($palingBermasalah) {
            $contoh = $this->contohAspek($result, $palingBermasalah['aspect'], 3);

            if (! empty($contoh)) {
                $fakta['contoh_aspek_paling_negatif'] = [
                    'aspek' => $palingBermasalah['aspect'],
                    'kalimat' => $contoh,
                ];
            }
        }

        return $fakta;
    }

    /**
     * @return array<int, string>
     */
    private function contohAspek(AnalysisResult $result, string $aspectName, int $jumlah): array
    {
        $contoh = [];

        foreach ($result->predictions ?? [] as $prediksi) {
            if (count($contoh) >= $jumlah) {
                break;
            }

            $aspek = $prediksi['aspects'] ?? [];

            if (! in_array($aspectName, is_array($aspek) ? $aspek : [], true)) {
                continue;
            }

            $teks = trim((string) ($prediksi['original_text'] ?? $prediksi['text'] ?? ''));

            if ($teks !== '') {
                $contoh[] = mb_substr($teks, 0, 200);
            }
        }

        return $contoh;
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

    /**
     * Aturan perilaku yang berlaku sama untuk setiap permintaan pada satu
     * bagian - persona, larangan mengarang angka, format keluaran. Dikirim
     * lewat `systemInstruction`, terpisah dari tugas dan data pada giliran
     * user di {@see prompt()}, karena Gemini lebih konsisten mematuhi aturan
     * perilaku ketika ditaruh di systemInstruction daripada ditumpuk jadi
     * satu blok teks bersama data yang berubah-ubah tiap permintaan.
     */
    /**
     * Apa gunanya satu bagian analisis, dalam kalimat yang bisa dipakai model
     * sebagai bahan - bukan disalin mentah.
     *
     * Ditulis di sisi kode, bukan diserahkan sepenuhnya ke model, karena
     * kegunaan sebuah metode analisis adalah pengetahuan tetap: ia tidak
     * berubah mengikuti data, dan membiarkan model mengarangnya sendiri tiap
     * kali membuka peluang penjelasan yang keliru tentang alat yang dipakai.
     */
    private function kegunaanBagian(string $section): string
    {
        return match ($section) {
            'sentiment' => 'Analisis sentimen memberi tahu arah opini secara keseluruhan tanpa harus '
                .'membaca semua teks satu per satu. Gunanya: mengukur penerimaan publik atas suatu '
                .'isu, menemukan seberapa terbelah pendapat yang ada, dan menjadi angka dasar yang '
                .'bisa dibandingkan lagi setelah ada tindakan atau perubahan kebijakan.',
            'aspect' => 'Ekstraksi aspek menjawab pertanyaan yang tidak bisa dijawab sentimen '
                .'keseluruhan: bukan sekadar "orang senang atau tidak", melainkan HAL APA yang mereka '
                .'keluhkan atau puji. Gunanya: mengarahkan perbaikan ke sasaran yang tepat, memisahkan '
                .'keluhan yang banyak disebut dari yang hanya muncul sekali, dan memantau apakah aspek '
                .'bermasalah membaik setelah ditangani.',
            'topic' => 'Pemodelan topik mengelompokkan teks menurut tema yang muncul sendiri dari '
                .'datanya, tanpa daftar kategori yang ditentukan lebih dulu. Gunanya: menemukan isu '
                .'yang tidak terpikir sebelumnya, meringkas ribuan teks menjadi beberapa tema yang '
                .'bisa dibaca, dan melihat tema mana yang paling banyak menyita perhatian.',
            'association' => 'Asosiasi aspek dan topik menunjukkan pasangan mana yang cenderung muncul '
                .'bersamaan lebih sering daripada kebetulan. Gunanya: menempatkan sebuah keluhan pada '
                .'konteks percakapan tempat ia hidup, sehingga penanganannya menyasar pembicaraan yang '
                .'tepat dan bukan aspeknya saja secara terpisah.',
            'segment' => 'Rincian per pemisah membandingkan kelompok data, misalnya per video, per '
                .'sumber, atau per periode. Gunanya: menemukan kelompok yang menyimpang dari pola '
                .'umum, sehingga penanganan bisa diarahkan ke kelompok itu alih-alih diratakan ke '
                .'seluruh data.',
            'overview' => 'Ringkasan menyeluruh menyatukan sentimen, aspek, dan topik menjadi satu '
                .'gambaran. Gunanya: memberi pembaca yang tidak sempat menelusuri tiap bagian satu '
                .'kesimpulan yang bisa dipakai mengambil keputusan.',
            default => 'Jelaskan apa gunanya hasil ini bagi pembacanya.',
        };
    }

    private function systemInstruction(string $section): string
    {
        $tugas = match ($section) {
            'sentiment' => 'Jelaskan apa yang ditunjukkan distribusi sentimen ini: kecenderungan utamanya, '
                .'seberapa terbelah opininya, dan apa artinya bagi pihak yang membaca hasil ini.',
            'aspect' => 'Jelaskan aspek mana yang paling banyak dibicarakan, aspek mana yang sentimennya '
                .'paling bermasalah, dan aspek mana yang paling baik diterima. Kalau ada contoh kalimat '
                .'pada data, boleh disinggung sebagai ilustrasi - jangan mengarang kalimat baru.',
            'association' => 'Jelaskan aspek dan topik mana yang paling erat berkaitan menurut nilai PMI, '
                .'serta apa makna keterkaitan itu. PMI positif berarti pasangan itu muncul bersama lebih '
                .'sering daripada yang diharapkan secara kebetulan.',
            'overview' => 'Tulis ringkasan eksekutif yang menyatukan seluruh hasil analisis di bawah ini '
                .'menjadi satu gambaran utuh.',
            'segment' => 'Bandingkan segmen-segmen di bawah ini. Sebutkan segmen mana yang paling '
                .'menyimpang dari distribusi keseluruhan, ke arah mana penyimpangannya, dan aspek serta '
                .'topik apa yang menyertainya (kalau field topik_dominan tersedia dan berisi). Perhatikan '
                .'jumlah teks tiap segmen: segmen dengan sedikit teks tidak cukup kuat untuk disimpulkan.',
            default => 'Jelaskan hasil analisis di bawah ini.',
        };

        return 'Anda seorang analis data yang menjelaskan hasil analisis teks berbahasa Indonesia '
            ."kepada pembaca non-teknis.\n\n"
            ."Tugas: {$tugas}\n\n"
            ."Kegunaan bagian ini sebagai bahan Anda: {$this->kegunaanBagian($section)}\n\n"
            ."Aturan yang wajib dipatuhi:\n"
            ."- Tulis dalam bahasa Indonesia yang lugas, tanpa istilah teknis yang tidak dijelaskan.\n"
            .'- Gunakan hanya angka yang ada pada data yang diberikan pengguna. Jangan menghitung ulang, '
            ."menaksir, atau menyebut angka yang tidak tertulis di sana.\n"
            ."- Jangan menyimpulkan sebab-akibat; data ini hanya menunjukkan pola, bukan sebab.\n"
            // Judul analisis sering berupa catatan teknis ("Final combined
            // 15:17:59") dan terbaca janggal kalau dikutip di dalam narasi.
            ."- Jangan mengutip judul analisis di dalam kalimat.\n"
            .'- Jangan memakai tanda pisah panjang (em dash). Pecah menjadi dua kalimat, atau pakai '
            ."koma, titik dua, atau tanda kurung.\n\n"
            ."Isi keempat medan berikut:\n"
            ."- 'narrative': 2 sampai 4 kalimat dalam satu paragraf, tanpa markdown. Isinya APA yang "
            ."ditunjukkan angka-angka itu.\n"
            ."- 'highlights': maksimal 4 poin temuan, masing-masing satu kalimat pendek.\n"
            .'- \'why\': 1 sampai 2 kalimat tentang MENGAPA bagian analisis ini penting dilihat, '
            .'dikaitkan dengan isi datanya sendiri. Jangan menyalin kalimat kegunaan di atas mentah-mentah; '
            ."jadikan ia khusus untuk data ini.\n"
            .'- \'how\': 2 sampai 4 langkah konkret BAGAIMANA pembaca memanfaatkan temuan ini, '
            .'masing-masing satu kalimat yang diawali kata kerja. Langkahnya harus mengacu pada angka '
            .'atau nama yang benar-benar muncul di data, bukan saran umum yang berlaku untuk analisis apa pun.';
    }

    /**
     * Konteks dan data numerik untuk SATU permintaan - bagian yang berubah
     * setiap kali, ditaruh pada giliran user, bukan systemInstruction.
     */
    private function prompt(TextAnalysis $analysis, array $fakta): string
    {
        return "Konteks: {$this->konteks($analysis)}\n\n"
            ."Data hasil analisis (format JSON):\n"
            .json_encode($fakta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
