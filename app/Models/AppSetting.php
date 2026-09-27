<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Saklar aplikasi yang diubah dari antarmuka admin.
 *
 * Nilai disimpan sebagai JSON supaya satu tabel bisa menampung boolean, angka,
 * maupun larik kecil tanpa kolom khusus per jenis.
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'json'];

    public static function ambil(string $kunci, mixed $bawaan = null): mixed
    {
        $baris = static::find($kunci);

        return $baris === null ? $bawaan : $baris->value;
    }

    public static function simpan(string $kunci, mixed $nilai): void
    {
        static::updateOrCreate(['key' => $kunci], ['value' => $nilai]);
    }
}
