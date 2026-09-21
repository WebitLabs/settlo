<?php

namespace App\Filament\Personal\Pages;

use App\Http\Middleware\EnsurePhoneIsVerified;
use App\Models\User;
use App\Services\Phone\PhoneVerifier;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasMaxWidth;
use Filament\Pages\Concerns\HasTopbar;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * "Verify your phone": the owner enters the 6-digit code sent by SMS. Only
 * reachable while the phone verification feature is on and the number is not
 * confirmed yet. Rendered in the simple (centered) layout, like the email
 * verification prompt.
 *
 * @property-read Schema $form
 */
class VerifyPhone extends Page
{
    use HasMaxWidth;
    use HasTopbar;

    protected static ?string $slug = 'verify-phone';

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected string $view = 'filament-panels::pages.simple';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return EnsurePhoneIsVerified::mustVerify(Filament::auth()->user());
    }

    public function getTitle(): string
    {
        return 'Verify your phone';
    }

    public function getSubheading(): string
    {
        $phone = $this->user()->phone;

        return filled($phone)
            ? 'We sent a 6-digit code by SMS to '.self::mask($phone).'.'
            : 'Add your mobile number in your profile to receive a code.';
    }

    public function hasLogo(): bool
    {
        return true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill();

        $user = $this->user();
        $verifier = app(PhoneVerifier::class);

        if (filled($user->phone) && ! $verifier->hasPendingCode($user)) {
            $verifier->sendCode($user);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                OneTimeCodeInput::make('code')
                    ->label('Verification code')
                    ->length(6)
                    ->required(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('verify-phone')
                ->livewireSubmitHandler('verify')
                ->footer([
                    Actions::make([
                        Action::make('verify')
                            ->label('Verify')
                            ->submit('verify'),
                    ])
                        ->fullWidth()
                        ->key('verify-phone-actions'),
                ]),
            Actions::make([
                $this->resendAction(),
                Action::make('changeNumber')
                    ->label('Change number')
                    ->link()
                    ->color('gray')
                    ->url(fn (): string => PersonalProfile::getUrl(panel: 'app')),
            ])
                ->alignment(Alignment::Center)
                ->key('verify-phone-secondary-actions'),
            Text::make('Didn\'t get a code? It can take up to a minute to arrive.')
                ->color('gray'),
        ]);
    }

    public function verify(): void
    {
        abort_unless(static::canAccess(), 403);

        $code = (string) $this->form->getState()['code'];
        $user = $this->user();

        if (! app(PhoneVerifier::class)->verify($user, $code)) {
            throw ValidationException::withMessages([
                'data.code' => 'The code is invalid or expired.',
            ]);
        }

        $user->forceFill(['phone_verified_at' => now()])->save();

        Notification::make()->title('Phone number verified')->success()->send();

        $this->redirect(PersonalDashboard::getUrl(panel: 'app'));
    }

    public function resendAction(): Action
    {
        return Action::make('resend')
            ->label('Send a new code')
            ->link()
            ->action(function (): void {
                $user = $this->user();
                abort_unless(static::canAccess() && filled($user->phone), 403);

                $cooldown = (int) config('settlo.phone_verification.resend_cooldown_seconds', 60);
                $key = 'phone-otp-resend:'.$user->getKey();

                $sent = RateLimiter::attempt(
                    $key,
                    1,
                    fn () => app(PhoneVerifier::class)->sendCode($user),
                    $cooldown,
                );

                if ($sent === false) {
                    Notification::make()
                        ->title('Please wait before requesting a new code')
                        ->body('You can request another code in '.RateLimiter::availableIn($key).' seconds.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()->title('A new code is on its way')->success()->send();
            });
    }

    /**
     * "+41 79 *** ** 67": only the country code, the first group and the last
     * group stay readable.
     */
    public static function mask(string $phone): string
    {
        try {
            $formatted = (new PhoneNumber($phone))->formatInternational();
        } catch (Throwable) {
            return Str::mask($phone, '*', 3, max(0, strlen($phone) - 5));
        }

        $parts = explode(' ', $formatted);

        if (count($parts) < 3) {
            return Str::mask($formatted, '*', 4, max(0, strlen($formatted) - 6));
        }

        if (count($parts) === 3) {
            $parts[2] = Str::mask($parts[2], '*', 0, max(0, strlen($parts[2]) - 2));
        }

        foreach (array_slice(array_keys($parts), 2, -1) as $index) {
            $parts[$index] = (string) preg_replace('/\d/', '*', $parts[$index]);
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getLayoutData(): array
    {
        return [
            'hasTopbar' => $this->hasTopbar(),
            'maxContentWidth' => $maxContentWidth = $this->getMaxWidth() ?? $this->getMaxContentWidth(),
            'maxWidth' => $maxContentWidth,
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
