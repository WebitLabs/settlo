<?php

namespace App\Filament\Personal\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Shared\Fields\PhoneNumberField;
use App\Filament\Support\ValidatesOnBlur;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Password;

/**
 * App-panel signup (one screen): name, email, an optional mobile phone with
 * country code, password and the terms consent. Business data is collected
 * later in the app ("Set up a business"). The security-critical role/status and
 * the consent timestamps are set server-side via forceFill so they can never be
 * forged from the request payload.
 */
class Register extends BaseRegister
{
    protected Width|string|null $maxWidth = Width::TwoExtraLarge;

    /**
     * Form validations allowed per minute per IP before the page stops
     * answering — the guard against enumerating addresses through the unique
     * email rule.
     */
    public const int VALIDATION_ATTEMPTS_PER_MINUTE = 20;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'sm' => 2])
            ->components([
                TextInput::make('first_name')
                    ->label('First name')
                    ->required()
                    ->maxLength(255)
                    ->autofocus()
                    ->autocomplete('given-name')
                    ->tap(new ValidatesOnBlur),
                TextInput::make('last_name')
                    ->label('Last name')
                    ->required()
                    ->maxLength(255)
                    ->autocomplete('family-name')
                    ->tap(new ValidatesOnBlur),
                $this->getEmailFormComponent()
                    ->autocomplete('email')
                    ->columnSpanFull()
                    ->tap(new ValidatesOnBlur),
                PhoneNumberField::make()
                    ->columnSpanFull(),
                // The interface is English-only for now, so the picker is
                // hidden rather than removed: the column keeps feeding the
                // multilingual invoice templates and comes back when the UI
                // is translated.
                Hidden::make('preferred_language')
                    ->default('en'),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                Checkbox::make('accept_terms')
                    ->label(fn (): HtmlString => new HtmlString(sprintf(
                        'I agree to the <a href="%s" target="_blank" rel="noopener" class="font-medium text-primary-600 underline dark:text-primary-400">Terms of Service</a> and acknowledge that I have read the <a href="%s" target="_blank" rel="noopener" class="font-medium text-primary-600 underline dark:text-primary-400">Privacy Notice</a>.',
                        e(config('settlo.legal.terms_url')),
                        e(config('settlo.legal.privacy_url')),
                    )))
                    ->accepted()
                    ->validationMessages(['accepted' => 'Please accept the Terms of Service to create your account.'])
                    ->dehydrated(false)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Genuine mistakes never consume the (much tighter) registration throttle:
     * the form is validated before Filament's own rate limiter is hit.
     *
     * Validation itself is not free of information, though, so it is throttled
     * on its own — see {@see hasBudgetToValidateEmail()}.
     */
    public function register(): ?RegistrationResponse
    {
        if (! $this->hasBudgetToValidateEmail()) {
            return null;
        }

        $this->form->validate();

        return parent::register();
    }

    /**
     * The field-level validation that runs while the form is being filled in
     * answers the same question as a full submit, one cheap Livewire request at
     * a time, so it is throttled with the same budget.
     *
     * @param  string  $field
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @param  array<string, mixed>  $dataOverrides
     */
    public function validateOnly($field, $rules = null, $messages = [], $attributes = [], $dataOverrides = []): mixed
    {
        if (str($field)->endsWith('.email') && ! $this->hasBudgetToValidateEmail()) {
            return [];
        }

        return parent::validateOnly($field, $rules, $messages, $attributes, $dataOverrides);
    }

    /**
     * Whether this IP may still have an email address checked. The unique rule
     * answers "does this account exist?", so without a ceiling a list of
     * addresses can be probed one request at a time. The budget is far above
     * what filling in the form takes and far below what enumeration needs; the
     * throttle notification is the only answer past it.
     */
    private function hasBudgetToValidateEmail(): bool
    {
        try {
            $this->rateLimit(self::VALIDATION_ATTEMPTS_PER_MINUTE, method: 'validateRegistration');
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return false;
        }

        return true;
    }

    /**
     * Allow a few genuine attempts per minute per IP (Filament's default is 2).
     *
     * @param  int  $maxAttempts
     * @param  int  $decaySeconds
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        // The parent derives the throttle key from the calling method via debug_backtrace(),
        // which would now be this override, so the real caller is passed explicitly.
        $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];

        parent::rateLimit(max($maxAttempts, 5), $decaySeconds, $method, $component);
    }

    /**
     * The "must match" rule lives on the confirmation field (not on the password as in
     * Filament's default), so validating the password on blur doesn't complain before
     * the user has reached the confirmation.
     */
    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/register.form.password.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(Password::default())
            ->showAllValidationMessages()
            ->autocomplete('new-password')
            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
            ->validationAttribute(__('filament-panels::auth/pages/register.form.password.validation_attribute'))
            ->tap(new ValidatesOnBlur);
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label(__('filament-panels::auth/pages/register.form.password_confirmation.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->same('password')
            ->autocomplete('new-password')
            ->dehydrated(false)
            ->validationAttribute('password confirmation')
            ->tap(new ValidatesOnBlur);
    }

    /**
     * Role and status are excluded from mass assignment; they are forced here so
     * a crafted registration cannot escalate a new account beyond an owner.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        $model = $this->getUserModel();

        /** @var Model $user */
        $user = new $model;
        $user->fill($data);
        $user->forceFill([
            'role' => UserRole::Owner,
            'status' => UserStatus::Active,
            'terms_accepted_at' => now(),
            'privacy_acknowledged_at' => now(),
            'terms_version' => config('settlo.legal.version'),
            'phone_verified_at' => null,
        ])->save();

        return $user;
    }
}
