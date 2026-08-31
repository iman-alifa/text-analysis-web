<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat retraining model. Tanpa tabel ini, hasil dari /api/retrain/*
     * tidak terekam di mana pun sehingga perbaikan model dari active learning
     * tidak bisa ditelusuri antar iterasi (fase Monitoring CRISP-ML(Q)).
     */
    public function up(): void
    {
        Schema::create('model_trainings', function (Blueprint $table) {
            $table->id();
            $table->enum('model_type', ['sentiment', 'aspect']);
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->unsignedInteger('total_samples')->default(0);
            $table->unsignedTinyInteger('epochs')->default(3);
            $table->float('learning_rate')->default(0.00002);
            $table->json('result')->nullable();      // respons mentah dari NLP API
            $table->text('error_message')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_trainings');
    }
};
