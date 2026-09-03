<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomStopword extends Model
{
    use HasFactory;

    /**
     * Atribut yang bisa diisi secara massal (mass assignable).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'word',
        'added_by',
    ];

    /**
     * Relasi ke model User (Admin yang menambahkan stopword ini).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
