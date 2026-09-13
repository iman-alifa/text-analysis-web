<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan perubahan label emas setelah dataset dibekukan.
 *
 * Pembekuan mengunci ISI dataset, dan itu tetap dijaga - teksnya tidak berubah,
 * `content_hash` tetap sah. Tetapi labelnya masih bisa diperbaiki secara sadar
 * (mis. menyatukan `prabowo subianto` ke `prabowo` mengikuti kebiasaan anotator
 * yang lebih baru), dan perubahan semacam itu WAJIB berjejak: setiap angka
 * evaluasi yang diukur sebelum perubahan tidak lagi sebanding dengan sesudahnya.
 *
 * Kolom sendiri, bukan menumpang `annotation` - kolom itu ditimpa utuh setiap
 * kali `refreshStats()` menghitung ulang kappa antar-anotator, jadi catatan apa
 * pun yang dititipkan ke sana akan hilang tanpa jejak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_datasets', function (Blueprint $table) {
            $table->json('label_changes')->nullable()->after('annotation');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_datasets', function (Blueprint $table) {
            $table->dropColumn('label_changes');
        });
    }
};
