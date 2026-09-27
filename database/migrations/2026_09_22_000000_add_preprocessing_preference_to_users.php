<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferensi preprocessing bawaan per pengguna.
 *
 * Menu "Pengaturan" sebelumnya hanya berisi profil dan kata sandi bawaan
 * Breeze, jadi namanya menjanjikan sesuatu yang tidak ada. Ini pengaturan
 * pertama yang benar-benar mengubah perilaku aplikasi: konfigurasi yang
 * terpilih lebih dulu setiap kali membuat analisis baru.
 *
 * nullOnDelete, bukan cascade: menghapus sebuah konfigurasi preprocessing tidak
 * boleh ikut menghapus akun penggunanya. Yang benar adalah preferensinya kembali
 * kosong, lalu resolver jatuh ke konfigurasi `is_default` seperti biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('preprocessing_config_id')
                ->nullable()
                ->after('role')
                ->constrained('preprocessing_configs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preprocessing_config_id');
        });
    }
};
