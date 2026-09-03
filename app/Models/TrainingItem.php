<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrainingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'text_analysis_id',
        'text_content',
        'predicted_sentiment',
        'detected_aspects',     // JSON array dari AI
        'confidence_score',

        // Bagian Koreksi Admin
        'corrected_sentiment',
        'corrected_aspects',    // JSON array koreksi
        'correction_notes',
        'is_corrected',         // Boolean marker
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'detected_aspects' => 'array',
        'corrected_aspects' => 'array',
        'is_corrected' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function textAnalysis()
    {
        return $this->belongsTo(TextAnalysis::class);
    }
}
