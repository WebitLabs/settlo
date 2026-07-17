<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique(); // cat_equipment, cat_meals, ...
            $table->string('name_de');
            $table->string('name_fr');
            $table->string('name_it');
            $table->string('name_en');
            $table->string('default_deductibility')->default('fully_deductible');
            $table->decimal('default_deductible_pct', 5, 2)->default(100);
            $table->boolean('requires_proof')->default(false);
            $table->boolean('vat_eligible')->default(true);
            $table->string('legal_basis')->nullable(); // e.g. DBG Art. 27
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
