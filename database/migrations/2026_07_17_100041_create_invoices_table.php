<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_entity_id')->constrained('business_entities')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('invoice_number'); // INV-2026-0001, unique per entity
            $table->string('status')->default('draft');

            // Amounts (CHF, decimal(18,2), computed server-side from line items)
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('currency_code', 3)->default('CHF');

            // Dates
            $table->date('issue_date');
            $table->date('due_date');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();

            // Content
            $table->string('language', 2)->default('en');
            $table->string('reference')->nullable(); // client PO / reference
            $table->text('notes')->nullable();          // visible on PDF
            $table->text('internal_notes')->nullable(); // private

            // Swiss QR-bill snapshot (frozen at send time)
            $table->string('qr_reference')->nullable();     // 27-digit QR reference
            $table->string('creditor_iban')->nullable();
            $table->string('creditor_name')->nullable();
            $table->string('creditor_street')->nullable();
            $table->string('creditor_city')->nullable();
            $table->string('creditor_postal')->nullable();
            $table->string('creditor_country', 2)->default('CH');
            $table->text('additional_info')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_entity_id', 'invoice_number']);
            $table->index(['business_entity_id', 'status']);
            $table->index('client_id');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
