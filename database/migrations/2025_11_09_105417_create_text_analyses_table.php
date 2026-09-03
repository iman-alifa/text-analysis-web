<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('text_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('input_type', ['manual', 'csv', 'txt', 'xlsx'])->default('manual');
            $table->longText('raw_data')->nullable(); // JSON untuk text manual
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->integer('total_records')->default(0);
            $table->enum('analysis_type', ['sentiment', 'aspect', 'topic', 'combined'])->default('sentiment');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_analyses');
    }
};
