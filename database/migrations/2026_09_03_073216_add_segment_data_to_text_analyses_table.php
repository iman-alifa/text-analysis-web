<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nilai kolom pemisah per baris, sejajar indeks dengan `raw_data`.
     *
     * Bentuknya {"columns": ["tanggal", "video"], "rows": [{"tanggal": "...",
     * "video": "..."}, ...]}. Disimpan di `text_analyses`, bukan di
     * `analysis_results`, karena ini bagian dari *masukan* - nilainya berasal
     * dari berkas yang diunggah dan tidak berubah walau analisis diproses ulang.
     */
    public function up(): void
    {
        Schema::table('text_analyses', function (Blueprint $table) {
            $table->json('segment_data')->nullable()->after('raw_data');
        });
    }

    public function down(): void
    {
        Schema::table('text_analyses', function (Blueprint $table) {
            $table->dropColumn('segment_data');
        });
    }
};
