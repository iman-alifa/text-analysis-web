<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pemicu pelatihan otomatis yang ditunda selama fase evaluasi kuesioner.
 *
 * Lihat RetrainDeferralService.
 */
class RetrainDeferral extends Model
{
    protected $fillable = [
        'cutoff_at',
        'new_corrections',
        'pool_size',
        'text_analysis_id',
        'model_type',
        'status',
        'training_ids',
        'message',
        'replayed_at',
    ];

    protected $casts = [
        'cutoff_at' => 'datetime',
        'replayed_at' => 'datetime',
        'training_ids' => 'array',
    ];

    public function textAnalysis(): BelongsTo
    {
        return $this->belongsTo(TextAnalysis::class);
    }

    public function scopeTertunda($query)
    {
        return $query->where('status', 'tertunda');
    }
}
