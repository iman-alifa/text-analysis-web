<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('text_analyses', function (Blueprint $table) {
            // File configuration columns (add after existing file columns)

            // Excel/CSV Configuration
            $table->boolean('file_has_header')->default(true)->after('file_name');
            $table->string('text_column_name')->nullable()->after('file_has_header');
            $table->integer('text_column_index')->nullable()->after('text_column_name');
            $table->string('csv_delimiter')->nullable()->after('text_column_index');
            $table->integer('excel_sheet_index')->default(0)->after('csv_delimiter');

            // TXT Configuration
            $table->enum('txt_separator', ['newline', 'period', 'double_newline', 'custom'])
                ->nullable()
                ->after('excel_sheet_index');
            $table->string('txt_custom_separator')->nullable()->after('txt_separator');
            $table->string('txt_encoding')->default('utf-8')->after('txt_custom_separator');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('text_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'file_has_header',
                'text_column_name',
                'text_column_index',
                'csv_delimiter',
                'excel_sheet_index',
                'txt_separator',
                'txt_custom_separator',
                'txt_encoding',
            ]);
        });
    }
};
