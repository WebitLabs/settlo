<?php

namespace App\Filament\Shared\Schemas;

use App\Enums\BusinessEntityType;
use App\Enums\VatStatus;
use App\Filament\Shared\Fields\SwissAddressFields;
use App\Filament\Support\ProfileFields;
use App\Filament\Support\ValidatesOnBlur;
use App\Models\Canton;
use App\Rules\SwissUid;
use App\Rules\SwissVatNumber;
use App\Services\Registry\UidRegister;
use App\Services\Registry\UidRegisterUnavailable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

/**
 * The business profile fields (UID, type, names, address, VAT), shared by the
 * "Set up a business" stepper and the workspace's business settings.
 */
final class BusinessProfileFields
{
    /**
     * The business attributes these fields write (canton_id is written with
     * forceFill by the callers).
     *
     * @var list<string>
     */
    public const array ATTRIBUTES = [
        'uid', 'type', 'name', 'legal_name', 'street', 'street_number', 'postal_code',
        'city', 'canton_id', 'vat_status', 'mwst_number', 'estimated_annual_revenue',
    ];

    /**
     * @param  bool  $withUidLookup  Offer (and run, once the UID is valid) the Swiss UID register lookup.
     * @param  int|null  $debounceMs  Validate while typing (instead of on blur) with this debounce.
     * @return array<Component|Field>
     */
    public static function components(bool $withUidLookup = false, ?int $debounceMs = null): array
    {
        $validates = new ValidatesOnBlur(debounceMs: $debounceMs);
        $retap = fn (Field $field): Field => $debounceMs === null ? $field : $field->tap($validates);

        return [
            $withUidLookup ? self::withUidLookup(ProfileFields::uid()) : ProfileFields::uid(),
            ProfileFields::businessType()
                ->rule(Rule::in([BusinessEntityType::SoleProprietorship->value])),
            TextInput::make('name')
                ->label('Business name')
                ->required()
                ->maxLength(255)
                ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Your registered business name. It appears on invoices and the QR-bill.')
                ->tap($validates),
            $retap(ProfileFields::tradingName()),
            ...SwissAddressFields::make(required: true, debounceMs: $debounceMs),
            Select::make('vat_status')
                ->label('VAT status')
                ->options(VatStatus::class)
                ->default(VatStatus::NotRegistered->value)
                ->selectablePlaceholder(false)
                ->required()
                ->live(),
            TextInput::make('mwst_number')
                ->label('VAT number')
                ->placeholder('CHE-123.456.789 MWST')
                ->visible(fn (Get $get): bool => self::vatStatusFrom($get('vat_status'))?->isRegistered() ?? false)
                ->required()
                ->rule(new SwissVatNumber)
                ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : SwissVatNumber::normalize($state))
                ->tap(new ValidatesOnBlur(debounceMs: $debounceMs ?? 500)),
            TextInput::make('estimated_annual_revenue')
                ->label('Estimated annual revenue')
                ->numeric()
                ->minValue(0)
                ->prefix('CHF')
                ->helperText('Helps us warn you before you reach the CHF 100,000 VAT threshold.')
                ->tap($validates),
        ];
    }

    /**
     * Fill the business name, type, address and VAT registration from the
     * Swiss UID register and tell the user what happened.
     */
    public static function fillFromRegister(Get $get, Set $set): void
    {
        $uid = (string) $get('uid');

        if (! SwissUid::isValid($uid)) {
            Notification::make()
                ->title('Enter a valid UID first, e.g. CHE-123.456.788.')
                ->warning()
                ->send();

            return;
        }

        try {
            $record = app(UidRegister::class)->lookup($uid, Filament::auth()->id());
        } catch (UidRegisterUnavailable $exception) {
            Notification::make()
                ->title($exception->isRateLimited()
                    ? 'Too many UID lookups. Please wait a minute and try again.'
                    : "The UID register isn't reachable right now. Please fill in the details manually or try again later.")
                ->warning()
                ->send();

            return;
        }

        if ($record === null) {
            Notification::make()
                ->title("We couldn't find this UID. Please fill in the details manually.")
                ->warning()
                ->send();

            return;
        }

        $set('name', $record->registeredName());

        if (filled($record->legalName) && $record->name !== $record->legalName && blank($get('legal_name'))) {
            $set('legal_name', $record->name);
        }

        $businessType = $record->businessType();

        if ($businessType?->isSupported()) {
            $set('type', $businessType->value);
        }

        if (filled($record->street)) {
            $set('street', $record->street);
            $set('street_number', $record->houseNumber);
        }

        if (filled($record->postalCode)) {
            $set('postal_code', $record->postalCode);
        }

        if (filled($record->town)) {
            $set('city', $record->town);
        }

        if (filled($record->cantonCode)) {
            $set('canton_id', Canton::where('code', $record->cantonCode)->value('id'));
        }

        if ($record->isVatActive()) {
            $set('vat_status', VatStatus::RegisteredMandatory->value);
            $set('mwst_number', $record->vatNumber());
        }

        Notification::make()
            ->title('Filled from the UID register — please check the details.')
            ->success()
            ->send();

        if ($businessType === null || ! $businessType->isSupported()) {
            Notification::make()
                ->title(in_array($businessType, [BusinessEntityType::GmbH, BusinessEntityType::AG], true)
                    ? 'GmbH and AG are coming soon — you can continue as a sole proprietorship later.'
                    : 'Settlo currently supports sole proprietorships only.')
                ->warning()
                ->send();
        }
    }

    private static function withUidLookup(TextInput $uid): TextInput
    {
        return $uid
            ->suffixAction(
                Action::make('lookupUid')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->tooltip('Fill from the Swiss UID register')
                    ->action(fn (Get $get, Set $set): mixed => self::fillFromRegister($get, $set)),
            )
            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                if (filled($state) && SwissUid::isValid($state)) {
                    self::fillFromRegister($get, $set);
                }
            });
    }

    public static function vatStatusFrom(mixed $state): ?VatStatus
    {
        return $state instanceof VatStatus ? $state : VatStatus::tryFrom((string) $state);
    }
}
