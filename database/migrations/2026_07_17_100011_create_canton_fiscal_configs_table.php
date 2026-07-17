<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canton_fiscal_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('canton_id')->constrained('cantons')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('cantonal_rate', 8, 4);          // Einfache Steuer rate %
            $table->decimal('communal_multiplier_default', 8, 4); // fallback Steuerfuss %
            $table->decimal('church_rate', 8, 4)->default(0); // % of cantonal simple tax
            $table->unsignedInteger('child_deduction')->default(0); // CHF per child
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['canton_id', 'year']);
            $table->index(['year', 'effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canton_fiscal_configs');
    }
};
