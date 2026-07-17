<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_entity_id')->constrained('business_entities')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('vat_number')->nullable();

            $table->string('street')->nullable();
            $table->string('street_number')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->default('CH');

            $table->string('default_language', 2)->default('en');
            $table->unsignedSmallInteger('default_payment_term_days')->default(30);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('business_entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
