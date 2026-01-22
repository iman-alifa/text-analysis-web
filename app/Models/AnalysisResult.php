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
        'metrics',
        'summary',
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
        'metrics' => 'array',
        'visualizations' => 'array',
        'result' => 'array', 
        'corrected_aspects' => 'array'
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(TextAnalysis::class, 'text_analysis_id');
    }

    // Helper methods
    public function getSentimentSummary(): array
    {
        if (!$this->sentiment_distribution) {
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
        if (!$this->topic_results || !isset($this->topic_results['topics'])) {
            return [];
        }

        return array_slice($this->topic_results['topics'], 0, $limit);
    }

    public function getIsAccurateAttribute() {
        if (is_null($this->verified_at)) return null;

        $aiSent = $this->result['sentiment']['label'] ?? null;
        $admSent = $this->corrected_sentiment;

        $aiAsp = $this->result['aspects'] ?? [];
        $admAsp = $this->corrected_aspects ?? [];
        sort($aiAsp); sort($admAsp);

        return ($aiSent === $admSent) && ($aiAsp == $admAsp);
    }
}