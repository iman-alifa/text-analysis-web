<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Narasi AI disimpan pada kolomnya sendiri, bukan diselipkan ke dalam
     * topic_results seperti interpretasi topik yang lama. Menumpang di kolom
     * hasil model membuat narasi ikut hilang setiap kali analisis diproses
     * ulang, dan mencampur keluaran model dengan keluaran LLM di satu tempat.
     */
    public function up(): void
    {
        Schema::table('analysis_results', function (Blueprint $table) {
            $table->json('ai_interpretations')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_results', function (Blueprint $table) {
            $table->dropColumn('ai_interpretations');
        });
    }
};
