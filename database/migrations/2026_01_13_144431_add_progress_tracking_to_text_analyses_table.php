<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('text_analyses', function (Blueprint $table) {
            $table->integer('progress')->default(0)->after('status');
            $table->string('current_step')->nullable()->after('progress');
            $table->timestamp('last_polled_at')->nullable()->after('current_step');
        });
    }

    public function down(): void
    {
        Schema::table('text_analyses', function (Blueprint $table) {
            $table->dropColumn(['progress', 'current_step', 'last_polled_at']);
        });
    }
};