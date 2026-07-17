<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('canton_id')->constrained('cantons')->cascadeOnDelete();
            $table->string('name');
            $table->string('bfs_number');
            $table->decimal('tax_multiplier', 8, 4); // Steuerfuss %
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['canton_id', 'bfs_number']);
            $table->index('canton_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communes');
    }
};
