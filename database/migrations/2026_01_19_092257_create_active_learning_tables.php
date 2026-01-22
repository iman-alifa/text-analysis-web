<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_create_active_learning_tables.php
    public function up()
    {
        // 1. Tabel untuk menyimpan pecahan baris dari JSON AnalysisResult
        Schema::create('training_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('text_analysis_id')->constrained('text_analyses')->onDelete('cascade');
            
            $table->text('text_content'); // Teks kalimat
            
            // Hasil dari JSON asli (disimpan terpisah kolomnya biar mudah difilter)
            $table->string('predicted_sentiment')->nullable();
            $table->json('detected_aspects')->nullable();
            $table->float('confidence_score')->default(0);
            
            // Hasil Koreksi Admin
            $table->string('corrected_sentiment')->nullable();
            $table->json('corrected_aspects')->nullable();
            $table->text('correction_notes')->nullable();
            
            // Status Verifikasi
            $table->boolean('is_corrected')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            
            $table->timestamps();
        });

        // 2. Tabel untuk Stopwords (Topic Identification Refinement)
        Schema::create('custom_stopwords', function (Blueprint $table) {
            $table->id();
            $table->string('word')->unique();
            $table->foreignId('added_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop tabel yang memiliki foreign key terlebih dahulu
        Schema::dropIfExists('custom_stopwords');
        Schema::dropIfExists('training_items');
    }
};
