<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationRunItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'evaluation_run_id',
        'evaluation_item_id',
        'predicted_sentiment',
        'confidence',
        'predicted_aspects',
        'sentiment_correct',
        'method',
    ];

    protected $casts = [
        'predicted_aspects' => 'array',
        'sentiment_correct' => 'boolean',
        'confidence' => 'float',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class, 'evaluation_run_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EvaluationItem::class, 'evaluation_item_id');
    }
}
