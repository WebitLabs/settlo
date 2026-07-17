<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federal_tax_brackets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('year');
            $table->char('tariff', 1); // A (single) or B (married / single parent)
            $table->unsignedInteger('bracket_from');
            $table->unsignedInteger('bracket_to')->nullable(); // null = top bracket
            $table->decimal('rate', 6, 3);        // % applied to excess over bracket_from
            $table->decimal('base_amount', 12, 2); // CHF tax at bracket_from
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['year', 'tariff', 'bracket_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federal_tax_brackets');
    }
};
