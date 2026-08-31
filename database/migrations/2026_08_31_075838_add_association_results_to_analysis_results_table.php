<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asosiasi aspek-topik (PMI) dari AssociationService sebelumnya dihitung
     * di Python tapi tidak pernah disimpan di sisi Laravel. Kolom ini menampung
     * pmi_top_associations + heatmap_matrix, dan document_aspects menampung
     * aspek per dokumen supaya training_items tidak perlu menebak lewat keyword.
     */
    public function up(): void
    {
        Schema::table('analysis_results', function (Blueprint $table) {
            $table->json('association_results')->nullable()->after('topic_results');
            $table->json('document_aspects')->nullable()->after('association_results');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_results', function (Blueprint $table) {
            $table->dropColumn(['association_results', 'document_aspects']);
        });
    }
};
