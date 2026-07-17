<?php

namespace App\Filament\Firm\Pages;

use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Firm profile settings. Only firm owners may open this page (enforced in
 * canAccess, not merely hidden in navigation). The form edits the current firm
 * tenant in place; saving writes through forceFill on the tenant model so the
 * guarded mass-assignment posture is preserved and only the whitelisted profile
 * columns are ever touched.
 */
class FirmSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Firm settings';

    protected static string|UnitEnum|null $navigationGroup = 'Firm';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'firm-settings';

    /**
     * The columns an owner is allowed to edit through this page.
     *
     * @var list<string>
     */
    private const EDITABLE_FIELDS = [
        'name', 'legal_name', 'uid', 'email', 'phone', 'street', 'city', 'postal_code',
    ];

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Only owners of the current firm tenant may access firm settings.
     */
    public static function canAccess(): bool
    {
        return static::currentUserIsFirmOwner();
    }

    public function getTitle(): string
    {
        return 'Firm settings';
    }

    public function mount(): void
    {
        abort_unless(static::currentUserIsFirmOwner(), 403);

        $this->form->fill($this->firm()->only(self::EDITABLE_FIELDS));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Firm details')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Display name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('legal_name')
                            ->label('Legal name')
                            ->maxLength(255),
                        TextInput::make('uid')
                            ->label('UID')
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Contact email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(255),
                    ]),
                Section::make('Address')
                    ->columns(2)
                    ->components([
                        TextInput::make('street')
                            ->label('Street')
                            ->maxLength(255),
                        TextInput::make('postal_code')
                            ->label('Postal code')
                            ->maxLength(255),
                        TextInput::make('city')
                            ->label('City')
                            ->maxLength(255),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save changes')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::currentUserIsFirmOwner(), 403);

        $data = $this->form->getState();

        $this->firm()->forceFill(
            collect($data)->only(self::EDITABLE_FIELDS)->all()
        )->save();

        Notification::make()->title('Firm settings saved')->success()->send();
    }

    private function firm(): AccountingFirm
    {
        return Filament::getTenant();
    }

    /**
     * Whether the authenticated user is an owner of the current firm tenant.
     */
    private static function currentUserIsFirmOwner(): bool
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return false;
        }

        return AccountingFirmMember::query()
            ->where('accounting_firm_id', $tenant->getKey())
            ->where('user_id', Auth::id())
            ->where('is_owner', true)
            ->exists();
    }
}
