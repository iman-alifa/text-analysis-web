<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu kali menjalankan golden dataset terhadap model yang sedang aktif.
 * Satu baris = satu titik pada kurva active learning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_dataset_id')->constrained()->cascadeOnDelete();

            // Pelatihan ulang yang mendahului run ini. null = baseline (iterasi 0).
            $table->foreignId('model_training_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('iteration')->default(0);
            $table->string('label')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('item_count')->default(0);

            // Identitas model saat run dijalankan (dari /health) dan konfigurasi
            // preprocessing yang dipakai. Tanpa keduanya, angka evaluasi tidak
            // bisa direproduksi berbulan-bulan kemudian.
            $table->json('model_info')->nullable();
            $table->json('preprocessing_config')->nullable();

            $table->json('metrics')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_runs');
    }
};
