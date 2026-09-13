<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'evaluation_dataset_id',
        'model_training_id',
        'iteration',
        'label',
        'status',
        'item_count',
        'model_info',
        'preprocessing_config',
        'metrics',
        'error_message',
        'triggered_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'model_info' => 'array',
        'preprocessing_config' => 'array',
        'metrics' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'evaluation_dataset_id');
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(ModelTraining::class, 'model_training_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EvaluationRunItem::class);
    }

    /**
     * Pengukuran yang selesai DAN masih layak dipakai.
     *
     * Run yang dibatalkan ikut disingkirkan di sini, bukan di tiap pemanggil.
     * Scope ini adalah satu-satunya pintu yang dipakai kurva iterasi,
     * perbandingan McNemar, laporan markdown, dan penomoran iterasi - menyaring
     * di pemanggil berarti satu jalur yang lupa sudah cukup untuk memasukkan
     * kembali angka yang tidak boleh dikutip ke dalam naskah.
     *
     * Konsekuensi yang memang diinginkan: run iterasi 2 yang dibatalkan tidak
     * lagi menaikkan nomor iterasi, jadi pengukuran berikutnya kembali menjadi
     * iterasi 2 - dan memang itu yang harus diulang.
     *
     * Barisnya tetap ada di basis data. Yang dibuang dari perhitungan adalah
     * angkanya, bukan jejaknya.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed')->sah();
    }

    /**
     * Belum dibatalkan.
     *
     * `model_info->batal` ditulis sisi layanan saat sebuah putaran dinyatakan
     * tidak dapat direproduksi. Dibaca sebagai JSON, bukan disalin ke kolom
     * sendiri: penanda ini kadang ditulis langsung ke basis data dari sisi
     * layanan, dan kolom bayangan akan diam-diam basi.
     */
    public function scopeSah($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('model_info->batal')
                ->orWhere('model_info->batal', false);
        });
    }

    public function getBatalAttribute(): bool
    {
        return ($this->model_info['batal'] ?? false) === true;
    }

    public function getAlasanBatalAttribute(): ?string
    {
        $alasan = $this->model_info['alasan_batal']
            ?? $this->model_info['alasan']
            ?? null;

        return is_string($alasan) && $alasan !== '' ? $alasan : null;
    }

    public function judul(): string
    {
        return $this->label ?: 'Iterasi '.$this->iteration;
    }
}
