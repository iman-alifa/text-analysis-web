<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analisis mana yang menjadi sumber sebuah pelatihan ulang.
 *
 * Studi kasus memisahkan korpus per lembaga, jadi pelatihannya juga dijalankan
 * per analisis. Tanpa kolom ini riwayat pelatihan tidak bisa menjawab "putaran
 * ini melatih koreksi lembaga mana" - pertanyaan yang justru harus dijawab
 * naskah saat melaporkan kurva active learning.
 *
 * Nullable, karena pelatihan gabungan lintas analisis tetap sah (dan itulah
 * yang dilakukan seluruh riwayat sebelum ini).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_trainings', function (Blueprint $table) {
            $table->foreignId('text_analysis_id')->nullable()->after('model_type')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('model_trainings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('text_analysis_id');
        });
    }
};
