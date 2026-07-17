<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->longText('content');
            $table->string('category')->nullable();

            // Assistant metadata
            $table->string('model_used')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->unsignedInteger('processing_ms')->nullable();
            $table->json('context_snapshot')->nullable();

            $table->timestamps();

            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
