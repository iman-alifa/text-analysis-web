<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rencana pengambilan sampel: analisis mana, semai berapa, distratifikasi apa,
 * dan apa saja yang disaring.
 *
 * Disimpan bersama datasetnya, bukan dicatat di luar sistem: prosedur
 * pengambilan sampel adalah bagian dari metodologi yang harus dilaporkan di
 * naskah, dan yang tidak tersimpan pasti terlupa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_datasets', function (Blueprint $table) {
            $table->json('sampling_plan')->nullable()->after('annotation');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_datasets', function (Blueprint $table) {
            $table->dropColumn('sampling_plan');
        });
    }
};
