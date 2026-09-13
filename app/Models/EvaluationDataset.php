<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationDataset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'split',
        'source',
        'item_count',
        'label_distribution',
        'annotation',
        'label_changes',
        'sampling_plan',
        'frozen_at',
        'content_hash',
        'created_by',
    ];

    protected $casts = [
        'label_distribution' => 'array',
        'annotation' => 'array',
        'label_changes' => 'array',
        'sampling_plan' => 'array',
        'frozen_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(EvaluationItem::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(EvaluationRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Baris yang belum diberi label emas.
     *
     * Dataset hasil pengambilan sampel lahir tanpa label - diisi setelah
     * dianotasi manusia. Selama masih ada yang kosong, dataset belum boleh
     * dibekukan.
     */
    public function unlabeledCount(): int
    {
        return $this->items()->whereNull('gold_sentiment')->count();
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    /**
     * Hanya dataset beku yang boleh dikutip di naskah: kalau isinya masih
     * bisa berubah, dua run pada iterasi berbeda tidak mengukur hal yang sama.
     */
    public function bolehDikutip(): bool
    {
        return $this->isFrozen() && $this->item_count > 0;
    }
}
