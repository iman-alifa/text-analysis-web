<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preprocessing_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('case_folding')->default(true);
            $table->boolean('remove_punctuation')->default(true);
            $table->boolean('remove_numbers')->default(false);
            $table->boolean('remove_stopwords')->default(true);
            $table->boolean('stemming')->default(true);
            $table->boolean('lemmatization')->default(false);
            $table->json('custom_stopwords')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preprocessing_configs');
    }
};