<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_base_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('escalation_id')->nullable()->unique()->constrained('ai_escalations')->nullOnDelete();
            $table->string('category');
            $table->longText('question');
            $table->longText('answer');
            $table->string('canton_code', 2)->nullable();
            $table->string('vat_status')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
            $table->index('canton_code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_entries');
    }
};
