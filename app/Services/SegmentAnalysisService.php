<?php

namespace App\Services;

use App\Models\TextAnalysis;

/**
 * Memecah hasil analisis menurut kolom pemisah yang dipilih pengguna.
 *
 * Seluruhnya dihitung ulang dari `predictions` yang sudah tersimpan - tidak
 * ada permintaan baru ke NLP API. Itu inti fiturnya: pengguna tidak perlu
 * menjalankan analisis terpisah untuk tiap tanggal, sumber, atau video.
 *
 * Penyejajaran memakai `original_index` pada tiap prediksi, bukan urutan
 * larik. Pada korpus yang diproses per batch, urutan prediksi bisa tidak sama
 * dengan urutan teks masukan, dan mengandalkan urutan akan memasangkan
 * kalimat dengan segmen yang salah.
 */
class SegmentAnalysisService
{
    /** Ambang jumlah segmen sebelum sisanya digabung. */
    public const MAX_SEGMEN = 30;

    /**
     * Kolom pemisah yang tersedia untuk analisis ini.
     *
     * @return array<int, string>
     */
    public function availableColumns(TextAnalysis $analysis): array
    {
        $data = $analysis->segment_data;

        if (! is_array($data) || empty($data['columns']) || empty($data['rows'])) {
            return [];
        }

        return array_values(array_filter(
            $data['columns'],
            fn ($kolom) => is_string($kolom) && $kolom !== ''
        ));
    }

    /**
     * Hitung rincian hasil untuk satu kolom pemisah.
     *
     * @return array{column: string, segments: array, total_segments: int, ungrouped: int}|null
     */
    public function breakdown(TextAnalysis $analysis, string $column): ?array
    {
        if (! in_array($column, $this->availableColumns($analysis), true)) {
            return null;
        }

        $result = $analysis->result;

        if (! $result || empty($result->predictions)) {
            return null;
        }

        $nilaiBaris = $analysis->segment_data['rows'] ?? [];
        $documentAspects = $result->document_aspects ?? [];

        // Topik dari model topic modeling, satu topik dominan per dokumen -
        // sama seperti dipakai heatmap PMI (ChartHelper::prepareAssociationData).
        // Hanya terisi untuk analisis 'combined' (topic modeling berjalan
        // sekali atas seluruh teks, tidak dibatch, jadi indeksnya sejajar
        // langsung dengan original_index seperti document_aspects).
        $documentTopics = $result->topic_results['document_topics'] ?? [];
        $labelTopik = $this->labelTopik($result->topic_results);

        $kelompok = [];
        $tanpaNilai = 0;

        foreach ($result->predictions as $posisi => $prediksi) {
            $index = $prediksi['original_index'] ?? $posisi;
            $nilai = trim((string) ($nilaiBaris[$index][$column] ?? ''));

            if ($nilai === '') {
                $tanpaNilai++;

                continue;
            }

            $kelompok[$nilai] ??= [
                'label' => $nilai,
                'count' => 0,
                'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
                'aspects' => [],
                'topics' => [],
            ];

            $kelompok[$nilai]['count']++;

            $sentimen = $prediksi['sentiment'] ?? null;

            if (isset($kelompok[$nilai]['sentiments'][$sentimen])) {
                $kelompok[$nilai]['sentiments'][$sentimen]++;
            }

            // Analisis gabungan menyimpan aspek langsung pada prediksi;
            // analisis aspek murni hanya punya document_aspects.
            $aspek = $prediksi['aspects'] ?? ($documentAspects[$index] ?? []);

            foreach (is_array($aspek) ? $aspek : [] as $namaAspek) {
                $namaAspek = is_string($namaAspek) ? $namaAspek : ($namaAspek['aspect'] ?? null);

                if (! $namaAspek) {
                    continue;
                }

                $kelompok[$nilai]['aspects'][$namaAspek] = ($kelompok[$nilai]['aspects'][$namaAspek] ?? 0) + 1;
            }

            $topicId = $documentTopics[$index] ?? null;

            // -1 (atau sejenisnya) menandai dokumen outlier BERTopic - tidak
            // masuk topik mana pun, jadi tidak berarti apa-apa untuk dihitung.
            if ($topicId !== null && $topicId >= 0 && isset($labelTopik[$topicId])) {
                $kelompok[$nilai]['topics'][$topicId] = ($kelompok[$nilai]['topics'][$topicId] ?? 0) + 1;
            }
        }

        if (empty($kelompok)) {
            return null;
        }

        $segmen = $this->finalisasi($kelompok, $labelTopik);

        return [
            'column' => $column,
            'segments' => $segmen,
            'total_segments' => count($kelompok),
            'ungrouped' => $tanpaNilai,
        ];
    }

    /**
     * Peta topic_id => label, memakai label AI kalau sudah dibangkitkan,
     * sama seperti ChartHelper::prepareAssociationData supaya nama topik
     * yang tampil di rincian per pemisah dan di heatmap PMI tidak berselisih.
     *
     * @return array<int, string>
     */
    private function labelTopik(?array $topicResults): array
    {
        $label = [];

        foreach ($topicResults['topics'] ?? [] as $index => $topic) {
            $id = $topic['topic_id'] ?? $index;

            if ($id < 0) {
                continue; // outlier BERTopic tidak punya label
            }

            $label[$id] = $topicResults['interpretation'][$id]['label']
                ?? $topic['topic_label']
                ?? ('Topik #'.($id + 1));
        }

        return $label;
    }

    /**
     * Ubah hitungan mentah menjadi persentase dan urutkan.
     *
     * @param  array<string, array>  $kelompok
     * @param  array<int, string>  $labelTopik
     */
    private function finalisasi(array $kelompok, array $labelTopik = []): array
    {
        // Segmen terbesar lebih dulu: itu yang paling menentukan gambaran
        // keseluruhan, dan membuat pemotongan MAX_SEGMEN membuang yang paling
        // sedikit isinya.
        uasort($kelompok, fn ($a, $b) => $b['count'] <=> $a['count']);

        $segmen = [];

        foreach (array_slice($kelompok, 0, self::MAX_SEGMEN, true) as $baris) {
            $jumlah = $baris['count'];

            arsort($baris['aspects']);
            arsort($baris['topics']);

            $segmen[] = [
                'label' => $baris['label'],
                'count' => $jumlah,
                'sentiments' => $baris['sentiments'],
                // Persentase, sama seperti sentiment_distribution di tingkat
                // analisis, supaya kedua angka bisa dibandingkan langsung.
                'distribution' => [
                    'positive' => round(($baris['sentiments']['positive'] / $jumlah) * 100, 1),
                    'neutral' => round(($baris['sentiments']['neutral'] / $jumlah) * 100, 1),
                    'negative' => round(($baris['sentiments']['negative'] / $jumlah) * 100, 1),
                ],
                'dominant' => array_search(max($baris['sentiments']), $baris['sentiments']),
                'top_aspects' => array_slice(
                    array_map(
                        fn ($nama, $hitung) => ['aspect' => $nama, 'count' => $hitung],
                        array_keys($baris['aspects']),
                        array_values($baris['aspects'])
                    ),
                    0,
                    5
                ),
                // Kosong untuk analisis yang tidak menjalankan topic modeling
                // (sentiment/aspect murni) - dirender sebagai kolom kosong,
                // bukan disembunyikan, supaya bentuknya konsisten antar segmen.
                'top_topics' => array_slice(
                    array_map(
                        fn ($id, $hitung) => ['topic_id' => $id, 'label' => $labelTopik[$id] ?? ('Topik #'.($id + 1)), 'count' => $hitung],
                        array_keys($baris['topics']),
                        array_values($baris['topics'])
                    ),
                    0,
                    5
                ),
            ];
        }

        return $segmen;
    }
}
