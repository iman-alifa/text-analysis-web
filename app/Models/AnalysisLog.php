<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'text_analysis_id',
        'action',
        'description',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(TextAnalysis::class, 'text_analysis_id');
    }

    // Static helper untuk create log
    public static function createLog(string $action, ?int $userId = null, ?int $analysisId = null, ?string $description = null, ?array $metadata = null): void
    {
        self::create([
            'user_id' => $userId ?? auth()->id(),
            'text_analysis_id' => $analysisId,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}