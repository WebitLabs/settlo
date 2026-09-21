<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax estimations belong to a person. Rows with a business_entity_id are a
 * workspace's share; rows without one are the consolidated personal estimate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tax_estimations', 'user_id')) {
            Schema::table('tax_estimations', function (Blueprint $table): void {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            });
        }

        $owners = DB::table('business_entities')->pluck('owner_id', 'id');

        foreach ($owners as $entityId => $ownerId) {
            DB::table('tax_estimations')
                ->where('business_entity_id', $entityId)
                ->whereNull('user_id')
                ->update(['user_id' => $ownerId]);
        }

        DB::table('tax_estimations')->whereNull('user_id')->delete();

        Schema::table('tax_estimations', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreignUuid('business_entity_id')->nullable()->change();
            $table->index(['user_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        DB::table('tax_estimations')->whereNull('business_entity_id')->delete();

        Schema::table('tax_estimations', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'fiscal_year']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('tax_estimations', function (Blueprint $table): void {
            $table->dropColumn('user_id');
            $table->foreignUuid('business_entity_id')->nullable(false)->change();
        });
    }
};
