<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('type')->default('sole_proprietorship');

            // Swiss identifiers
            $table->string('uid')->nullable();          // CHE-xxx.xxx.xxx
            $table->string('mwst_number')->nullable();   // MWST/TVA/IVA number

            // Address
            $table->string('street')->nullable();
            $table->string('street_number')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->foreignUuid('canton_id')->nullable()->constrained('cantons')->nullOnDelete();

            // Banking / invoicing defaults
            $table->string('iban')->nullable();
            $table->string('default_currency', 3)->default('CHF');
            $table->unsignedSmallInteger('default_payment_term_days')->default(30);
            $table->string('default_language', 2)->default('en');
            $table->string('invoice_number_prefix')->default('INV-');
            $table->text('default_invoice_notes')->nullable();
            $table->string('logo_url')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
            $table->index('canton_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_entities');
    }
};
