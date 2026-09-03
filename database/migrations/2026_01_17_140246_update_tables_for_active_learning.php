<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // 1. Tambah Role ke Users
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('email'); // admin/user
        });

        // 2. Tambah Kolom Koreksi ke Analysis Results
        Schema::table('analysis_results', function (Blueprint $table) {
            $table->string('corrected_sentiment')->nullable(); // positive/neutral/negative
            $table->json('corrected_aspects')->nullable(); // ["harga", "rasa"]
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
        });

        // 3. Buat Tabel Custom Stopwords (Untuk Service Topik)
        // Tabel yang sama juga dibuat migrasi create_active_learning_tables,
        // jadi harus dijaga agar `migrate` pada database baru tidak gagal.
        if (! Schema::hasTable('custom_stopwords')) {
            Schema::create('custom_stopwords', function (Blueprint $table) {
                $table->id();
                $table->string('word')->unique();
                $table->foreignId('added_by')->constrained('users');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        // Hapus kolom/tabel jika rollback
        Schema::dropIfExists('custom_stopwords');
        Schema::table('analysis_results', function (Blueprint $table) {
            $table->dropColumn(['corrected_sentiment', 'corrected_aspects', 'verified_at', 'verified_by']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
