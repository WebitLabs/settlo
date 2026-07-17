<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The last VAT alert level surfaced to the owner for this entity. Persisted
     * at the entity level (not the user) so it stays correct for owners with
     * multiple businesses. Compared against each fresh estimation to decide
     * whether a proactive registration notification must fire.
     */
    public function up(): void
    {
        Schema::table('business_entities', function (Blueprint $table) {
            $table->string('vat_alert_level')->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('business_entities', function (Blueprint $table) {
            $table->dropColumn('vat_alert_level');
        });
    }
};
