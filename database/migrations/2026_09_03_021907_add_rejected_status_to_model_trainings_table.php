<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NLP API menolak menyimpan checkpoint yang menurunkan metrik validasi
     * (`rejected_for_regression`). Tanpa status tersendiri, training seperti itu
     * tercatat "completed" dan admin mengira model membaik padahal bobotnya
     * dikembalikan ke versi sebelumnya.
     *
     * enum diubah menjadi string biasa supaya menambah status tidak lagi
     * memerlukan migrasi skema, dan agar perilakunya sama di MySQL maupun
     * SQLite (enum SQLite dibuat sebagai check constraint yang akan menolak
     * nilai baru). Nilai yang sah ditegakkan di sisi aplikasi.
     */
    public function up(): void
    {
        if (! Schema::hasTable('model_trainings')) {
            return;
        }

        Schema::table('model_trainings', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('model_trainings')) {
            return;
        }

        Schema::table('model_trainings', function (Blueprint $table) {
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])
                ->default('pending')
                ->change();
        });
    }
};
