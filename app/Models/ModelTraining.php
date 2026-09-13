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
        'text_analysis_id',
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

    public function textAnalysis(): BelongsTo
    {
        return $this->belongsTo(TextAnalysis::class);
    }

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
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return gmdate('H:i:s', $this->started_at->diffInSeconds($this->completed_at));
    }

    /**
     * Putaran yang dibatalkan setelah selesai - angkanya tidak boleh dikutip.
     *
     * Ditandai di `result.batal` oleh sisi layanan, bukan dihapus: barisnya
     * justru jejak audit yang perlu disimpan. Yang dibuang dari tampilan adalah
     * ANGKANYA, bukan keberadaannya - training #4 dibatalkan bukan karena
     * hasilnya jelek (aspek 27,14 -> 31,57, naik) melainkan karena titik
     * berangkatnya checkpoint yang tercemar dan angkanya tidak bisa dihitung
     * ulang oleh siapa pun.
     */
    public function getBatalAttribute(): bool
    {
        return ($this->result['batal'] ?? false) === true;
    }

    public function getAlasanBatalAttribute(): ?string
    {
        $alasan = $this->result['alasan_batal']
            ?? $this->result['batal_alasan']
            ?? $this->result['alasan']
            ?? null;

        return is_string($alasan) && $alasan !== '' ? $alasan : null;
    }

    /**
     * Sidik jari isi kolam yang dilatihkan pada putaran ini.
     *
     * Dua putaran berturut-turut dengan sidik jari yang SAMA berarti kolamnya
     * tidak bertambah - keliru menurut protokol kolam. Berbeda pada putaran
     * yang seharusnya mengulang hal yang sama berarti kolamnya berubah
     * diam-diam. Keduanya tidak terlihat dari berkas bobot, ukurannya, maupun
     * `/health`.
     */
    public function getSidikJariKolamAttribute(): ?string
    {
        $nilai = $this->result['sidik_jari_kolam'] ?? null;

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }

    /**
     * Apakah putaran ini benar-benar berangkat dari bobot dasar.
     *
     * Sengaja membaca `trained_from_baseline`, bukan `baseline_swapped`: yang
     * kedua hanya menyala ketika bobot DITUKAR, sehingga putaran yang memang
     * berangkat dari bobot dasar karena checkpoint-nya belum ada tercatat
     * `false` - persis kebalikan dari yang terjadi. Training #5 kena itu.
     */
    public function getDariBobotDasarAttribute(): ?bool
    {
        $nilai = $this->result['trained_from_baseline'] ?? null;

        return is_bool($nilai) ? $nilai : null;
    }

    /**
     * Ukuran set validasi yang dipakai untuk memutuskan terima/tolak checkpoint.
     *
     * Angka penentu sahnya satu putaran, dan sebelumnya hanya terlihat dengan
     * membuka basis data - yang persis bagaimana putaran pertama ketahuan
     * rusak, setelah bobotnya terlanjur terpasang.
     */
    public function getValSizeAttribute(): ?int
    {
        $nilai = $this->result['val_size'] ?? null;

        return is_numeric($nilai) ? (int) $nilai : null;
    }

    /**
     * Selisih F1 sebelum/sesudah, dengan nama kunci yang berbeda per modul:
     * `weighted_f1_delta` untuk sentimen, `f1_delta` untuk aspek.
     *
     * Nol berarti pengukuran tidak membedakan apa pun - pada set validasi kecil
     * itu keadaan yang wajar, dan checkpoint tetap tersimpan karena "tidak
     * memburuk" secara teknis benar. Karena itu angkanya harus tampil.
     */
    public function getF1DeltaAttribute(): ?float
    {
        $nilai = $this->result['weighted_f1_delta'] ?? $this->result['f1_delta'] ?? null;

        return is_numeric($nilai) ? (float) $nilai : null;
    }

    /**
     * Keterangan kalibrasi suhu, atau null bila putaran ini tidak memuatnya.
     *
     * Suhu yang diwarisi berarti keyakinan pada iterasi itu **belum diukur
     * ulang** - angkanya berasal dari bobot sebelumnya. Sebelum layanan
     * mewariskannya, suhu direset ke 1,0 setiap kali set validasi kecil, dan
     * itu menaikkan rerata keyakinan (0,8929 -> 0,9497) sehingga antrean
     * tinjauan menyusut (109 -> 58) tanpa model benar-benar membaik.
     *
     * Tiga keadaan, bukan dua. Putaran yang dijalankan SEBELUM layanan
     * mewariskan suhu tidak membawa kunci `temperature_inherited` sama sekali,
     * dan pada putaran itulah suhu justru direset ke 1,0 diam-diam. Melabelinya
     * "diukur ulang" akan menyatakan kebalikan dari yang sebenarnya terjadi,
     * jadi keadaan ketiga dinyatakan apa adanya: tidak diketahui.
     *
     * @return array{temperature: float, inherited: bool, unknown: bool, label: string}|null
     */
    public function getCalibrationAttribute(): ?array
    {
        $suhu = $this->result['temperature'] ?? null;

        if (! is_numeric($suhu)) {
            return null;
        }

        $tercatat = array_key_exists('temperature_inherited', $this->result ?? []);
        $diwarisi = ($this->result['temperature_inherited'] ?? false) === true;

        if (! $tercatat) {
            return [
                'temperature' => (float) $suhu,
                'inherited' => false,
                'unknown' => true,
                'label' => 'asal kalibrasi tidak tercatat (putaran sebelum layanan mewariskan suhu)',
            ];
        }

        return [
            'temperature' => (float) $suhu,
            'inherited' => $diwarisi,
            'unknown' => false,
            'label' => $diwarisi
                ? sprintf(
                    'kalibrasi diwarisi (validasi %s terlalu kecil untuk mengukur ulang)',
                    $this->val_size ?? '?'
                )
                : 'kalibrasi diukur ulang pada set validasi putaran ini',
        ];
    }
}
