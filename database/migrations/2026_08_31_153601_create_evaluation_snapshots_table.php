<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rekam metrik evaluasi global setiap kali retraining dipicu.
     *
     * Tanpa ini, evaluasi hanya bisa dilihat untuk kondisi saat ini, sehingga
     * tidak ada bukti perbaikan model antar-iterasi active learning
     * (fase Monitoring & Quality pada CRISP-ML(Q)).
     */
    public function up(): void
    {
        Schema::create('evaluation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_training_id')->nullable()->constrained('model_trainings')->nullOnDelete();
            $table->string('note')->nullable();

            $table->unsignedInteger('corrected_total')->default(0);

            // Sentimen
            $table->float('sentiment_accuracy')->nullable();
            $table->float('sentiment_weighted_f1')->nullable();
            $table->unsignedInteger('sentiment_rows')->default(0);

            // Aspek
            $table->float('aspect_f1')->nullable();
            $table->float('aspect_exact_match')->nullable();
            $table->unsignedInteger('aspect_rows')->default(0);

            $table->json('metrics')->nullable(); // hasil evaluasi lengkap
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_snapshots');
    }
};
