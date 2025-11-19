<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('text_analysis_id')->constrained()->onDelete('cascade');
            $table->longText('preprocessed_data')->nullable(); // JSON
            $table->longText('predictions')->nullable(); // JSON hasil prediksi
            $table->json('sentiment_distribution')->nullable();
            $table->json('aspect_results')->nullable();
            $table->json('topic_results')->nullable();
            $table->json('metrics')->nullable(); // accuracy, precision, recall, f1
            $table->text('summary')->nullable(); // Auto-generated summary
            $table->json('visualizations')->nullable(); // Path ke chart images
            $table->timestamps();
            
            $table->index('text_analysis_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_results');
    }
};