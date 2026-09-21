<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The tax profile belongs to the person, not to a business (Phase B, P2).
 * VAT status and estimated revenue are per business and move onto
 * business_entities. When an owner had several profiles (one per business),
 * the most recently updated one is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('business_entities', 'vat_status')) {
            Schema::table('business_entities', function (Blueprint $table): void {
                $table->string('vat_status')->default('not_registered')->after('mwst_number');
                $table->decimal('estimated_annual_revenue', 18, 2)->nullable()->after('vat_status');
            });
        }

        if (! Schema::hasColumn('tax_profiles', 'business_entity_id')) {
            return;
        }

        foreach (DB::table('tax_profiles')->orderBy('id')->get(['business_entity_id', 'vat_status', 'estimated_annual_revenue']) as $profile) {
            DB::table('business_entities')->where('id', $profile->business_entity_id)->update([
                'vat_status' => $profile->vat_status ?? 'not_registered',
                'estimated_annual_revenue' => $profile->estimated_annual_revenue,
            ]);
        }

        if (! Schema::hasColumn('tax_profiles', 'user_id')) {
            Schema::table('tax_profiles', function (Blueprint $table): void {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            });
        }

        $owners = DB::table('business_entities')->pluck('owner_id', 'id');

        foreach (DB::table('tax_profiles')->get(['id', 'business_entity_id']) as $profile) {
            DB::table('tax_profiles')->where('id', $profile->id)->update([
                'user_id' => $owners[$profile->business_entity_id] ?? null,
            ]);
        }

        $orphans = DB::table('tax_profiles')->whereNull('user_id')->pluck('id');
        if ($orphans->isNotEmpty()) {
            Log::info('move_tax_profiles_to_users: deleted tax profiles without an owner', ['ids' => $orphans->all()]);
            DB::table('tax_profiles')->whereIn('id', $orphans)->delete();
        }

        $this->dedupe();

        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->dropForeign(['business_entity_id']);
            $table->dropUnique(['business_entity_id']);
        });

        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->dropColumn(['business_entity_id', 'vat_status', 'estimated_annual_revenue']);
        });

        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->unique('user_id');
        });

        foreach (DB::table('tax_profiles')->get(['user_id', 'canton_id', 'commune_id']) as $profile) {
            DB::table('users')->where('id', $profile->user_id)->whereNull('canton_id')->update([
                'canton_id' => $profile->canton_id,
                'commune_id' => $profile->commune_id,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tax_profiles', 'business_entity_id')) {
            return;
        }

        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->foreignUuid('business_entity_id')->nullable()->after('id')->constrained('business_entities')->cascadeOnDelete();
            $table->string('vat_status')->default('not_registered')->after('commune_id');
            $table->decimal('estimated_annual_revenue', 18, 2)->nullable()->after('vat_status');
        });

        foreach (DB::table('tax_profiles')->get(['id', 'user_id']) as $profile) {
            $entity = DB::table('business_entities')
                ->where('owner_id', $profile->user_id)
                ->orderBy('created_at')
                ->first(['id', 'vat_status', 'estimated_annual_revenue']);

            if ($entity === null) {
                DB::table('tax_profiles')->where('id', $profile->id)->delete();

                continue;
            }

            DB::table('tax_profiles')->where('id', $profile->id)->update([
                'business_entity_id' => $entity->id,
                'vat_status' => $entity->vat_status,
                'estimated_annual_revenue' => $entity->estimated_annual_revenue,
            ]);
        }

        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
        });

        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->dropColumn('user_id');
            $table->unique('business_entity_id');
        });

        Schema::table('business_entities', function (Blueprint $table): void {
            $table->dropColumn(['vat_status', 'estimated_annual_revenue']);
        });
    }

    /**
     * Keep one profile per owner: the most recently updated one.
     */
    private function dedupe(): void
    {
        $deleted = [];

        foreach (DB::table('tax_profiles')->select('user_id')->groupBy('user_id')->havingRaw('count(*) > 1')->pluck('user_id') as $userId) {
            $ids = DB::table('tax_profiles')
                ->where('user_id', $userId)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->pluck('id');

            $duplicates = $ids->slice(1)->values()->all();
            DB::table('tax_profiles')->whereIn('id', $duplicates)->delete();
            $deleted = [...$deleted, ...$duplicates];
        }

        if ($deleted !== []) {
            Log::info('move_tax_profiles_to_users: deleted duplicate tax profiles', ['ids' => $deleted]);
        }
    }
};
