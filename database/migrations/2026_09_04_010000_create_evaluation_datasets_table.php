<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Golden dataset: kumpulan teks berlabel manusia yang dipakai sebagai
 * hold-out test. Sengaja dipisahkan dari `training_items` - bukan sekadar
 * kolom penanda - supaya tidak ada satu pun jalur kode yang bisa keliru
 * memasukkannya ke data latih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_datasets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // test  = dipakai melaporkan angka akhir di naskah
            // dev   = boleh dilihat berulang kali saat menyetel sistem
            $table->string('split', 20)->default('test');

            $table->string('source', 30)->default('import');
            $table->unsignedInteger('item_count')->default(0);
            $table->json('label_distribution')->nullable();

            // Ringkasan reliabilitas anotasi (Cohen's kappa bila ada anotator kedua).
            $table->json('annotation')->nullable();

            // Dibekukan = tidak boleh ditambah/diubah/dihapus lagi. Ini janji
            // yang membuat angka evaluasi antar iterasi bisa dibandingkan.
            $table->timestamp('frozen_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_datasets');
    }
};
