<?php

namespace App\Models;

use App\Support\TextFingerprint;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'evaluation_dataset_id',
        'text',
        'text_hash',
        'gold_sentiment',
        'gold_aspects',
        'gold_aspects_normalized',
        'second_sentiment',
        'segment',
        'source_ref',
        'note',
    ];

    protected $casts = [
        'gold_aspects' => 'array',
        'gold_aspects_normalized' => 'array',
    ];

    protected static function booted(): void
    {
        // Hash selalu diturunkan dari teksnya, tidak pernah diisi pemanggil.
        // Kalau boleh diisi manual, cepat atau lambat ada jalur yang mengisinya
        // dengan normalisasi lain dan pemeriksaan kebocoran diam-diam berhenti bekerja.
        static::saving(function (EvaluationItem $item) {
            $item->text_hash = TextFingerprint::hash((string) $item->text);
        });
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'evaluation_dataset_id');
    }
}
