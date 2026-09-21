<?php

use App\Enums\ResidencePermit;
use App\Models\TaxProfile;
use App\Models\User;
use Database\Seeders\CantonSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('maps legacy residence permit values to the new residence statuses', function () {
    $this->seed(CantonSeeder::class);
    $migration = require database_path('migrations/2026_09_17_101219_migrate_residence_permit_values_on_tax_profiles_table.php');

    $swiss = TaxProfile::factory()->create();
    $permitB = TaxProfile::factory()->create();
    DB::table('tax_profiles')->where('id', $swiss->getKey())->update(['residence_permit' => 'swiss_or_c']);
    DB::table('tax_profiles')->where('id', $permitB->getKey())->update(['residence_permit' => 'b_permit']);

    $migration->up();

    expect($swiss->fresh()->residence_permit)->toBe(ResidencePermit::SwissCitizen)
        ->and($permitB->fresh()->residence_permit)->toBe(ResidencePermit::EuEftaPermitB);

    $migration->down();

    expect(DB::table('tax_profiles')->where('id', $swiss->getKey())->value('residence_permit'))->toBe('swiss_or_c')
        ->and(DB::table('tax_profiles')->where('id', $permitB->getKey())->value('residence_permit'))->toBe('b_permit');

    $migration->up();
});

it('defaults new tax profiles to Swiss citizen', function () {
    $this->seed(CantonSeeder::class);
    $user = User::factory()->create();

    DB::table('tax_profiles')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('tax_profiles')->where('user_id', $user->getKey())->value('residence_permit'))->toBe('swiss');
});
