<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_entity_id')->constrained('business_entities')->cascadeOnDelete();
            $table->string('status')->default('pending_review');

            // Core data
            $table->string('vendor')->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('vat_rate', 6, 3)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->string('currency_code', 3)->default('CHF');
            $table->date('expense_date');

            // Categorization
            $table->foreignUuid('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('deductibility')->default('uncertain');
            $table->decimal('deductible_amount', 18, 2)->nullable();
            $table->decimal('deductible_pct', 5, 2)->nullable();

            // OCR
            $table->string('receipt_path')->nullable(); // private disk path
            $table->timestamp('ocr_processed_at')->nullable();
            $table->json('ocr_raw_data')->nullable();
            $table->decimal('ocr_confidence', 5, 4)->nullable();

            // AI categorization
            $table->foreignUuid('ai_suggested_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->decimal('ai_confidence', 5, 4)->nullable();
            $table->text('ai_reasoning')->nullable();

            // Override tracking
            $table->boolean('user_overrode_category')->default(false);
            $table->boolean('user_overrode_deductibility')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_entity_id', 'status']);
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
