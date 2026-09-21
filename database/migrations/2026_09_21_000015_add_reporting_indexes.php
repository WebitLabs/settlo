<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the hot reporting paths: invoices are filtered by issue date for
 * every year-to-date figure (dashboards, VAT return, year-end export),
 * escalations are always looked up through their conversation, and the last
 * workspace of a user is resolved on every login redirect. Foreign keys are
 * not indexed automatically on PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['business_entity_id', 'issue_date']);
        });

        Schema::table('ai_escalations', function (Blueprint $table) {
            $table->index('conversation_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('last_business_entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['business_entity_id', 'issue_date']);
        });

        Schema::table('ai_escalations', function (Blueprint $table) {
            $table->dropIndex(['conversation_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_business_entity_id']);
        });
    }
};
