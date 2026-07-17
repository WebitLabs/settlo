<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accountant_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('accounting_firm_id')->constrained('accounting_firms')->cascadeOnDelete();
            $table->foreignUuid('business_entity_id')->constrained('business_entities')->cascadeOnDelete();
            // Optional named accountant within the firm responsible for this client.
            $table->foreignId('accountant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable(); // null = active grant
            $table->timestamps();

            $table->unique(['accounting_firm_id', 'business_entity_id']);
            $table->index('business_entity_id');
            $table->index('accountant_id');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accountant_assignments');
    }
};
