<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bentuk dasar label aspek emas.
 *
 * Bahasa Indonesia aglutinatif: mode automatic mengembalikan bentuk permukaan
 * seperti yang tertulis di teks (`pelayanannya`), sementara anotator menulis
 * bentuk dasar (`pelayanan`). Membandingkan keduanya sebagai string persis
 * menghitung pasangan yang BENAR sebagai false positive sekaligus false
 * negative - terukur micro-F1 0,118 padahal seharusnya 0,941.
 *
 * Disimpan, bukan dihitung saat evaluasi: normalisasinya memakai stemmer
 * Sastrawi yang hidup di Python, dan datasetnya beku sehingga cukup sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_items', function (Blueprint $table) {
            $table->json('gold_aspects_normalized')->nullable()->after('gold_aspects');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_items', function (Blueprint $table) {
            $table->dropColumn('gold_aspects_normalized');
        });
    }
};
