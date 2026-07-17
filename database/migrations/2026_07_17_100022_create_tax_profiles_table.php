<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_entity_id')->unique()->constrained('business_entities')->cascadeOnDelete();
            $table->foreignUuid('canton_id')->nullable()->constrained('cantons')->nullOnDelete();
            $table->foreignUuid('commune_id')->nullable()->constrained('communes')->nullOnDelete();

            $table->string('vat_status')->default('not_registered');
            $table->decimal('estimated_annual_revenue', 18, 2)->nullable();

            // Personal tax details (sole proprietorship)
            $table->string('marital_status')->default('single');
            $table->unsignedTinyInteger('number_of_children')->default(0);
            $table->string('residence_permit')->default('swiss_or_c');
            $table->decimal('pillar3a_amount', 18, 2)->default(0);
            $table->boolean('has_pillar2')->default(false);
            $table->boolean('kirchensteuer')->default(false);
            $table->unsignedSmallInteger('birth_year')->nullable();

            // Other income sources
            $table->decimal('employment_income', 18, 2)->default(0);
            $table->decimal('employment_rate', 5, 2)->nullable(); // %
            $table->boolean('employment_taxed_at_source')->default(false);
            $table->decimal('other_income', 18, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_profiles');
    }
};
