<?php

namespace App\Filament\Personal\Pages;

use App\Filament\Shared\Schemas\TaxProfileFields;
use App\Filament\Support\ProfileFields;
use App\Jobs\RecalculatePersonalTaxEstimation;
use App\Models\TaxProfile;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use UnitEnum;

/**
 * The owner's personal tax profile, shared by all of their businesses. Saving
 * it recalculates the consolidated personal tax estimate and every workspace's
 * share. The page also lists the year-to-date income of every sole
 * proprietorship.
 *
 * @property-read Schema $form
 */
class EditTaxProfile extends Page
{
    protected static ?string $slug = 'tax-profile';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static ?string $navigationLabel = 'Tax profile';

    protected static ?int $navigationSort = 2;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->isOwner() ?? false;
    }

    public function getTitle(): string
    {
        return 'Tax profile';
    }

    public function getSubheading(): string
    {
        return 'Your tax profile is personal: it applies to all of your businesses.';
    }

    public function mount(): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $profile = $user->taxProfile;

        $cantonId = $profile?->canton_id
            ?? $user->canton_id
            ?? $user->ownedEntities()->oldest()->value('canton_id');

        $state = [
            ...($profile ? Arr::only($profile->attributesToArray(), TaxProfileFields::ATTRIBUTES) : []),
            'tax_canton_id' => $cantonId,
            'commune_id' => $profile?->commune_id
                ?? ($cantonId !== null && $cantonId === $user->canton_id ? $user->commune_id : null),
        ];

        $this->form->fill();
        $this->form->fillPartially($state, array_keys($state));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(1)
            ->components(TaxProfileFields::components());
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
                            ->submit('save'),
                    ])
                        ->alignment('end')
                        ->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        abort_unless($user->isOwner(), 403);

        self::persist($user, $this->form->getState());

        RecalculatePersonalTaxEstimation::dispatch($user->getKey());

        Notification::make()
            ->title('Tax profile saved')
            ->body('Your estimate is being updated.')
            ->success()
            ->send();
    }

    /**
     * Create or update the user's tax profile from tax profile form state.
     * user_id is guarded and the Pillar 3a amount is clamped to the legal cap.
     *
     * @param  array<string, mixed>  $data
     */
    public static function persist(User $user, array $data, string $cantonField = 'tax_canton_id'): TaxProfile
    {
        $profile = $user->taxProfile ?? new TaxProfile;

        $profile->fill([
            ...Arr::only($data, TaxProfileFields::ATTRIBUTES),
            'canton_id' => $data[$cantonField] ?? null,
            'pillar3a_amount' => ProfileFields::clampedPillar3a($data['pillar3a_amount'] ?? 0, (bool) ($data['has_pillar2'] ?? false)),
        ]);
        $profile->forceFill(['user_id' => $user->getKey()])->save();

        $user->setRelation('taxProfile', $profile);

        return $profile;
    }
}
