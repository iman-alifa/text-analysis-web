<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `method` per prediksi ('indobert', 'rule-based', 'empty', 'error') tidak
     * pernah disalin dari JSON predictions ke training_items, sehingga halaman
     * koreksi tidak bisa membedakan baris yang benar-benar dinilai model dari
     * baris berisi emotikon/kosong yang ditandai NLP API sebagai `method:
     * 'empty'` - baris itu tidak berarti apa-apa untuk dikoreksi (tidak ada
     * penilaian untuk dibetulkan), tetapi tetap ikut tersodorkan.
     */
    public function up(): void
    {
        Schema::table('training_items', function (Blueprint $table) {
            $table->string('predicted_method')->nullable()->after('confidence_score');
        });
    }

    public function down(): void
    {
        Schema::table('training_items', function (Blueprint $table) {
            $table->dropColumn('predicted_method');
        });
    }
};
