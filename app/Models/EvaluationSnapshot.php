<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_training_id',
        'note',
        'corrected_total',
        'sentiment_accuracy',
        'sentiment_weighted_f1',
        'sentiment_rows',
        'aspect_f1',
        'aspect_exact_match',
        'aspect_rows',
        'metrics',
    ];

    protected $casts = [
        'metrics' => 'array',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(ModelTraining::class, 'model_training_id');
    }
}
