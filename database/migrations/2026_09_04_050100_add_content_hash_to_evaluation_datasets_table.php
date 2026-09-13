<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sidik jari isi golden dataset, dihitung saat pembekuan.
 *
 * Pembekuan sudah dijaga aplikasi, tetapi hash ini membuktikan kepada pembaca
 * naskah bahwa iterasi 0 dan iterasi terakhir benar-benar mengukur baris yang
 * sama - tanpa perlu mempercayai aplikasinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_datasets', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('frozen_at');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_datasets', function (Blueprint $table) {
            $table->dropColumn('content_hash');
        });
    }
};
