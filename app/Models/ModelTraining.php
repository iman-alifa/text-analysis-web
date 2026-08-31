<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelTraining extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_type',
        'status',
        'total_samples',
        'epochs',
        'learning_rate',
        'result',
        'error_message',
        'triggered_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function scopeRunning($query)
    {
        return $query->whereIn('status', ['pending', 'running']);
    }

    public function getDurationAttribute(): ?string
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }

        return gmdate('H:i:s', $this->started_at->diffInSeconds($this->completed_at));
    }
}
