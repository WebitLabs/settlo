<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_escalations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignUuid('message_id')->unique()->constrained('ai_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // escalating owner
            $table->foreignId('accountant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('accounting_firm_id')->nullable()->constrained('accounting_firms')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('category')->nullable();

            $table->longText('user_question');
            $table->longText('ai_answer');

            // Accountant response
            $table->longText('accountant_answer')->nullable();
            $table->text('accountant_notes')->nullable(); // internal, not shown to user
            $table->timestamp('answered_at')->nullable();

            // Knowledge base
            $table->boolean('add_to_knowledge_base')->default(false);
            $table->timestamp('knowledge_base_approved_at')->nullable();

            // SLA (24 business hours)
            $table->timestamp('sla_deadline')->nullable();
            $table->boolean('sla_breached')->default(false);

            $table->timestamps();

            $table->index('status');
            $table->index('accountant_id');
            $table->index('accounting_firm_id');
            $table->index('sla_deadline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_escalations');
    }
};
