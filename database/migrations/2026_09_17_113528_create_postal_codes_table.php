<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('postal_code', 4)->index();
            $table->string('locality');
            $table->string('bfs_number');
            $table->string('canton_code', 2)->nullable();
            $table->decimal('address_share', 5, 2);
            $table->timestamps();

            $table->unique(['postal_code', 'locality', 'bfs_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postal_codes');
    }
};
