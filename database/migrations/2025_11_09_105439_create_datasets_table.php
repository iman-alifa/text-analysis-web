<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_type'); // csv, txt, xlsx
            $table->bigInteger('file_size'); // in bytes
            $table->integer('total_rows')->default(0);
            $table->json('columns')->nullable(); // Column names untuk CSV/XLSX
            $table->boolean('is_processed')->default(false);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['user_id', 'is_processed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};