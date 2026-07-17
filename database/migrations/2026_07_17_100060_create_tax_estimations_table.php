<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_estimations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_entity_id')->constrained('business_entities')->cascadeOnDelete();
            $table->foreignUuid('canton_id')->nullable()->constrained('cantons')->nullOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->timestamp('calculated_at');

            // Income base
            $table->decimal('gross_revenue', 18, 2)->default(0);
            $table->decimal('total_expenses', 18, 2)->default(0);
            $table->decimal('net_income', 18, 2)->default(0);

            // Social insurance
            $table->decimal('ahv_contribution', 18, 2)->default(0);
            $table->decimal('iv_contribution', 18, 2)->default(0);
            $table->decimal('eo_contribution', 18, 2)->default(0);
            $table->decimal('total_social_insurance', 18, 2)->default(0);
            $table->decimal('ahv_deduction', 18, 2)->default(0);

            $table->decimal('taxable_income', 18, 2)->default(0);

            // Income tax
            $table->decimal('federal_tax', 18, 2)->default(0);
            $table->decimal('cantonal_tax', 18, 2)->default(0);
            $table->decimal('communal_tax', 18, 2)->default(0);
            $table->decimal('church_tax', 18, 2)->default(0);
            $table->decimal('total_income_tax', 18, 2)->default(0);

            // Totals + reserve
            $table->decimal('total_tax_burden', 18, 2)->default(0);
            $table->decimal('monthly_reserve', 18, 2)->default(0);
            $table->decimal('effective_rate', 6, 2)->default(0);

            // Annualised projection
            $table->decimal('projected_annual_revenue', 18, 2)->default(0);
            $table->decimal('projected_total_tax', 18, 2)->default(0);

            // VAT threshold
            $table->decimal('vat_threshold_pct', 6, 2)->default(0);
            $table->string('vat_alert_level')->default('none');
            $table->date('vat_crossing_date')->nullable();

            // Quellensteuer stop flag
            $table->boolean('quellensteuer_regime')->default(false);

            // Audit trail
            $table->json('inputs');
            $table->json('rates_snapshot');

            $table->timestamps();

            $table->index(['business_entity_id', 'fiscal_year']);
            $table->index('calculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_estimations');
    }
};
