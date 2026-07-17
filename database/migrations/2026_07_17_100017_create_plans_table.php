<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique(); // solo, pro, confidence
            $table->string('name');
            $table->decimal('price_monthly', 18, 2);
            $table->string('currency_code', 3)->default('CHF');
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->unsignedSmallInteger('human_answers_quota')->default(0); // 0/1/3
            $table->json('features')->nullable();      // list<PlanFeature value> granted
            $table->json('marketing_features')->nullable(); // display-only bullet list
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
