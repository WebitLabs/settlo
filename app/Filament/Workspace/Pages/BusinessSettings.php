<?php

namespace App\Filament\Workspace\Pages;

use App\Filament\Personal\Pages\EditTaxProfile;
use App\Filament\Shared\Schemas\BusinessProfileFields;
use App\Filament\Shared\Schemas\InvoicingDefaultsFields;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Rules\ValidIban;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use UnitEnum;

/**
 * Owner-only settings for the active workspace: business profile (including
 * VAT status) and invoicing defaults. Each tab is its own form with its own
 * save button, so a validation error on one tab never blocks saving another.
 * Guarded columns (owner_id, iban) are written via forceFill; saving the
 * profile queues a tax recalculation. The tax profile is personal and edited
 * in the personal area (EditTaxProfile). A read-only workspace (subscription
 * ended) cannot be saved.
 *
 * @property-read Schema $profileForm
 * @property-read Schema $invoicingForm
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
    public ?array $profileData = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $invoicingData = [];

    /**
     * Query-string value (`?tab=`) that opens the Invoicing tab.
     */
    public const string INVOICING_TAB = 'invoicing::tab';

    private const PROFILE_FIELDS = [
        'name', 'legal_name', 'type', 'uid', 'street', 'street_number', 'city',
        'postal_code', 'canton_id', 'logo_url', 'vat_status', 'mwst_number', 'estimated_annual_revenue',
    ];

    private const INVOICING_FIELDS = [
        'default_payment_term_days', 'default_language', 'invoice_number_prefix', 'default_invoice_notes',
    ];

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

        $this->profileForm->fill($entity->only(self::PROFILE_FIELDS));

        $this->invoicingForm->fill([
            ...$entity->only(self::INVOICING_FIELDS),
            'iban' => ValidIban::format((string) $entity->iban),
            'default_currency' => $entity->default_currency,
        ]);
    }

    public function profileForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('profileData')
            ->columns(2)
            ->components($this->businessProfileFields());
    }

    public function invoicingForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('invoicingData')
            ->columns(2)
            ->components($this->invoicingFields());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Settings')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Business profile')
                        ->icon(Heroicon::OutlinedBuildingOffice)
                        ->schema([$this->formBlock('profileForm', 'saveProfile')]),
                    Tab::make('Invoicing')
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->schema([$this->formBlock('invoicingForm', 'saveInvoicing')]),
                    Tab::make('Tax profile')
                        ->icon(Heroicon::OutlinedCalculator)
                        ->schema([
                            Callout::make('Your tax profile is personal')
                                ->description('Your canton of residence, family situation and pension contributions apply to all of your businesses, so they are kept in your personal tax profile.')
                                ->info()
                                ->actions([
                                    Action::make('openTaxProfile')
                                        ->label('Open tax profile')
                                        ->url(fn (): string => EditTaxProfile::getUrl(panel: 'app')),
                                ]),
                        ]),
                ]),
        ]);
    }

    private function formBlock(string $schemaName, string $handler): Form
    {
        return Form::make([EmbeddedSchema::make($schemaName)])
            ->id($schemaName)
            ->livewireSubmitHandler($handler)
            ->footer([
                Actions::make([
                    Action::make($handler)
                        ->label('Save changes')
                        ->submit($handler)
                        ->disabled(fn (): bool => ! self::canWriteCurrentEntity())
                        ->tooltip(fn (): ?string => self::canWriteCurrentEntity()
                            ? null
                            : 'This business is read-only until you choose a plan.'),
                ])
                    ->alignment('end')
                    ->key("{$schemaName}-actions"),
            ]);
    }

    /**
     * @return array<Component|Field>
     */
    private function businessProfileFields(): array
    {
        return [
            ...BusinessProfileFields::components(),
            FileUpload::make('logo_url')
                ->label('Logo')
                ->image()
                ->disk('public')
                ->visibility('public')
                ->directory('logos')
                ->maxSize(4096)
                ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Shown on invoice PDFs. PNG or JPG.'),
        ];
    }

    /**
     * @return array<Component|Field>
     */
    private function invoicingFields(): array
    {
        $fields = InvoicingDefaultsFields::components(withInvoiceNotes: true);

        array_splice($fields, -1, 0, [
            TextInput::make('default_currency')
                ->label('Default currency')
                ->default('CHF')
                ->disabled()
                ->dehydrated(false),
        ]);

        return $fields;
    }

    public function saveProfile(): void
    {
        $entity = self::currentOwnedEntity();
        abort_unless($entity !== null, 403);

        if (! $this->ensureWritable($entity)) {
            return;
        }

        $data = $this->profileForm->getState();

        $data['mwst_number'] = (BusinessProfileFields::vatStatusFrom($data['vat_status'] ?? null)?->isRegistered() ?? false)
            ? ($data['mwst_number'] ?? null)
            : null;

        $entity->fill(Arr::only($data, array_diff(self::PROFILE_FIELDS, ['canton_id'])));
        $entity->forceFill(['canton_id' => $data['canton_id'] ?? null]);
        $entity->save();

        RecalculateTaxEstimation::dispatch($entity->getKey());

        Notification::make()->title('Business profile saved')->success()->send();
    }

    public function saveInvoicing(): void
    {
        $entity = self::currentOwnedEntity();
        abort_unless($entity !== null, 403);

        if (! $this->ensureWritable($entity)) {
            return;
        }

        $data = $this->invoicingForm->getState();

        $oldIban = (string) $entity->iban;
        $newIban = ValidIban::normalize((string) ($data['iban'] ?? ''));

        $entity->fill(Arr::only($data, self::INVOICING_FIELDS));
        $entity->forceFill(['iban' => $newIban]);
        $entity->save();

        if ($oldIban !== '' && $oldIban !== $newIban) {
            $entity->bankAccounts()
                ->where('is_default', true)
                ->where('iban', $oldIban)
                ->update(['iban' => $newIban]);
        }

        $this->invoicingForm->fill([
            ...$entity->only(self::INVOICING_FIELDS),
            'iban' => ValidIban::format($newIban),
            'default_currency' => $entity->default_currency,
        ]);

        Notification::make()->title('Invoicing settings saved')->success()->send();
    }

    /**
     * Refuse a save in a read-only workspace, telling the owner why.
     */
    private function ensureWritable(BusinessEntity $entity): bool
    {
        if ($entity->canWrite()) {
            return true;
        }

        Notification::make()
            ->title('This business is read-only')
            ->body('Choose a plan in Billing to change its settings again.')
            ->danger()
            ->send();

        return false;
    }

    private static function canWriteCurrentEntity(): bool
    {
        return self::currentOwnedEntity()?->canWrite() ?? false;
    }

    private static function currentOwnedEntity(): ?BusinessEntity
    {
        $entity = Filament::getTenant();

        if (! $entity instanceof BusinessEntity) {
            return null;
        }

        return $entity->owner_id === Filament::auth()->id() ? $entity : null;
    }
}
