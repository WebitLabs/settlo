<?php

use App\Filament\Personal\Pages\PersonalProfile;
use App\Filament\Personal\Pages\VerifyPhone;
use App\Jobs\RecalculatePersonalTaxEstimation;
use App\Models\Canton;
use App\Models\Commune;
use App\Models\TaxProfile;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create([
        'first_name' => 'Anna',
        'last_name' => 'Muster',
        'phone' => '+41791234567',
        'phone_country' => 'CH',
    ]);
    $this->owner->forceFill(['phone_verified_at' => now()])->save();
    $this->zurich = Canton::where('code', 'ZH')->firstOrFail();
    $this->zurichCity = Commune::where('bfs_number', '261')->firstOrFail();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/**
 * @return array<string, mixed>
 */
function validAddress(Canton $canton, Commune $commune): array
{
    return [
        'street' => 'Bahnhofstrasse',
        'street_number' => '1',
        'postal_code' => '8001',
        'city' => 'Zürich',
        'canton_id' => $canton->getKey(),
        'commune_id' => $commune->getKey(),
    ];
}

it('is served at /app/profile and shows the three forms', function () {
    $this->get('/app/profile')
        ->assertOk()
        ->assertSee('Personal details')
        ->assertSee('Home address')
        ->assertSee('Password')
        ->assertSee('Contact support to change your email address');
});

it('saves the personal details without touching the address', function () {
    Livewire::test(PersonalProfile::class)
        ->assertSchemaStateSet(['email' => $this->owner->email, 'first_name' => 'Anna'], 'profileForm')
        ->fillForm(['first_name' => 'Annina', 'email' => 'hacker@example.com'], 'profileForm')
        ->fillForm(['street' => ''], 'addressForm')
        ->call('saveProfile')
        ->assertHasNoFormErrors(form: 'profileForm')
        ->assertNotified('Profile saved');

    $owner = $this->owner->fresh();
    expect($owner->first_name)->toBe('Annina')
        ->and($owner->email)->not->toBe('hacker@example.com');
});

it('validates each form independently', function () {
    Livewire::test(PersonalProfile::class)
        ->fillForm(['first_name' => ''], 'profileForm')
        ->fillForm(validAddress($this->zurich, $this->zurichCity), 'addressForm')
        ->call('saveAddress')
        ->assertHasNoFormErrors(form: 'addressForm')
        ->call('saveProfile')
        ->assertHasFormErrors(['first_name' => 'required'], 'profileForm');

    expect($this->owner->fresh()->street)->toBe('Bahnhofstrasse');
});

it('requires the address fields and a commune', function () {
    Livewire::test(PersonalProfile::class)
        ->fillForm(['canton_id' => $this->zurich->getKey()], 'addressForm')
        ->fillForm(['street' => '', 'postal_code' => '', 'city' => ''], 'addressForm')
        ->call('saveAddress')
        ->assertHasFormErrors(['street' => 'required', 'postal_code' => 'required', 'city' => 'required', 'commune_id' => 'required'], 'addressForm');
});

it('resets the phone verification when the number changes', function () {
    Livewire::test(PersonalProfile::class)
        ->fillForm(['phone' => '079 765 43 21'], 'profileForm')
        ->call('saveProfile')
        ->assertHasNoFormErrors(form: 'profileForm')
        ->assertNoRedirect();

    expect($this->owner->fresh()->phone)->toBe('+41797654321')
        ->and($this->owner->fresh()->phone_verified_at)->toBeNull();
});

it('sends the owner to verify a changed number when SMS verification is on', function () {
    config(['settlo.phone_verification.enabled' => true]);

    Livewire::test(PersonalProfile::class)
        ->fillForm(['phone' => '079 765 43 21'], 'profileForm')
        ->call('saveProfile')
        ->assertRedirect(VerifyPhone::getUrl());
});

it('keeps the verification when the number is unchanged', function () {
    config(['settlo.phone_verification.enabled' => true]);

    Livewire::test(PersonalProfile::class)
        ->call('saveProfile')
        ->assertHasNoFormErrors(form: 'profileForm')
        ->assertNoRedirect();

    expect($this->owner->fresh()->phone_verified_at)->not->toBeNull();
});

it('saves the address, fills a missing tax canton and recalculates the estimate', function () {
    Queue::fake();
    $profile = TaxProfile::factory()->for($this->owner)->create(['canton_id' => null, 'commune_id' => null]);

    Livewire::test(PersonalProfile::class)
        ->fillForm(validAddress($this->zurich, $this->zurichCity), 'addressForm')
        ->call('saveAddress')
        ->assertHasNoFormErrors(form: 'addressForm')
        ->assertNotified('Address saved');

    $owner = $this->owner->fresh();
    expect($owner->canton_id)->toBe($this->zurich->getKey())
        ->and($owner->commune_id)->toBe($this->zurichCity->getKey())
        ->and($owner->postal_code)->toBe('8001')
        ->and($profile->fresh()->canton_id)->toBe($this->zurich->getKey())
        ->and($profile->fresh()->commune_id)->toBe($this->zurichCity->getKey());

    Queue::assertPushed(RecalculatePersonalTaxEstimation::class, fn (RecalculatePersonalTaxEstimation $job): bool => $job->userId === $this->owner->getKey());
});

it('leaves an existing tax canton alone', function () {
    Queue::fake();
    $zug = Canton::where('code', 'ZG')->firstOrFail();
    $profile = TaxProfile::factory()->for($this->owner)->create(['canton_id' => $zug->getKey()]);

    Livewire::test(PersonalProfile::class)
        ->fillForm(validAddress($this->zurich, $this->zurichCity), 'addressForm')
        ->call('saveAddress')
        ->assertHasNoFormErrors(form: 'addressForm');

    expect($profile->fresh()->canton_id)->toBe($zug->getKey());
});

it('updates the password and keeps the session', function () {
    Livewire::test(PersonalProfile::class)
        ->fillForm([
            'current_password' => 'password',
            'password' => 'N3w-Secure-Passw0rd!',
            'password_confirmation' => 'N3w-Secure-Passw0rd!',
        ], 'passwordForm')
        ->call('updatePassword')
        ->assertHasNoFormErrors(form: 'passwordForm')
        ->assertNotified('Password updated')
        ->assertSchemaStateSet(['current_password' => null, 'password' => null], 'passwordForm');

    $owner = $this->owner->fresh();
    expect(Hash::check('N3w-Secure-Passw0rd!', $owner->password))->toBeTrue()
        ->and(session('password_hash_'.Filament::getAuthGuard()))->toBe($owner->getAuthPassword());
});

it('rejects a wrong current password or a mismatching confirmation', function () {
    Livewire::test(PersonalProfile::class)
        ->fillForm([
            'current_password' => 'wrong-password',
            'password' => 'N3w-Secure-Passw0rd!',
            'password_confirmation' => 'Something-else-1!',
        ], 'passwordForm')
        ->call('updatePassword')
        ->assertHasFormErrors(['current_password', 'password_confirmation' => 'same'], 'passwordForm');

    expect(Hash::check('password', $this->owner->fresh()->password))->toBeTrue();
});
