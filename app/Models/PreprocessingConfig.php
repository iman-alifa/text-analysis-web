<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PreprocessingConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'case_folding',
        'remove_punctuation',
        'remove_numbers',
        'remove_stopwords',
        'stemming',
        'lemmatization',
        'custom_stopwords',
        'is_default',
    ];

    protected $casts = [
        'case_folding' => 'boolean',
        'remove_punctuation' => 'boolean',
        'remove_numbers' => 'boolean',
        'remove_stopwords' => 'boolean',
        'stemming' => 'boolean',
        'lemmatization' => 'boolean',
        'custom_stopwords' => 'array',
        'is_default' => 'boolean',
    ];

    public function scopeDefault($query)
    {
        return $query->where('is_default', true)->first();
    }

    public function toApiFormat(): array
    {
        return [
            'case_folding' => $this->case_folding,
            'remove_punctuation' => $this->remove_punctuation,
            'remove_numbers' => $this->remove_numbers,
            'remove_stopwords' => $this->remove_stopwords,
            'stemming' => $this->stemming,
            'lemmatization' => $this->lemmatization,
            'custom_stopwords' => $this->custom_stopwords ?? [],
        ];
    }
}
