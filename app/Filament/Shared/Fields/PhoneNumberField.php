<?php

namespace App\Filament\Shared\Fields;

use App\Filament\Support\ValidatesOnBlur;
use App\Rules\MobilePhoneNumber;
use App\Support\PhoneCountries;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;

/**
 * A mobile phone number with a country dial-code picker. The number is
 * validated for the selected country (mobile numbers only) and stored in
 * E.164 form in `phone`; the country goes to `phone_country`.
 *
 * The number is optional by default — landline-only owners must still be able
 * to register — and only becomes mandatory when SMS verification is switched
 * on, since that flow has nothing to send a code to otherwise.
 */
final class PhoneNumberField
{
    public static function make(?bool $required = null): FusedGroup
    {
        $required ??= self::isMandatory();

        return FusedGroup::make([
            Select::make('phone_country')
                ->hiddenLabel()
                ->options(fn (): array => PhoneCountries::options())
                ->default('CH')
                ->searchable()
                ->selectablePlaceholder(false)
                ->required()
                ->validationAttribute('country code')
                ->live()
                ->afterStateUpdated(function (LivewireComponent $livewire, Select $component, Get $get): void {
                    if (filled($get('phone'))) {
                        self::revalidatePhone($livewire, str($component->getStatePath())->beforeLast('.')->append('.phone')->toString());
                    }
                })
                ->columnSpan(1),
            TextInput::make('phone')
                ->hiddenLabel()
                ->tel()
                ->required($required)
                ->maxLength(30)
                ->validationAttribute('mobile phone')
                ->placeholder(fn (Get $get): string => PhoneCountries::example(self::country($get)))
                ->rule(fn (Get $get): MobilePhoneNumber => new MobilePhoneNumber(self::country($get)))
                ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => MobilePhoneNumber::toE164($state, self::country($get)))
                ->formatStateUsing(fn (?string $state): ?string => MobilePhoneNumber::toNational($state))
                ->autocomplete('tel-national')
                ->tap(new ValidatesOnBlur)
                ->columnSpan(['default' => 1, 'sm' => 2]),
        ])
            ->label($required ? 'Mobile phone' : 'Mobile phone (optional)')
            ->columns(['default' => 1, 'sm' => 3]);
    }

    /**
     * Whether a number must be given: only while SMS verification is enabled.
     */
    public static function isMandatory(): bool
    {
        return (bool) config('settlo.phone_verification.enabled', false);
    }

    /**
     * Re-check the number for the newly selected country. Errors are added to
     * the bag instead of thrown, so other state updates of the same request
     * (e.g. the number itself) are still applied.
     */
    private static function revalidatePhone(LivewireComponent $livewire, string $statePath): void
    {
        $livewire->resetErrorBag($statePath);

        try {
            $livewire->validateOnly($statePath);
        } catch (ValidationException $exception) {
            foreach ($exception->errors()[$statePath] ?? [] as $message) {
                $livewire->addError($statePath, $message);
            }
        }
    }

    private static function country(Get $get): string
    {
        $country = strtoupper((string) ($get('phone_country') ?: 'CH'));

        return PhoneCountries::isSupported($country) ? $country : 'CH';
    }
}
