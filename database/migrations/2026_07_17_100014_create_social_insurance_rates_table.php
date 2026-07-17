<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_insurance_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('year');
            $table->decimal('ahv_rate', 6, 3);   // 10.6%
            $table->decimal('iv_rate', 6, 3);    // 1.4%
            $table->decimal('eo_rate', 6, 3);    // 0.5%
            $table->unsignedInteger('pillar3a_max_se');      // 35280 (no pillar 2)
            $table->unsignedInteger('pillar3a_max_with_p2'); // 7056 (with pillar 2)
            $table->unsignedInteger('ahv_minimum')->default(514); // CHF/year minimum
            $table->unsignedInteger('age_exemption_amount')->default(16800); // 65+ exemption
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_insurance_rates');
    }
};
