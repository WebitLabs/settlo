<?php

namespace App\Filament\Personal\Pages;

use App\Filament\Shared\Fields\PhoneNumberField;
use App\Filament\Shared\Fields\SwissAddressFields;
use App\Filament\Support\ValidatesOnBlur;
use App\Http\Middleware\EnsurePhoneIsVerified;
use App\Jobs\RecalculatePersonalTaxEstimation;
use App\Models\User;
use App\Services\Phone\PhoneVerifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Propaganistas\LaravelPhone\PhoneNumber;
use UnitEnum;

/**
 * The owner's personal details, home address and password, each in its own
 * form so a validation error in one never blocks saving another. Changing the
 * mobile number resets its verification and replaces any pending one-time code
 * with a fresh one; saving the address recalculates the personal tax estimate.
 *
 * @property-read Schema $profileForm
 * @property-read Schema $addressForm
 * @property-read Schema $passwordForm
 */
class PersonalProfile extends Page
{
    protected static ?string $slug = 'profile';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static ?string $navigationLabel = 'Profile';

    protected static ?int $navigationSort = 1;

    /**
     * @var list<string>
     */
    private const array PROFILE_FIELDS = ['first_name', 'last_name', 'phone', 'phone_country', 'preferred_language'];

    /**
     * @var list<string>
     */
    private const array ADDRESS_FIELDS = ['street', 'street_number', 'postal_code', 'city'];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $profileData = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $addressData = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $passwordData = [];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->isOwner() ?? false;
    }

    public function getTitle(): string
    {
        return 'Personal profile';
    }

    public function mount(): void
    {
        $user = $this->user();

        $this->profileForm->fill([
            ...$user->only(self::PROFILE_FIELDS),
            'email' => $user->email,
            'phone_country' => $user->phone_country
                ?: rescue(fn (): ?string => filled($user->phone) ? (new PhoneNumber($user->phone))->getCountry() : null, report: false)
                ?: 'CH',
        ]);

        $this->addressForm->fill([
            ...$user->only([...self::ADDRESS_FIELDS, 'canton_id', 'commune_id']),
            'country_code' => $user->country_code ?: 'CH',
        ]);

        $this->passwordForm->fill();
    }

    public function profileForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('profileData')
            ->columns(['default' => 1, 'md' => 2])
            ->components([
                TextInput::make('first_name')->label('First name')->required()->maxLength(255)->autocomplete('given-name')->tap(new ValidatesOnBlur),
                TextInput::make('last_name')->label('Last name')->required()->maxLength(255)->autocomplete('family-name')->tap(new ValidatesOnBlur),
                TextInput::make('email')
                    ->label('Email address')
                    ->disabled()
                    ->dehydrated(false)
                    ->hint('Contact support to change your email address')
                    ->columnSpanFull(),
                PhoneNumberField::make()
                    ->columnSpanFull(),
                // English-only interface: the picker is hidden, the stored
                // value is preserved and still drives the invoice templates.
                Hidden::make('preferred_language'),
            ]);
    }

    public function addressForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('addressData')
            ->columns(['default' => 1, 'md' => 2])
            ->components([
                ...SwissAddressFields::make(withCommune: true, required: true),
                TextInput::make('country_code')
                    ->label('Country')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn (): string => 'CH'),
            ]);
    }

    public function passwordForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('passwordData')
            ->columns(['default' => 1, 'md' => 2])
            ->components([
                TextInput::make('current_password')
                    ->label('Current password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule('current_password:'.Filament::getAuthGuard())
                    ->autocomplete('current-password')
                    ->columnSpanFull(),
                TextInput::make('password')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->autocomplete('new-password'),
                TextInput::make('password_confirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->same('password')
                    ->autocomplete('new-password'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Personal details')
                ->schema([$this->formBlock('profileForm', 'saveProfile', 'Save details')]),
            Section::make('Home address')
                ->description('Your place of residence determines where you pay income tax.')
                ->schema([$this->formBlock('addressForm', 'saveAddress', 'Save address')]),
            Section::make('Password')
                ->schema([$this->formBlock('passwordForm', 'updatePassword', 'Update password')]),
        ]);
    }

    public function saveProfile(): void
    {
        $user = $this->user();

        $user->fill(Arr::only($this->profileForm->getState(), self::PROFILE_FIELDS));

        $phoneChanged = $user->isDirty('phone');

        if ($phoneChanged) {
            $user->forceFill(['phone_verified_at' => null]);
        }

        $user->save();

        Notification::make()->title('Profile saved')->success()->send();

        if (! $phoneChanged) {
            return;
        }

        $verifier = app(PhoneVerifier::class);
        $verifier->forget($user);

        if (EnsurePhoneIsVerified::mustVerify($user)) {
            if (filled($user->phone)) {
                $verifier->sendCode($user);
            }

            $this->redirect(VerifyPhone::getUrl());
        }
    }

    public function saveAddress(): void
    {
        $user = $this->user();
        $data = $this->addressForm->getState();

        $user->fill(Arr::only($data, self::ADDRESS_FIELDS));
        $user->forceFill([
            'canton_id' => $data['canton_id'] ?? null,
            'commune_id' => $data['commune_id'] ?? null,
            'country_code' => 'CH',
        ])->save();

        $profile = $user->taxProfile;

        if ($profile !== null && $profile->canton_id === null) {
            $profile->forceFill([
                'canton_id' => $user->canton_id,
                'commune_id' => $user->commune_id,
            ])->save();
        }

        RecalculatePersonalTaxEstimation::dispatch($user->getKey());

        Notification::make()->title('Address saved')->success()->send();
    }

    public function updatePassword(): void
    {
        $user = $this->user();
        $data = $this->passwordForm->getState();

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        session()->put(['password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword()]);

        $this->passwordForm->fill();

        Notification::make()->title('Password updated')->success()->send();
    }

    private function formBlock(string $schemaName, string $handler, string $label): Form
    {
        return Form::make([EmbeddedSchema::make($schemaName)])
            ->id($schemaName)
            ->livewireSubmitHandler($handler)
            ->footer([
                Actions::make([
                    Action::make($handler)
                        ->label($label)
                        ->submit($handler),
                ])
                    ->alignment('end')
                    ->key("{$schemaName}-actions"),
            ]);
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User && $user->isOwner(), 403);

        return $user;
    }
}
