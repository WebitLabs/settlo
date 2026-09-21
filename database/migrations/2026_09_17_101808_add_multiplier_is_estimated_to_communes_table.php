<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communes imported from the BFS register get the canton's default tax
 * multiplier until real Steuerfüsse are imported; this flag marks those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communes', function (Blueprint $table): void {
            $table->boolean('multiplier_is_estimated')->default(false)->after('tax_multiplier');
        });
    }

    public function down(): void
    {
        Schema::table('communes', function (Blueprint $table): void {
            $table->dropColumn('multiplier_is_estimated');
        });
    }
};
