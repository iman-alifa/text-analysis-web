<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode tunda pelatihan ulang (fase evaluasi kuesioner).
 *
 * - `app_settings`: saklar yang diubah admin dari antarmuka, bukan dari .env,
 *   supaya berganti fase tidak menuntut akses SSH ke server.
 * - `retrain_deferrals`: satu baris untuk setiap kali pelatihan otomatis
 *   SEHARUSNYA terpicu selama mode tunda. `cutoff_at` menentukan kolam koreksi
 *   yang dipakai saat pemicu itu diputar ulang, sehingga kurva iterasinya
 *   meniru apa yang akan terjadi bila pelatihan berjalan langsung.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->string('key', 100)->primary();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('retrain_deferrals', function (Blueprint $table) {
            $table->id();
            // Saat pemicu terjadi; koreksi dengan verified_at <= nilai ini
            // membentuk kolam putaran yang diputar ulang.
            $table->timestamp('cutoff_at');
            $table->unsignedInteger('new_corrections')->default(0);
            $table->unsignedInteger('pool_size')->default(0);
            $table->foreignId('text_analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model_type', 20)->default('both');
            // tertunda | dijalankan | dilewati | dibatalkan
            $table->string('status', 20)->default('tertunda');
            $table->json('training_ids')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('replayed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'cutoff_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrain_deferrals');
        Schema::dropIfExists('app_settings');
    }
};
