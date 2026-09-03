<?php

namespace App\Services;

use App\Models\AnalysisResult;

/**
 * Penyaringan, pencarian, dan paginasi daftar prediksi.
 *
 * Prediksi disimpan sebagai satu kolom JSON, bukan baris tabel, sehingga
 * paginasinya dikerjakan di PHP. Sebelumnya seluruh prediksi dirender sekaligus
 * sebagai kartu HTML dan disaring di browser - terukur 2,7 MB untuk 289 baris,
 * dan akan jauh lebih berat untuk korpus 885 komentar.
 */
class PredictionQueryService
{
    public const PER_PAGE = 25;

    public const FILTERS = ['all', 'positive', 'neutral', 'negative', 'review'];

    /**
     * @return array{items: array, meta: array}
     */
    public function paginate(
        AnalysisResult $result,
        string $filter = 'all',
        string $search = '',
        int $page = 1,
        int $perPage = self::PER_PAGE
    ): array {
        $predictions = is_array($result->predictions) ? $result->predictions : [];
        $reviewRanks = $this->reviewRanks($result);

        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
        $search = trim($search);
        $perPage = max(1, min(100, $perPage));

        $rows = [];

        foreach ($predictions as $index => $prediction) {
            $originalIndex = $prediction['original_index'] ?? $index;

            $rows[] = [
                'prediction' => $prediction,
                // Nomor urut mengikuti posisi baris tersimpan, supaya tetap
                // berurutan walaupun halaman yang ditampilkan berpindah-pindah.
                'position' => $index + 1,
                'index' => $index,
                'review_rank' => $reviewRanks[$originalIndex] ?? -1,
            ];
        }

        $total = count($rows);
        $rows = $this->applyFilter($rows, $filter);
        $rows = $this->applySearch($rows, $search);

        // Mode tinjauan diurutkan dari yang paling tidak yakin, sesuai urutan
        // indices dari NLP API; mode lain memakai urutan aslinya.
        if ($filter === 'review') {
            usort($rows, fn ($a, $b) => $a['review_rank'] <=> $b['review_rank']);
        }

        $filtered = count($rows);
        $lastPage = max(1, (int) ceil($filtered / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        $items = array_slice($rows, $offset, $perPage);

        return [
            'items' => $items,
            'meta' => [
                'total' => $total,
                'filtered' => $filtered,
                'page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'from' => $filtered === 0 ? 0 : $offset + 1,
                'to' => $offset + count($items),
                'filter' => $filter,
                'search' => $search,
            ],
        ];
    }

    /**
     * Peringkat antrean tinjauan per indeks teks asli.
     */
    public function reviewRanks(AnalysisResult $result): array
    {
        $indices = $result->metrics['review_queue']['indices'] ?? null;

        return is_array($indices) ? array_flip($indices) : [];
    }

    private function applyFilter(array $rows, string $filter): array
    {
        if ($filter === 'all') {
            return $rows;
        }

        if ($filter === 'review') {
            return array_values(array_filter($rows, fn ($row) => $row['review_rank'] >= 0));
        }

        return array_values(array_filter(
            $rows,
            fn ($row) => ($row['prediction']['sentiment'] ?? null) === $filter
        ));
    }

    private function applySearch(array $rows, string $search): array
    {
        if ($search === '') {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return array_values(array_filter($rows, function ($row) use ($needle) {
            $text = (string) ($row['prediction']['text'] ?? '');

            return str_contains(mb_strtolower($text), $needle);
        }));
    }
}
