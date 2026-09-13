<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prediksi per baris untuk satu run.
 *
 * Disimpan, bukan dibuang setelah metrik dihitung: uji McNemar antar iterasi
 * menuntut prediksi berpasangan pada baris yang sama, dan analisis kesalahan
 * di naskah menuntut contoh kalimat yang benar-benar salah dinilai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluation_item_id')->constrained()->cascadeOnDelete();
            $table->string('predicted_sentiment', 20)->nullable();
            $table->decimal('confidence', 8, 6)->nullable();
            $table->json('predicted_aspects')->nullable();
            $table->boolean('sentiment_correct')->nullable();
            $table->string('method', 30)->nullable();

            $table->index(['evaluation_run_id', 'sentiment_correct']);
            $table->index(['evaluation_run_id', 'evaluation_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_run_items');
    }
};
