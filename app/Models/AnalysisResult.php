<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'text_analysis_id',
        'preprocessed_data',
        'predictions',
        'sentiment_distribution',
        'aspect_results',
        'topic_results',
        'association_results',
        'document_aspects',
        'metrics',
        'summary',
        'ai_interpretations',
        'visualizations',
        'corrected_sentiment',
        'corrected_aspects',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'preprocessed_data' => 'array',
        'predictions' => 'array',
        'sentiment_distribution' => 'array',
        'aspect_results' => 'array',
        'topic_results' => 'array',
        'association_results' => 'array',
        'document_aspects' => 'array',
        'metrics' => 'array',
        'visualizations' => 'array',
        'ai_interpretations' => 'array',
        'result' => 'array',
        'corrected_aspects' => 'array',
    ];

    /**
     * Sebagian baris lama (dan data seeder) tersimpan double-encoded: kolom
     * ber-cast 'array' diisi string hasil json_encode manual, sehingga cast
     * mengembalikan string. Normalisasi di sini supaya view, chart, dan
     * TrainingItemService tidak perlu menangani dua bentuk data.
     */
    protected function castAttribute($key, $value)
    {
        $casted = parent::castAttribute($key, $value);

        if (is_string($casted) && ($this->getCasts()[$key] ?? null) === 'array') {
            $decoded = json_decode($casted, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $casted;
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(TextAnalysis::class, 'text_analysis_id');
    }

    /**
     * Samakan bentuk aspect_results dari berbagai versi kode.
     *
     * Yang ditemui di database: bentuk sekarang {aspect, count, sentiments},
     * bentuk lama {aspect, positive, neutral, negative} (count, bukan persen),
     * dan bentuk lama tanpa nama aspek sama sekali (tidak bisa ditampilkan,
     * jadi dilewati daripada memunculkan kartu kosong).
     *
     * @return array<int, array{aspect: string, count: int, sentiments: array}>
     */
    public function normalizedAspectResults(): array
    {
        $rows = $this->aspect_results;

        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $row['aspect'] ?? (is_string($key) ? $key : null);

            if (! $name) {
                continue;
            }

            $sentiments = $row['sentiments'] ?? null;

            if (! is_array($sentiments)) {
                // Bentuk lama menyimpan jumlah dokumen per label, bukan persentase
                $counts = [
                    'positive' => (int) ($row['positive'] ?? 0),
                    'neutral' => (int) ($row['neutral'] ?? 0),
                    'negative' => (int) ($row['negative'] ?? 0),
                ];
                $total = array_sum($counts);

                if ($total === 0) {
                    continue;
                }

                $sentiments = [
                    'positive' => round(($counts['positive'] / $total) * 100, 1),
                    'neutral' => round(($counts['neutral'] / $total) * 100, 1),
                    'negative' => round(($counts['negative'] / $total) * 100, 1),
                ];
            }

            $normalized[] = [
                'aspect' => (string) $name,
                'count' => (int) ($row['count'] ?? $row['total'] ?? 0),
                'sentiments' => [
                    'positive' => $sentiments['positive'] ?? 0,
                    'neutral' => $sentiments['neutral'] ?? 0,
                    'negative' => $sentiments['negative'] ?? 0,
                ],
            ];
        }

        return $normalized;
    }

    // Helper methods
    public function getSentimentSummary(): array
    {
        if (! $this->sentiment_distribution) {
            return [];
        }

        return [
            'positive' => $this->sentiment_distribution['positive'] ?? 0,
            'negative' => $this->sentiment_distribution['negative'] ?? 0,
            'neutral' => $this->sentiment_distribution['neutral'] ?? 0,
        ];
    }

    public function getTopTopics(int $limit = 5): array
    {
        if (! $this->topic_results || ! isset($this->topic_results['topics'])) {
            return [];
        }

        return array_slice($this->topic_results['topics'], 0, $limit);
    }

    public function getIsAccurateAttribute()
    {
        if (is_null($this->verified_at)) {
            return null;
        }

        $aiSent = $this->result['sentiment']['label'] ?? null;
        $admSent = $this->corrected_sentiment;

        $aiAsp = $this->result['aspects'] ?? [];
        $admAsp = $this->corrected_aspects ?? [];
        sort($aiAsp);
        sort($admAsp);

        return ($aiSent === $admSent) && ($aiAsp == $admAsp);
    }
}
