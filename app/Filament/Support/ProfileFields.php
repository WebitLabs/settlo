<?php

namespace App\Filament\Support;

use App\Enums\BusinessEntityType;
use App\Enums\ResidencePermit;
use App\Filament\Shared\Fields\CommuneSelect;
use App\Rules\SwissUid;
use App\Services\Tax\RateRepository;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Field builders shared by the business onboarding wizard and the business
 * settings page, so both forms validate and explain the same fields the same way.
 */
final class ProfileFields
{
    /** Pillar 3a cap without a pension fund, used when no rates are seeded. */
    private const int FALLBACK_PILLAR_3A_CAP = 35280;

    /** Pillar 3a cap with a pension fund, used when no rates are seeded. */
    private const int FALLBACK_PILLAR_3A_CAP_WITH_PILLAR_2 = 7056;

    /**
     * Business types offered in the picker; unsupported ones are shown but
     * disabled with a "Coming soon" badge.
     *
     * @var array<int, BusinessEntityType>
     */
    private const array OFFERED_BUSINESS_TYPES = [
        BusinessEntityType::SoleProprietorship,
        BusinessEntityType::GmbH,
        BusinessEntityType::AG,
    ];

    public static function businessType(): Select
    {
        return Select::make('type')
            ->label('Business type')
            ->allowHtml()
            ->native(false)
            ->options(fn (): array => collect(self::OFFERED_BUSINESS_TYPES)
                ->mapWithKeys(fn (BusinessEntityType $type): array => [$type->value => $type->isSupported()
                    ? e($type->getLabel())
                    : e($type->getLabel()).' <span class="ms-2 rounded-full border border-danger-400 px-2 py-0.5 text-xs font-medium text-danger-600 dark:text-danger-400">Coming soon</span>'])
                ->all())
            ->disableOptionWhen(fn (string $value): bool => ! (BusinessEntityType::tryFrom($value)?->isSupported() ?? false))
            ->default(BusinessEntityType::SoleProprietorship->value)
            ->selectablePlaceholder(false)
            ->required();
    }

    public static function tradingName(): TextInput
    {
        return TextInput::make('legal_name')
            ->label('Trading name')
            ->maxLength(255)
            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'The name you use with clients, if different from your registered business name. Optional.')
            ->tap(new ValidatesOnBlur);
    }

    public static function uid(): TextInput
    {
        return TextInput::make('uid')
            ->label('Swiss business registration number (UID)')
            ->helperText('Optional if your business is not registered in the Swiss Commercial Register yet.')
            ->placeholder('CHE-123.456.789')
            ->mask('CHE-999.999.999')
            ->rule(new SwissUid)
            ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : SwissUid::normalize($state))
            ->tap(new ValidatesOnBlur(debounceMs: 500));
    }

    public static function paymentTerms(): TextInput
    {
        return TextInput::make('default_payment_term_days')
            ->label('Payment terms')
            ->required()
            ->integer()
            ->minValue(1)
            ->maxValue(365)
            ->suffix('days')
            ->default(30)
            ->datalist(['10', '15', '30', '45', '60', '90'])
            ->tap(new ValidatesOnBlur);
    }

    public static function iban(): TextInput
    {
        return TextInput::make('iban')
            ->label('IBAN')
            ->placeholder('CH93 0076 2011 6238 5295 7')
            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Your IBAN generates the Swiss QR-bill on every invoice automatically.');
    }

    public static function numberOfChildren(): TextInput
    {
        return TextInput::make('number_of_children')
            ->label('Dependent children')
            ->integer()
            ->minValue(0)
            ->maxValue(10)
            ->default(0)
            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Each child reduces your taxable income by the cantonal child deduction (CHF 6,500–9,000).')
            ->tap(new ValidatesOnBlur);
    }

    public static function residenceStatus(): Select
    {
        return Select::make('residence_permit')
            ->label('Residence status')
            ->options(ResidencePermit::class)
            ->default(ResidencePermit::SwissCitizen->value)
            ->selectablePlaceholder(false)
            ->required()
            ->live();
    }

    public static function quellensteuerWarning(): TextEntry
    {
        return TextEntry::make('quellensteuer_warning')
            ->hiddenLabel()
            ->columnSpanFull()
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->state(ResidencePermit::QUELLENSTEUER_WARNING)
            ->visible(fn (Get $get): bool => self::residenceStatusFrom($get('residence_permit'))?->triggersQuellensteuer() ?? false);
    }

    public static function commune(string $cantonField, bool $requiredWhenAvailable = false): Select
    {
        return CommuneSelect::make('commune_id', cantonField: $cantonField, requiredWhenAvailable: $requiredWhenAvailable);
    }

    public static function kirchensteuer(): Toggle
    {
        return Toggle::make('kirchensteuer')
            ->label('Kirchensteuer (church tax)')
            ->default(false)
            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'About 8–15 % surcharge on cantonal tax for registered church members.');
    }

    public static function hasPillar2(): Toggle
    {
        return Toggle::make('has_pillar2')
            ->label('I pay into a pension fund (Pillar 2)')
            ->default(false)
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set): bool => self::clampPillar3a($get, $set));
    }

    public static function pillar3aAmount(): TextInput
    {
        return TextInput::make('pillar3a_amount')
            ->label('Pillar 3a contributions / year')
            ->numeric()
            ->minValue(0)
            ->default(0)
            ->prefix('CHF')
            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: fn (): string => self::pillar3aTooltip())
            ->afterStateUpdated(fn (Get $get, Set $set): bool => self::clampPillar3a($get, $set))
            ->tap(new ValidatesOnBlur);
    }

    /**
     * The legal Pillar 3a maximum for the current fiscal year.
     */
    public static function pillar3aCap(bool $hasPillar2): int
    {
        try {
            return app(RateRepository::class)->pillar3aCap($hasPillar2, (int) config('settlo.current_fiscal_year', now()->year));
        } catch (Throwable) {
            return $hasPillar2 ? self::FALLBACK_PILLAR_3A_CAP_WITH_PILLAR_2 : self::FALLBACK_PILLAR_3A_CAP;
        }
    }

    /**
     * Explains both caps with the current year's rates. Without a pension fund
     * the deductible amount also depends on income, which the tax estimate
     * applies; the form can only enforce the absolute maximum.
     */
    public static function pillar3aTooltip(): string
    {
        return sprintf(
            'Without a pension fund: up to 20 %% of your net self-employment income, max CHF %s per year. With one: max CHF %s per year (%d). Amounts above the maximum are reduced automatically; the income limit is applied in your tax estimate.',
            self::chf(self::pillar3aCap(false)),
            self::chf(self::pillar3aCap(true)),
            (int) config('settlo.current_fiscal_year', now()->year),
        );
    }

    /**
     * Server-side clamp applied when saving. Without a pension fund this is
     * the absolute maximum; the 20 %-of-income limit is applied by the tax
     * calculator, which knows the income.
     */
    public static function clampedPillar3a(mixed $amount, bool $hasPillar2): float
    {
        return min(max(0.0, (float) $amount), (float) self::pillar3aCap($hasPillar2));
    }

    /**
     * Reduce the Pillar 3a field to the legal cap and tell the user. Returns
     * whether the value was changed.
     */
    private static function clampPillar3a(Get $get, Set $set): bool
    {
        $amount = $get('pillar3a_amount');
        $cap = self::pillar3aCap((bool) $get('has_pillar2'));

        if (! is_numeric($amount) || (float) $amount <= $cap) {
            return false;
        }

        $set('pillar3a_amount', $cap);

        Notification::make()
            ->title('Pillar 3a is capped at CHF '.self::chf($cap))
            ->body((bool) $get('has_pillar2')
                ? 'We reduced the amount to the legal maximum.'
                : 'We reduced the amount to the legal maximum. Your tax estimate also limits it to 20 % of your net self-employment income.')
            ->info()
            ->send();

        return true;
    }

    private static function chf(int $amount): string
    {
        return number_format($amount, 0, '.', "'");
    }

    private static function residenceStatusFrom(mixed $state): ?ResidencePermit
    {
        return $state instanceof ResidencePermit ? $state : ResidencePermit::tryFrom((string) $state);
    }
}
