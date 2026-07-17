<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_firm_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('accounting_firm_id')->constrained('accounting_firms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_owner')->default(false); // firm administrator
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['accounting_firm_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_firm_members');
    }
};
