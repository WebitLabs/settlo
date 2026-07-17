<?php

namespace App\Filament\App\Pages;

use App\Enums\BusinessEntityType;
use App\Enums\Language;
use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Enums\VatStatus;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\Commune;
use App\Models\TaxProfile;
use App\Rules\ValidIban;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Owner-only settings for the active tenant: business profile, invoicing
 * defaults and tax profile, edited in one tabbed form. Guarded columns
 * (owner_id, iban, business_entity_id) are written via forceFill; saving the
 * tax profile queues a tax-estimation recalculation.
 *
 * @property-read Schema $form
 */
class BusinessSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Business settings';

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    private const ENTITY_FIELDS = [
        'name', 'legal_name', 'type', 'uid', 'street', 'street_number', 'city',
        'postal_code', 'canton_id', 'default_payment_term_days', 'default_language',
        'invoice_number_prefix', 'default_invoice_notes', 'logo_url',
    ];

    private const TAX_FIELDS = [
        'canton_id', 'commune_id', 'vat_status', 'estimated_annual_revenue',
        'marital_status', 'number_of_children', 'residence_permit',
        'pillar3a_amount', 'kirchensteuer', 'other_income',
    ];

    private const PILLAR_3A_CAP = 35280;

    public function getTitle(): string
    {
        return 'Business settings';
    }

    /**
     * Owner-only: hidden in navigation and hard-denied for anyone who is not the
     * owner of the active tenant.
     */
    public static function canAccess(): bool
    {
        return self::currentOwnedEntity() !== null;
    }

    public function mount(): void
    {
        $entity = self::currentOwnedEntity();
        abort_unless($entity !== null, 403);

        $taxProfile = $entity->taxProfile;

        $this->form->fill([
            ...$entity->only(self::ENTITY_FIELDS),
            'default_currency' => $entity->default_currency,
            ...($taxProfile ? Arr::only($taxProfile->attributesToArray(), self::TAX_FIELDS) : []),
            'tax_canton_id' => $taxProfile?->canton_id ?? $entity->canton_id,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('Settings')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        $this->businessProfileTab(),
                        $this->invoicingTab(),
                        $this->taxProfileTab(),
                    ]),
            ]);
    }

    private function businessProfileTab(): Tab
    {
        return Tab::make('Business profile')
            ->icon('heroicon-o-building-office')
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label('Business name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('legal_name')
                    ->label('Legal name')
                    ->maxLength(255),
                Select::make('type')
                    ->label('Business type')
                    ->options(BusinessEntityType::class)
                    ->disableOptionWhen(fn (string $value): bool => $value !== BusinessEntityType::SoleProprietorship->value)
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('uid')
                    ->label('UID')
                    ->placeholder('CHE-123.456.789')
                    ->rule('regex:/^CHE-\d{3}\.\d{3}\.\d{3}$/')
                    ->helperText('Format: CHE-XXX.XXX.XXX'),
                TextInput::make('street')->label('Street')->maxLength(255),
                TextInput::make('street_number')->label('No.')->maxLength(50),
                TextInput::make('postal_code')->label('Postal code')->maxLength(20),
                TextInput::make('city')->label('City')->maxLength(255),
                Select::make('canton_id')
                    ->label('Canton')
                    ->options(fn (): array => self::cantonOptions())
                    ->searchable()
                    ->required(),
                FileUpload::make('logo_url')
                    ->label('Logo')
                    ->image()
                    ->disk('public')
                    ->visibility('public')
                    ->directory('logos')
                    ->maxSize(4096)
                    ->helperText('Shown on invoice PDFs. PNG or JPG.'),
            ]);
    }

    private function invoicingTab(): Tab
    {
        return Tab::make('Invoicing')
            ->icon('heroicon-o-document-text')
            ->columns(2)
            ->schema([
                TextInput::make('iban')
                    ->label('IBAN')
                    ->required()
                    ->rule(new ValidIban)
                    ->columnSpanFull()
                    ->helperText('Used to generate the Swiss QR-bill on every invoice.'),
                Select::make('default_payment_term_days')
                    ->label('Payment terms')
                    ->options([15 => '15 days', 30 => '30 days', 60 => '60 days'])
                    ->selectablePlaceholder(false)
                    ->required(),
                Select::make('default_language')
                    ->label('Invoice language')
                    ->options(Language::class)
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('invoice_number_prefix')
                    ->label('Invoice number prefix')
                    ->required()
                    ->maxLength(20),
                TextInput::make('default_currency')
                    ->label('Default currency')
                    ->default('CHF')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('default_invoice_notes')
                    ->label('Default invoice notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    private function taxProfileTab(): Tab
    {
        return Tab::make('Tax profile')
            ->icon('heroicon-o-calculator')
            ->columns(2)
            ->schema([
                Select::make('tax_canton_id')
                    ->label('Tax canton')
                    ->options(fn (): array => self::cantonOptions())
                    ->searchable()
                    ->required()
                    ->live(),
                Select::make('commune_id')
                    ->label('Commune')
                    ->options(fn (Get $get): array => $get('tax_canton_id')
                        ? Commune::where('canton_id', $get('tax_canton_id'))->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->searchable(),
                Select::make('marital_status')
                    ->label('Marital status')
                    ->options(MaritalStatus::class)
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('number_of_children')
                    ->label('Dependent children')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(10),
                Select::make('residence_permit')
                    ->label('Residence permit')
                    ->options(ResidencePermit::class)
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
                    ->prefix('CHF')
                    ->helperText('Max CHF 35,280 · Fully deductible. Amounts above are capped automatically.'),
                Toggle::make('kirchensteuer')
                    ->label('Kirchensteuer (church tax)'),
                Select::make('vat_status')
                    ->label('VAT status')
                    ->options(VatStatus::class)
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
                    ->prefix('CHF'),
            ]);
    }

    public function save(): void
    {
        $entity = self::currentOwnedEntity();
        abort_unless($entity !== null, 403);

        $data = $this->form->getState();

        $entity->fill(Arr::only($data, array_diff(self::ENTITY_FIELDS, ['canton_id'])));
        $entity->forceFill([
            'canton_id' => $data['canton_id'] ?? null,
            'iban' => ValidIban::normalize((string) ($data['iban'] ?? '')),
        ]);
        $entity->save();

        $taxProfile = $entity->taxProfile ?? new TaxProfile;
        $taxProfile->fill([
            ...Arr::only($data, [
                'commune_id', 'vat_status', 'estimated_annual_revenue', 'marital_status',
                'number_of_children', 'residence_permit', 'kirchensteuer', 'other_income',
            ]),
            'canton_id' => $data['tax_canton_id'] ?? null,
            'pillar3a_amount' => min((float) ($data['pillar3a_amount'] ?? 0), self::PILLAR_3A_CAP),
        ]);
        $taxProfile->forceFill(['business_entity_id' => $entity->getKey()])->save();

        RecalculateTaxEstimation::dispatch($entity->getKey());

        Notification::make()->title('Settings saved')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment('end')
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    private static function currentOwnedEntity(): ?BusinessEntity
    {
        $entity = Filament::getTenant();

        if (! $entity instanceof BusinessEntity) {
            return null;
        }

        return $entity->owner_id === Filament::auth()->id() ? $entity : null;
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
}
