<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_dataset_id')->constrained()->cascadeOnDelete();
            $table->text('text');

            // Sidik jari teks ternormalisasi. Dipakai dua hal: menolak duplikat
            // di dalam satu dataset (duplikat menggelembungkan metrik karena
            // teks yang sama dihitung dua kali), dan memeriksa kebocoran
            // terhadap data latih.
            $table->string('text_hash', 64)->index();

            $table->string('gold_sentiment', 20)->nullable();
            $table->json('gold_aspects')->nullable();

            // Label anotator kedua, bila ada. Hanya untuk menghitung kappa;
            // yang dipakai sebagai kebenaran tetap gold_sentiment.
            $table->string('second_sentiment', 20)->nullable();

            // Asal baris (mis. nama lembaga / judul video). Dipakai untuk
            // stratifikasi saat memecah dan untuk rincian metrik per lembaga.
            $table->string('segment')->nullable()->index();
            $table->string('source_ref')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['evaluation_dataset_id', 'text_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_items');
    }
};
