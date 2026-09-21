<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Percentages on a tax estimation could exceed what their columns hold.
 *
 * decimal(6,2) caps at 9999.99. The effective rate is tax over revenue, and a
 * self-employed person owes the minimum AHV contribution (CHF 514) even in a
 * year with almost no revenue — CHF 5 of revenue is an effective rate above
 * 10,000 %. Postgres then rejects the insert outright ("numeric field
 * overflow"), which is how a demo seed on the deployed database failed.
 *
 * SQLite ignores precision entirely, so the test suite never saw it; the
 * Postgres run (phpunit.pgsql.xml) is what this guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_estimations', function (Blueprint $table): void {
            $table->decimal('effective_rate', 12, 2)->default(0)->change();
            $table->decimal('vat_threshold_pct', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tax_estimations', function (Blueprint $table): void {
            $table->decimal('effective_rate', 6, 2)->default(0)->change();
            $table->decimal('vat_threshold_pct', 6, 2)->default(0)->change();
        });
    }
};
