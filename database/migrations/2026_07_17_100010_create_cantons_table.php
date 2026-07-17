<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cantons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 2)->unique();
            $table->string('name_de');
            $table->string('name_fr');
            $table->string('name_it');
            $table->string('name_en');
            $table->string('capital')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cantons');
    }
};
