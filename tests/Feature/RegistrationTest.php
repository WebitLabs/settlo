<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Personal\Auth\Register;
use App\Models\BusinessEntity;
use App\Models\User;
use App\Support\PhoneCountries;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Register::class.'|register|127.0.0.1'));
});

/**
 * @return array<string, mixed>
 */
function registrationData(array $overrides = []): array
{
    return [
        'first_name' => 'Anna',
        'last_name' => 'Muster',
        'email' => 'anna.muster@example.ch',
        'phone_country' => 'CH',
        'phone' => '079 123 45 67',
        'preferred_language' => 'en',
        'password' => 'long-enough-Passw0rd',
        'passwordConfirmation' => 'long-enough-Passw0rd',
        'accept_terms' => true,
        ...$overrides,
    ];
}

describe('registration form (C2)', function () {
    it('creates an active owner with the consent, the E.164 phone and a verification email', function () {
        Notification::fake();

        Livewire::test(Register::class)
            ->fillForm(registrationData())
            ->call('register')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $user = User::where('email', 'anna.muster@example.ch')->firstOrFail();
        expect($user->role)->toBe(UserRole::Owner)
            ->and($user->status)->toBe(UserStatus::Active)
            ->and($user->phone)->toBe('+41791234567')
            ->and($user->phone_country)->toBe('CH')
            ->and($user->phone_verified_at)->toBeNull()
            ->and($user->terms_accepted_at)->not->toBeNull()
            ->and($user->privacy_acknowledged_at)->not->toBeNull()
            ->and($user->terms_version)->toBe('2026-09')
            ->and($user->hasVerifiedEmail())->toBeFalse()
            ->and(BusinessEntity::where('owner_id', $user->getKey())->exists())->toBeFalse();

        Notification::assertSentTo($user, VerifyEmail::class);
    });

    it('rejects invalid input', function (array $overrides, array $errors) {
        User::factory()->create(['email' => 'taken@example.ch']);

        Livewire::test(Register::class)
            ->fillForm(registrationData($overrides))
            ->call('register')
            ->assertHasFormErrors($errors);

        expect(User::where('first_name', 'Anna')->exists())->toBeFalse();
    })->with([
        'missing first name' => [['first_name' => ''], ['first_name' => 'required']],
        'missing last name' => [['last_name' => ''], ['last_name' => 'required']],
        'invalid email' => [['email' => 'not-an-email'], ['email' => 'email']],
        'taken email' => [['email' => 'taken@example.ch'], ['email' => 'unique']],
        'garbage phone' => [['phone' => 'abcde'], ['phone']],
        'Swiss landline' => [['phone' => '044 123 45 67'], ['phone']],
        'short password' => [['password' => 'short', 'passwordConfirmation' => 'short'], ['password']],
        'password mismatch' => [['passwordConfirmation' => 'something-else'], ['passwordConfirmation' => 'same']],
        'terms not accepted' => [['accept_terms' => false], ['accept_terms' => 'accepted']],
    ]);

    it('explains that the terms must be accepted', function () {
        Livewire::test(Register::class)
            ->fillForm(registrationData(['accept_terms' => false]))
            ->call('register')
            ->assertSee('Please accept the Terms of Service to create your account.');
    });

    it('reports a password mismatch on the confirmation only', function () {
        Livewire::test(Register::class)
            ->fillForm(registrationData(['passwordConfirmation' => 'something-else']))
            ->call('register')
            ->assertHasFormErrors(['passwordConfirmation'])
            ->assertHasNoFormErrors(['password']);
    });

    it('links the terms of service and privacy notice', function () {
        config([
            'settlo.legal.terms_url' => 'https://settlo.test/terms',
            'settlo.legal.privacy_url' => 'https://settlo.test/privacy',
        ]);

        $this->get('/app/register')
            ->assertOk()
            ->assertSee('href="https://settlo.test/terms"', false)
            ->assertSee('href="https://settlo.test/privacy"', false)
            ->assertSee('Terms of Service')
            ->assertSee('Privacy Notice');
    });

    it('does not throttle registration attempts that fail validation', function () {
        $component = Livewire::test(Register::class);

        foreach (range(1, 6) as $attempt) {
            $component
                ->fillForm(registrationData(['email' => 'not-an-email']))
                ->call('register')
                ->assertHasFormErrors(['email'])
                ->assertNotNotified();
        }

        $component
            ->fillForm(registrationData())
            ->call('register')
            ->assertHasNoFormErrors()
            ->assertNotNotified();

        expect(User::where('email', 'anna.muster@example.ch')->exists())->toBeTrue();
    });

    it('stops answering once the same IP has probed the form too often', function () {
        User::factory()->create(['email' => 'taken@example.ch']);

        $attempts = Register::VALIDATION_ATTEMPTS_PER_MINUTE;
        $component = Livewire::test(Register::class);

        // Below the limit the unique rule still answers.
        $component
            ->fillForm(registrationData(['email' => 'taken@example.ch']))
            ->call('register')
            ->assertHasFormErrors(['email' => 'unique']);

        foreach (range(1, $attempts) as $attempt) {
            $component
                ->fillForm(registrationData(['email' => 'not-an-email']))
                ->call('register')
                ->assertHasFormErrors(['email']);
        }

        // Past the limit validation no longer runs, so the form stops
        // answering "does this address exist?" — on a fresh page too, because
        // the limit is per IP, not per component.
        Livewire::test(Register::class)
            ->fillForm(registrationData(['email' => 'taken@example.ch']))
            ->call('register')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect(User::where('first_name', 'Anna')->exists())->toBeFalse();
    });

    it('accepts a registration without a phone number', function () {
        Livewire::test(Register::class)
            ->fillForm(registrationData(['phone' => '']))
            ->call('register')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        expect(User::where('email', 'anna.muster@example.ch')->firstOrFail()->phone)->toBeNull();
    });

    it('requires a phone number again once SMS verification is switched on', function () {
        config(['settlo.phone_verification.enabled' => true]);

        Livewire::test(Register::class)
            ->fillForm(registrationData(['phone' => '']))
            ->call('register')
            ->assertHasFormErrors(['phone' => 'required']);

        expect(User::where('email', 'anna.muster@example.ch')->exists())->toBeFalse();
    });

    it('hides the language picker while the interface is English-only', function () {
        $this->get('/app/register')
            ->assertOk()
            ->assertDontSee('Deutsch')
            ->assertDontSee('Italiano');

        Livewire::test(Register::class)
            ->fillForm(registrationData())
            ->call('register')
            ->assertHasNoFormErrors();

        expect(User::where('email', 'anna.muster@example.ch')->firstOrFail()->preferred_language)->toBe('en');
    });

    it('adds autofill hints to the registration form', function () {
        $this->get('/app/register')
            ->assertOk()
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('autocomplete="given-name"', false)
            ->assertSee('autocomplete="family-name"', false)
            ->assertSee('autocomplete="tel-national"', false);
    });
});

describe('phone number (C3)', function () {
    it('stores numbers of the selected country in E.164 form', function (string $country, string $phone, string $expected) {
        Livewire::test(Register::class)
            ->fillForm(registrationData(['phone_country' => $country, 'phone' => $phone]))
            ->call('register')
            ->assertHasNoFormErrors();

        expect(User::where('email', 'anna.muster@example.ch')->firstOrFail())
            ->phone->toBe($expected)
            ->phone_country->toBe($country);
    })->with([
        'Swiss mobile' => ['CH', '079 123 45 67', '+41791234567'],
        'Swiss mobile, international' => ['CH', '+41 79 123 45 67', '+41791234567'],
        'German mobile' => ['DE', '0151 23456789', '+4915123456789'],
    ]);

    it('rejects a number that belongs to another country', function () {
        Livewire::test(Register::class)
            ->fillForm(registrationData(['phone_country' => 'CH', 'phone' => '0151 23456789']))
            ->call('register')
            ->assertHasFormErrors(['phone']);
    });

    it('validates the phone as soon as it changes and again when the country changes', function () {
        Livewire::test(Register::class)
            ->set('data.phone', 'abcde')
            ->assertHasErrors(['data.phone'])
            ->set('data.phone', '0151 23456789')
            ->assertHasErrors(['data.phone'])
            ->set('data.phone_country', 'DE')
            ->assertHasNoErrors(['data.phone']);
    });

    it('lists Switzerland and its neighbours first', function () {
        $options = PhoneCountries::options();

        expect(array_slice(array_keys($options), 0, 6))->toBe(['CH', 'LI', 'DE', 'AT', 'FR', 'IT'])
            ->and($options['CH'])->toBe('🇨🇭 Switzerland +41')
            ->and($options)->toHaveKey('US')
            ->and(count($options))->toBeGreaterThan(200)
            ->and(PhoneCountries::example('CH'))->toStartWith('07');
    });
});

describe('email verification (C1)', function () {
    it('asks an unverified owner to verify the email before using the app', function () {
        $this->actingAs(User::factory()->owner()->unverified()->create())
            ->get('/app')
            ->assertRedirect('/app/email-verification/prompt');
    });

    it('asks an unverified owner to verify the email before opening a workspace', function () {
        $owner = User::factory()->owner()->unverified()->create();
        $entity = BusinessEntity::factory()->for($owner, 'owner')->create();

        $this->actingAs($owner)
            ->get("/app/w/{$entity->getKey()}")
            ->assertRedirect('/app/email-verification/prompt');
    });

    it('marks the email verified through the signed link and opens the personal dashboard', function () {
        $owner = User::factory()->owner()->unverified()->create();

        $url = URL::temporarySignedRoute('filament.app.auth.email-verification.verify', now()->addHour(), [
            'id' => $owner->getKey(),
            'hash' => sha1($owner->getEmailForVerification()),
        ]);

        $this->actingAs($owner)
            ->get($url)
            ->assertRedirect('/app');

        expect($owner->fresh()->hasVerifiedEmail())->toBeTrue();

        $this->get('/app')->assertOk();
    });
});
