<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TextAnalysis extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'input_type',
        'raw_data',
        'file_path',
        'file_name',
        'total_records',
        'metadata',
        'analysis_type',
        'status',
        'progress',
        'current_step',
        'error_message',
        'started_at',
        'completed_at',
        'last_polled_at',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_polled_at' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(AnalysisResult::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AnalysisLog::class);
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    // Accessors
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'pending' => '<span class="badge bg-warning">Pending</span>',
            'processing' => '<span class="badge bg-info">Processing</span>',
            'completed' => '<span class="badge bg-success">Completed</span>',
            'failed' => '<span class="badge bg-danger">Failed</span>',
            default => '<span class="badge bg-secondary">Unknown</span>',
        };
    }

    public function getDurationAttribute(): ?string
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }

        $seconds = $this->started_at->diffInSeconds($this->completed_at);
        return gmdate('H:i:s', $seconds);
    }

    // Helper untuk update progress
    public function updateProgress(int $progress, string $step): void
    {
        $this->update([
            'progress' => $progress,
            'current_step' => $step,
        ]);
    }

    // Check apakah masih dalam proses
    public function isProcessing(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    // Get status untuk polling
    public function getStatusForPolling(): array
    {
        return [
            'status' => $this->status,
            'progress' => $this->progress,
            'current_step' => $this->current_step,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'error_message' => $this->error_message,
            'duration' => $this->duration,
        ];
    }

    // Tambahkan relasi ke tabel kerja baru
    public function trainingItems()
    {
        return $this->hasMany(TrainingItem::class);
    }

    // Helper untuk menghitung akurasi realtime dari tabel kerja
    public function getRealtimeAccuracyAttribute()
    {
        $verified = $this->trainingItems()->where('is_corrected', true)->get();
        if ($verified->isEmpty()) return 0;
        
        // Hitung berapa yang AI-nya benar (AI == Koreksi)
        $correct = $verified->filter(function($item) {
            return $item->predicted_sentiment === $item->corrected_sentiment;
        })->count();
        
        return round(($correct / $verified->count()) * 100, 1);
    }
}