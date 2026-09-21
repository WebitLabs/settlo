<?php

use App\Filament\Personal\Pages\PersonalDashboard;
use App\Filament\Personal\Pages\PersonalProfile;
use App\Filament\Personal\Pages\VerifyPhone;
use App\Http\Middleware\EnsurePhoneIsVerified;
use App\Models\BusinessEntity;
use App\Models\User;
use App\Services\Phone\LogPhoneVerifier;
use App\Services\Phone\PhoneVerifier;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * The log verifier, remembering the codes it generated.
 */
class RecordingPhoneVerifier extends LogPhoneVerifier
{
    /** @var list<string> */
    public array $codes = [];

    public function lastCode(): ?string
    {
        return $this->codes === [] ? null : $this->codes[array_key_last($this->codes)];
    }

    protected function generateCode(): string
    {
        return $this->codes[] = parent::generateCode();
    }
}

beforeEach(function () {
    $this->verifier = new RecordingPhoneVerifier;
    $this->app->instance(PhoneVerifier::class, $this->verifier);

    $this->owner = User::factory()->owner()->create(['phone' => '+41791234567', 'phone_country' => 'CH']);
    RateLimiter::clear('phone-otp-resend:'.$this->owner->getKey());

    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/**
 * A code that is guaranteed to differ from the pending one.
 */
function wrongCode(?string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

describe('feature flag off', function () {
    it('does not ask for a phone verification', function () {
        $this->actingAs($this->owner)->get('/app')->assertOk();
        $this->actingAs($this->owner)->get('/app/verify-phone')->assertForbidden();

        expect($this->verifier->codes)->toBe([]);
    });

    it('lets owners with an unverified phone create a business', function () {
        expect($this->owner->phone_verified_at)->toBeNull()
            ->and($this->owner->can('create', BusinessEntity::class))->toBeTrue();
    });

    it('binds the log verifier by default', function () {
        $this->app->forgetInstance(PhoneVerifier::class);

        expect(app(PhoneVerifier::class))->toBeInstanceOf(LogPhoneVerifier::class);
    });
});

describe('feature flag on', function () {
    beforeEach(function () {
        config(['settlo.phone_verification.enabled' => true]);
    });

    it('sends owners with an unverified phone to the verification page', function () {
        $entity = BusinessEntity::factory()->for($this->owner, 'owner')->create();

        $this->actingAs($this->owner)->get('/app')->assertRedirect(VerifyPhone::getUrl(panel: 'app'));
        $this->actingAs($this->owner)->get('/app/businesses/new')->assertRedirect(VerifyPhone::getUrl(panel: 'app'));
        $this->actingAs($this->owner)->get("/app/w/{$entity->getKey()}")->assertRedirect(VerifyPhone::getUrl(panel: 'app'));
    });

    it('only lets owners with a verified phone create a business', function () {
        expect($this->owner->can('create', BusinessEntity::class))->toBeFalse();

        $this->owner->forceFill(['phone_verified_at' => now()])->save();

        expect($this->owner->fresh()->can('create', BusinessEntity::class))->toBeTrue();
    });

    it('keeps the profile and the verification page reachable', function () {
        $this->actingAs($this->owner)->get('/app/profile')->assertOk();
        $this->actingAs($this->owner)
            ->get('/app/verify-phone')
            ->assertOk()
            ->assertSee('Verify your phone')
            ->assertSee('+41 79 *** ** 67');
    });

    it('asks for the email verification first', function () {
        $owner = User::factory()->owner()->unverified()->create();

        $this->actingAs($owner)->get('/app')->assertRedirect('/app/email-verification/prompt');
    });

    it('does not redirect verified owners or accountants', function () {
        $this->owner->forceFill(['phone_verified_at' => now()])->save();

        $this->actingAs($this->owner)->get('/app')->assertOk();
        $this->actingAs($this->owner)->get('/app/verify-phone')->assertForbidden();
        expect(EnsurePhoneIsVerified::mustVerify(User::factory()->accountant()->create()))->toBeFalse();
    });

    it('sends a code when the page opens, but only once', function () {
        $this->actingAs($this->owner);

        Livewire::test(VerifyPhone::class);
        Livewire::test(VerifyPhone::class);

        expect($this->verifier->codes)->toHaveCount(1)
            ->and($this->verifier->lastCode())->toMatch('/^\d{6}$/');
    });

    it('verifies the phone with the correct code', function () {
        $this->actingAs($this->owner);

        Livewire::test(VerifyPhone::class)
            ->fillForm(['code' => $this->verifier->lastCode()])
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect(PersonalDashboard::getUrl(panel: 'app'));

        expect($this->owner->fresh()->phone_verified_at)->not->toBeNull();

        $this->get('/app')->assertOk();
    });

    it('rejects a wrong code', function () {
        $this->actingAs($this->owner);

        Livewire::test(VerifyPhone::class)
            ->fillForm(['code' => wrongCode($this->verifier->lastCode())])
            ->call('verify')
            ->assertHasErrors(['data.code'])
            ->assertSee('The code is invalid or expired.')
            ->assertNoRedirect();

        expect($this->owner->fresh()->phone_verified_at)->toBeNull();
    });

    it('locks the code after five wrong attempts', function () {
        $this->actingAs($this->owner);
        $component = Livewire::test(VerifyPhone::class);
        $code = $this->verifier->lastCode();

        foreach (range(1, 5) as $attempt) {
            $component->fillForm(['code' => wrongCode($code)])->call('verify')->assertHasErrors(['data.code']);
        }

        $component->fillForm(['code' => $code])->call('verify')->assertHasErrors(['data.code']);

        expect($this->owner->fresh()->phone_verified_at)->toBeNull();
    });

    it('expires the code', function () {
        $this->actingAs($this->owner);
        $component = Livewire::test(VerifyPhone::class);

        $this->travel(11)->minutes();

        $component->fillForm(['code' => $this->verifier->lastCode()])->call('verify')->assertHasErrors(['data.code']);
    });

    it('enforces the resend cooldown', function () {
        $this->actingAs($this->owner);
        $component = Livewire::test(VerifyPhone::class);

        $component
            ->callAction('resend')
            ->assertNotified('A new code is on its way');
        expect($this->verifier->codes)->toHaveCount(2);

        $component
            ->callAction('resend')
            ->assertNotified('Please wait before requesting a new code');
        expect($this->verifier->codes)->toHaveCount(2);

        $this->travel(61)->seconds();

        $component->callAction('resend');
        expect($this->verifier->codes)->toHaveCount(3);
    });

    it('only accepts the latest code after a resend', function () {
        $this->actingAs($this->owner);
        $component = Livewire::test(VerifyPhone::class);
        $first = $this->verifier->lastCode();

        $component->callAction('resend');
        $latest = $this->verifier->lastCode();

        if ($first !== $latest) {
            $component->fillForm(['code' => $first])->call('verify')->assertHasErrors(['data.code']);
        }

        $component->fillForm(['code' => $latest])->call('verify')->assertHasNoErrors();
        expect($this->owner->fresh()->phone_verified_at)->not->toBeNull();
    });

    it('never writes the code to the log outside the local environment', function () {
        Log::spy();

        (new LogPhoneVerifier)->sendCode($this->owner);

        Log::shouldNotHaveReceived('info');
    });
});

describe('changing the number', function () {
    it('stores the new number in E.164 form and resets its verification', function () {
        $this->owner->forceFill(['phone_verified_at' => now()])->save();
        $this->actingAs($this->owner);

        Livewire::test(PersonalProfile::class)
            ->assertSchemaStateSet(['phone_country' => 'CH', 'phone' => '079 123 45 67'], 'profileForm')
            ->call('saveProfile')
            ->assertHasNoFormErrors(form: 'profileForm');

        expect($this->owner->fresh()->phone_verified_at)->not->toBeNull();

        Livewire::test(PersonalProfile::class)
            ->fillForm(['phone_country' => 'DE', 'phone' => '0151 23456789'], 'profileForm')
            ->call('saveProfile')
            ->assertHasNoFormErrors(form: 'profileForm');

        $owner = $this->owner->fresh();
        expect($owner->phone)->toBe('+4915123456789')
            ->and($owner->phone_country)->toBe('DE')
            ->and($owner->phone_verified_at)->toBeNull();
    });

    it('discards the code sent to the old number and sends a fresh one', function () {
        config(['settlo.phone_verification.enabled' => true]);
        $this->actingAs($this->owner);

        Livewire::test(VerifyPhone::class);
        $oldCode = $this->verifier->lastCode();

        Livewire::test(PersonalProfile::class)
            ->fillForm(['phone_country' => 'CH', 'phone' => '078 765 43 21'], 'profileForm')
            ->call('saveProfile')
            ->assertHasNoFormErrors(form: 'profileForm')
            ->assertRedirect(VerifyPhone::getUrl(panel: 'app'));

        expect($this->verifier->codes)->toHaveCount(2)
            ->and($this->verifier->hasPendingCode($this->owner))->toBeTrue();

        $newCode = $this->verifier->lastCode();
        $owner = $this->owner->fresh();

        if ($oldCode !== $newCode) {
            expect($this->verifier->verify($owner, $oldCode))->toBeFalse();
        }

        expect($this->verifier->verify($owner, $newCode))->toBeTrue();
    });

    it('forgets the pending code when the number changes with the feature off', function () {
        $this->actingAs($this->owner);
        $this->verifier->sendCode($this->owner);
        $oldCode = $this->verifier->lastCode();

        Livewire::test(PersonalProfile::class)
            ->fillForm(['phone_country' => 'CH', 'phone' => '078 765 43 21'], 'profileForm')
            ->call('saveProfile')
            ->assertHasNoFormErrors(form: 'profileForm')
            ->assertNoRedirect();

        expect($this->verifier->codes)->toHaveCount(1)
            ->and($this->verifier->hasPendingCode($this->owner))->toBeFalse()
            ->and($this->verifier->verify($this->owner->fresh(), $oldCode))->toBeFalse();
    });

    it('requires a phone number on the profile', function () {
        config(['settlo.phone_verification.enabled' => true]);
        $this->owner->forceFill(['phone_verified_at' => now()])->save();
        $this->actingAs($this->owner);

        Livewire::test(PersonalProfile::class)
            ->fillForm(['phone' => null], 'profileForm')
            ->call('saveProfile')
            ->assertHasFormErrors(['phone' => 'required'], 'profileForm')
            ->assertNoRedirect();

        expect($this->owner->fresh())
            ->phone->toBe('+41791234567')
            ->phone_verified_at->not->toBeNull();
    });

    it('rejects a landline', function () {
        $this->actingAs($this->owner);

        Livewire::test(PersonalProfile::class)
            ->fillForm(['phone' => '044 123 45 67'], 'profileForm')
            ->call('saveProfile')
            ->assertHasFormErrors(['phone'], 'profileForm');
    });
});

it('masks the phone number', function (string $phone, string $masked) {
    expect(VerifyPhone::mask($phone))->toBe($masked);
})->with([
    'Swiss mobile' => ['+41791234567', '+41 79 *** ** 67'],
    'German mobile' => ['+4915123456789', '+49 1512 *****89'],
]);
