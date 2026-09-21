<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly amount to set aside is derived from the annualised (projected)
 * full year, while monthly_reserve stays the year-to-date liability divided by
 * twelve. Both are shown, so both are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_estimations', function (Blueprint $table): void {
            $table->decimal('projected_monthly_reserve', 18, 2)->default(0)->after('projected_total_tax');
        });
    }

    public function down(): void
    {
        Schema::table('tax_estimations', function (Blueprint $table): void {
            $table->dropColumn('projected_monthly_reserve');
        });
    }
};
