<?php

use App\Enums\VatStatus;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\TaxEstimation;
use App\Models\User;
use Database\Seeders\CantonSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase B data migrations: legacy per-business tax profiles move to the owner
 * (deduplicated), VAT fields move to the business, and tax estimations gain
 * their owner.
 */
beforeEach(function () {
    $this->seed(CantonSeeder::class);

    $this->moveProfiles = require database_path('migrations/2026_09_17_200001_move_tax_profiles_to_users.php');
    $this->estimationOwners = require database_path('migrations/2026_09_17_200002_add_user_id_to_tax_estimations_table.php');
});

/**
 * Roll the schema back to the pre-Phase-B shape (after the fixtures were created).
 */
function useLegacySchema(): void
{
    test()->estimationOwners->down();
    test()->moveProfiles->down();
}

afterEach(function () {
    $this->moveProfiles->up();
    $this->estimationOwners->up();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function insertLegacyTaxProfile(BusinessEntity $entity, array $attributes = []): string
{
    $id = (string) Str::uuid();

    DB::table('tax_profiles')->insert([
        'id' => $id,
        'business_entity_id' => $entity->getKey(),
        'canton_id' => $entity->canton_id,
        'vat_status' => 'not_registered',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);

    return $id;
}

it('moves a legacy tax profile to the owner and its VAT fields to the business', function () {
    $owner = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->forCanton('BE')->for($owner, 'owner')->create();
    useLegacySchema();
    $profileId = insertLegacyTaxProfile($entity, [
        'vat_status' => 'registered_voluntary',
        'estimated_annual_revenue' => 90000,
        'marital_status' => 'married',
    ]);

    $this->moveProfiles->up();

    $profile = DB::table('tax_profiles')->where('id', $profileId)->first();
    $entity->refresh();

    expect(Schema::hasColumn('tax_profiles', 'business_entity_id'))->toBeFalse()
        ->and(Schema::hasColumn('tax_profiles', 'vat_status'))->toBeFalse()
        ->and($profile->user_id)->toBe($owner->getKey())
        ->and($profile->marital_status)->toBe('married')
        ->and($entity->vat_status)->toBe(VatStatus::RegisteredVoluntary)
        ->and((float) $entity->estimated_annual_revenue)->toBe(90000.0)
        ->and($owner->fresh()->canton_id)->toBe(Canton::where('code', 'BE')->value('id'))
        ->and($owner->fresh()->taxProfile->getKey())->toBe($profileId);

    $this->moveProfiles->down();

    expect(DB::table('tax_profiles')->where('id', $profileId)->value('business_entity_id'))->toBe($entity->getKey())
        ->and(DB::table('tax_profiles')->where('id', $profileId)->value('vat_status'))->toBe('registered_voluntary');
});

it('keeps only the most recently updated profile of an owner with several businesses', function () {
    $owner = User::factory()->owner()->create();
    $first = BusinessEntity::factory()->for($owner, 'owner')->create();
    $second = BusinessEntity::factory()->for($owner, 'owner')->create();
    useLegacySchema();
    $stale = insertLegacyTaxProfile($first, ['updated_at' => now()->subWeek(), 'vat_status' => 'exempt']);
    $fresh = insertLegacyTaxProfile($second, ['updated_at' => now()]);

    $this->moveProfiles->up();

    expect(DB::table('tax_profiles')->where('user_id', $owner->getKey())->pluck('id')->all())->toBe([$fresh])
        ->and(DB::table('tax_profiles')->where('id', $stale)->exists())->toBeFalse()
        ->and($first->fresh()->vat_status)->toBe(VatStatus::Exempt)
        ->and($second->fresh()->vat_status)->toBe(VatStatus::NotRegistered);
});

it('assigns existing tax estimations to the business owner', function () {
    $owner = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->for($owner, 'owner')->create();
    $this->estimationOwners->down();
    $id = (string) Str::uuid();
    DB::table('tax_estimations')->insert([
        'id' => $id,
        'business_entity_id' => $entity->getKey(),
        'fiscal_year' => 2026,
        'calculated_at' => now(),
        'inputs' => '{}',
        'rates_snapshot' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->estimationOwners->up();

    expect(TaxEstimation::find($id)->user_id)->toBe($owner->getKey());

    useLegacySchema();
});

it('backfills the email verification and phone country of existing users', function () {
    $personalFields = require database_path('migrations/2026_09_17_200000_add_personal_fields_to_users_table.php');
    $personalFields->down();

    $insertUser = function (array $attributes): int {
        return DB::table('users')->insertGetId([
            'first_name' => 'Legacy',
            'last_name' => 'User',
            'email' => Str::uuid().'@example.com',
            'password' => 'secret',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    };

    $verifiedAt = now()->subYear()->startOfSecond();
    $unverified = $insertUser(['email_verified_at' => null, 'phone' => '+41791234567']);
    $verified = $insertUser(['email_verified_at' => $verifiedAt, 'phone' => '079 123 45 67']);
    $foreign = $insertUser(['email_verified_at' => null, 'phone' => '+4915123456789']);
    $noPhone = $insertUser(['email_verified_at' => null, 'phone' => null]);

    $personalFields->up();

    $users = DB::table('users')->whereIn('id', [$unverified, $verified, $foreign, $noPhone])->get()->keyBy('id');

    expect($users[$unverified]->email_verified_at)->not->toBeNull()
        ->and($users[$unverified]->phone_country)->toBe('CH')
        ->and($users[$verified]->email_verified_at)->toBe($verifiedAt->toDateTimeString())
        ->and($users[$verified]->phone_country)->toBe('CH')
        ->and($users[$foreign]->email_verified_at)->not->toBeNull()
        ->and($users[$foreign]->phone_country)->toBeNull()
        ->and($users[$noPhone]->email_verified_at)->not->toBeNull()
        ->and($users[$noPhone]->phone_country)->toBeNull()
        ->and($users[$noPhone]->country_code)->toBe('CH')
        ->and($users[$noPhone]->phone_verified_at)->toBeNull();

    useLegacySchema();
});
