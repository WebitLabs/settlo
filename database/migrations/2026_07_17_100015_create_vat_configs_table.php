<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('year');
            $table->decimal('standard_rate', 6, 3); // 8.1%
            $table->decimal('reduced_rate', 6, 3);  // 2.6%
            $table->decimal('special_rate', 6, 3);  // 3.8% (accommodation)
            $table->unsignedInteger('registration_threshold'); // CHF 100000
            $table->unsignedSmallInteger('registration_window_days')->default(30);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_configs');
    }
};
