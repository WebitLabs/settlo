<?php

namespace App\Filament\App\Tenancy;

use App\Enums\BusinessEntityType;
use App\Enums\Language;
use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Enums\VatStatus;
use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\Commune;
use App\Models\Plan;
use App\Models\TaxProfile;
use App\Models\User;
use App\Rules\ValidIban;
use App\Services\Billing\SubscriptionService;
use App\Services\Tax\TaxEngine;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The ~30-second onboarding shown to an owner with no business yet. A four-step
 * wizard collects the business profile, banking defaults, tax profile and plan,
 * then in a single (framework-wrapped) transaction provisions the entity, tax
 * profile, optional default bank account and trial subscription, marks
 * onboarding complete and kicks off the first tax estimate.
 */
class RegisterBusinessEntity extends RegisterTenant
{
    /**
     * Cap for annual Pillar 3a contributions (2026); anything above is silently
     * clamped since only this much is deductible.
     */
    private const PILLAR_3A_CAP = 35280;

    public static function getLabel(): string
    {
        return 'Set up your business';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    $this->businessProfileStep(),
                    $this->bankingStep(),
                    $this->taxProfileStep(),
                    $this->planStep(),
                ])
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button type="submit" size="sm">Start free trial</x-filament::button>'
                    ))),
            ])
            ->statePath('data');
    }

    /**
     * The wizard renders its own submit button on the final step, so the page's
     * footer form action is suppressed to avoid a duplicate submit control.
     *
     * @return array<mixed>
     */
    protected function getFormActions(): array
    {
        return [];
    }

    private function businessProfileStep(): Step
    {
        return Step::make('Business profile')
            ->icon('heroicon-o-building-office')
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label('Business name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('legal_name')
                    ->label('Legal name (optional)')
                    ->maxLength(255),
                Select::make('type')
                    ->label('Business type')
                    ->options(BusinessEntityType::class)
                    ->default(BusinessEntityType::SoleProprietorship->value)
                    ->disableOptionWhen(fn (string $value): bool => $value !== BusinessEntityType::SoleProprietorship->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->helperText('Only sole proprietorships are supported for now.'),
                TextInput::make('uid')
                    ->label('UID (optional)')
                    ->placeholder('CHE-123.456.789')
                    ->rule('regex:/^CHE-\d{3}\.\d{3}\.\d{3}$/')
                    ->helperText('Format: CHE-XXX.XXX.XXX'),
                TextInput::make('street')
                    ->label('Street')
                    ->maxLength(255)
                    ->columnSpan(1),
                TextInput::make('street_number')
                    ->label('No.')
                    ->maxLength(50),
                TextInput::make('postal_code')
                    ->label('Postal code')
                    ->maxLength(20),
                TextInput::make('city')
                    ->label('City')
                    ->maxLength(255),
                Select::make('canton_id')
                    ->label('Canton')
                    ->options(fn (): array => self::cantonOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->columnSpanFull(),
            ]);
    }

    private function bankingStep(): Step
    {
        return Step::make('Banking')
            ->icon('heroicon-o-banknotes')
            ->columns(2)
            ->schema([
                TextInput::make('iban')
                    ->label('IBAN')
                    ->required()
                    ->rule(new ValidIban)
                    ->columnSpanFull()
                    ->helperText('Your IBAN generates the Swiss QR-bill on every invoice automatically.'),
                Select::make('default_payment_term_days')
                    ->label('Payment terms')
                    ->options([15 => '15 days', 30 => '30 days', 60 => '60 days'])
                    ->default(30)
                    ->selectablePlaceholder(false)
                    ->required(),
                Select::make('default_language')
                    ->label('Invoice language')
                    ->options(Language::class)
                    ->default('en')
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('invoice_number_prefix')
                    ->label('Invoice number prefix')
                    ->default('INV-')
                    ->required()
                    ->maxLength(20),
            ]);
    }

    private function taxProfileStep(): Step
    {
        return Step::make('Tax profile')
            ->icon('heroicon-o-calculator')
            ->columns(2)
            ->schema([
                Placeholder::make('canton_display')
                    ->label('Canton')
                    ->content(fn (Get $get): string => optional(Canton::find($get('canton_id')))->name_en ?? 'Choose a canton in step 1')
                    ->helperText('Prefilled from your business address.'),
                Select::make('commune_id')
                    ->label('Commune')
                    ->options(fn (Get $get): array => $get('canton_id')
                        ? Commune::where('canton_id', $get('canton_id'))->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->searchable(),
                Select::make('marital_status')
                    ->label('Marital status')
                    ->options(MaritalStatus::class)
                    ->default(MaritalStatus::Single->value)
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('number_of_children')
                    ->label('Dependent children')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(10)
                    ->default(0)
                    ->helperText('CHF 6,500–9,000 deduction per child, varies by canton.'),
                Select::make('residence_permit')
                    ->label('Residence permit')
                    ->options(ResidencePermit::class)
                    ->default(ResidencePermit::SwissOrCPermit->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(),
                Placeholder::make('quellensteuer_warning')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('residence_permit') === ResidencePermit::BPermit->value)
                    ->content(new HtmlString(
                        '<span class="text-sm text-warning-600 dark:text-warning-400">Quellensteuer regime — your tax calculation will be handled by your accountant.</span>'
                    )),
                TextInput::make('pillar3a_amount')
                    ->label('Pillar 3a contributions / year')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->prefix('CHF')
                    ->helperText('Max CHF 35,280 · Fully deductible. Amounts above are capped automatically.'),
                Toggle::make('kirchensteuer')
                    ->label('Kirchensteuer (church tax)')
                    ->default(false)
                    ->helperText('~8–15% surcharge on cantonal tax if you are a registered church member.'),
                Select::make('vat_status')
                    ->label('VAT status')
                    ->options(VatStatus::class)
                    ->default(VatStatus::NotRegistered->value)
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('estimated_annual_revenue')
                    ->label('Estimated annual revenue')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('CHF'),
                TextInput::make('other_income')
                    ->label('Other income')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->prefix('CHF'),
            ]);
    }

    private function planStep(): Step
    {
        return Step::make('Choose your plan')
            ->icon('heroicon-o-sparkles')
            ->schema([
                Radio::make('plan_id')
                    ->hiddenLabel()
                    ->options(fn (): array => self::activePlans()->pluck('name', 'id')->all())
                    ->descriptions(fn (): array => self::activePlans()
                        ->mapWithKeys(fn (Plan $plan): array => [
                            $plan->getKey() => 'CHF '.(int) $plan->price_monthly.'/mo · '
                                .implode(' · ', $plan->marketing_features ?? []),
                        ])
                        ->all())
                    ->default(fn (): ?string => Plan::where('code', 'pro')->value('id'))
                    ->required(),
                Placeholder::make('trial_note')
                    ->hiddenLabel()
                    ->content('No credit card required · Cancel anytime · 14 days free on any plan.'),
            ]);
    }

    /**
     * Provision everything a new tenant needs. Runs inside the transaction the
     * base RegisterTenant::register() opens, so a failure in any step rolls the
     * whole onboarding back. The tax estimate is best-effort and never aborts it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        $entity = new BusinessEntity;
        $entity->fill(Arr::only($data, [
            'name', 'legal_name', 'type', 'uid', 'street', 'street_number',
            'city', 'postal_code', 'canton_id', 'default_payment_term_days',
            'default_language', 'invoice_number_prefix',
        ]));
        $entity->forceFill([
            'owner_id' => $user->getKey(),
            'iban' => ValidIban::normalize((string) ($data['iban'] ?? '')),
        ]);
        $entity->save();

        $taxProfile = new TaxProfile;
        $taxProfile->fill([
            ...Arr::only($data, [
                'commune_id', 'vat_status', 'marital_status', 'number_of_children',
                'residence_permit', 'kirchensteuer', 'estimated_annual_revenue', 'other_income',
            ]),
            'canton_id' => $data['canton_id'] ?? null,
            'pillar3a_amount' => min((float) ($data['pillar3a_amount'] ?? 0), self::PILLAR_3A_CAP),
        ]);
        $taxProfile->forceFill(['business_entity_id' => $entity->getKey()]);
        $taxProfile->save();

        if (! empty($data['iban'])) {
            $bankAccount = new BankAccount;
            $bankAccount->fill([
                'bank_name' => 'Primary account',
                'account_name' => $entity->name,
                'currency_code' => $entity->default_currency ?? 'CHF',
            ]);
            $bankAccount->forceFill([
                'business_entity_id' => $entity->getKey(),
                'iban' => ValidIban::normalize((string) $data['iban']),
                'is_default' => true,
            ])->save();
        }

        $plan = Plan::findOrFail($data['plan_id']);
        app(SubscriptionService::class)->startTrial($user, $plan);

        $user->forceFill(['onboarding_completed_at' => now()])->save();

        try {
            app(TaxEngine::class)->estimateFor($entity);
        } catch (Throwable) {
            // Best-effort: an estimation failure must not block onboarding.
        }

        return $entity;
    }

    /**
     * @return array<string, string>
     */
    private static function cantonOptions(): array
    {
        return Canton::orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (Canton $canton): array => [
                $canton->getKey() => "{$canton->code} — {$canton->name_en}",
            ])
            ->all();
    }

    /**
     * @return Collection<int, Plan>
     */
    private static function activePlans(): Collection
    {
        return Plan::where('is_active', true)->orderBy('sort_order')->get();
    }
}
