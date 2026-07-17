<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_entity_id')->constrained('business_entities')->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('iban');
            $table->string('account_name')->nullable();
            $table->string('currency_code', 3)->default('CHF');
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->index('business_entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
