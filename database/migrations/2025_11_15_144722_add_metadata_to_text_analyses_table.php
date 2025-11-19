<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('text_analyses', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('total_records');
        });
    }

    public function down(): void
    {
        Schema::table('text_analyses', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};