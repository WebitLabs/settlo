<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Extraction-pipeline state, separate from the human review status.
            $table->string('processing_status')->default('manual')->after('status');
            $table->text('processing_error')->nullable()->after('ocr_confidence');

            $table->index(['business_entity_id', 'processing_status']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['business_entity_id', 'processing_status']);
            $table->dropColumn(['processing_status', 'processing_error']);
        });
    }
};
