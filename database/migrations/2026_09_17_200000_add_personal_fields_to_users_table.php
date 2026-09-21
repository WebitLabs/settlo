<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The person becomes the root of the account (Phase B): home address, phone
 * country, consent timestamps and the last opened workspace live on the user.
 * Existing accounts are marked as verified so the new email verification
 * requirement does not lock testers out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('street')->nullable()->after('avatar_url');
            $table->string('street_number', 20)->nullable()->after('street');
            $table->string('postal_code', 10)->nullable()->after('street_number');
            $table->string('city')->nullable()->after('postal_code');
            $table->string('country_code', 2)->default('CH')->after('city');
            $table->foreignUuid('canton_id')->nullable()->after('country_code')->constrained('cantons')->nullOnDelete();
            $table->foreignUuid('commune_id')->nullable()->after('canton_id')->constrained('communes')->nullOnDelete();
            $table->string('phone_country', 2)->nullable()->after('phone');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_country');
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_acknowledged_at')->nullable();
            $table->string('terms_version', 20)->nullable();
            $table->foreignUuid('last_business_entity_id')->nullable()->constrained('business_entities')->nullOnDelete();
        });

        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

        DB::table('users')
            ->whereNull('phone_country')
            ->where(function ($query): void {
                $query->where('phone', 'like', '+41%')->orWhere('phone', 'like', '0%');
            })
            ->update(['phone_country' => 'CH']);
    }

    public function down(): void
    {
        // A later migration indexes last_business_entity_id; SQLite refuses to
        // drop a column an index still points at, so the index goes first.
        $indexes = array_column(Schema::getIndexes('users'), 'name');

        Schema::table('users', function (Blueprint $table) use ($indexes): void {
            if (in_array('users_last_business_entity_id_index', $indexes, true)) {
                $table->dropIndex('users_last_business_entity_id_index');
            }

            $table->dropForeign(['canton_id']);
            $table->dropForeign(['commune_id']);
            $table->dropForeign(['last_business_entity_id']);
            $table->dropColumn([
                'street', 'street_number', 'postal_code', 'city', 'country_code',
                'canton_id', 'commune_id', 'phone_country', 'phone_verified_at',
                'terms_accepted_at', 'privacy_acknowledged_at', 'terms_version',
                'last_business_entity_id',
            ]);
        });
    }
};
