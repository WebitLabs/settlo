<?php

use App\Enums\BusinessEntityType;
use App\Enums\InvoiceStatus;
use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Filament\Personal\Pages\EditTaxProfile;
use App\Filament\Support\ProfileFields;
use App\Jobs\RecalculatePersonalTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\Commune;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TaxProfile;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();
    BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
    $this->zurichCity = Commune::where('bfs_number', '261')->firstOrFail();
    TaxProfile::factory()->forCanton('ZH')->for($this->owner)->create([
        'marital_status' => MaritalStatus::Single,
        'commune_id' => $this->zurichCity->getKey(),
    ]);

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('is served from the personal area', function () {
    $this->get('/app/tax-profile')->assertOk()->assertSee('Tax profile');
});

it('is only available to owners', function () {
    $accountant = User::factory()->accountant()->create();
    $this->actingAs($accountant);

    expect(EditTaxProfile::canAccess())->toBeFalse();
});

it('saves the personal tax profile and recalculates the personal estimate', function () {
    Queue::fake();
    $canton = Canton::where('code', 'ZG')->firstOrFail();

    Livewire::test(EditTaxProfile::class)
        ->fillForm([
            'tax_canton_id' => $canton->getKey(),
            'commune_id' => Commune::where('bfs_number', '1711')->value('id'),
            'marital_status' => MaritalStatus::Married->value,
            'pillar3a_amount' => 50000, // over the cap
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Tax profile saved');

    $taxProfile = $this->owner->taxProfile()->firstOrFail();
    expect($taxProfile->canton_id)->toBe($canton->getKey())
        ->and($taxProfile->marital_status)->toBe(MaritalStatus::Married)
        ->and((float) $taxProfile->pillar3a_amount)->toBe(35280.0)
        ->and(TaxProfile::where('user_id', $this->owner->getKey())->count())->toBe(1);

    Queue::assertPushed(RecalculatePersonalTaxEstimation::class, fn (RecalculatePersonalTaxEstimation $job): bool => $job->userId === $this->owner->getKey());
});

it('creates the tax profile when the owner has none yet, prefilled from the business canton', function () {
    Queue::fake();
    $this->owner->taxProfile()->delete();
    $zurich = Canton::where('code', 'ZH')->firstOrFail();

    Livewire::test(EditTaxProfile::class)
        ->assertSchemaStateSet(['tax_canton_id' => $zurich->getKey(), 'marital_status' => MaritalStatus::Single])
        ->fillForm(['commune_id' => $this->zurichCity->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->owner->taxProfile()->firstOrFail()->canton_id)->toBe($zurich->getKey());
});

it('validates the number of children', function (mixed $children, string $rule) {
    Livewire::test(EditTaxProfile::class)
        ->fillForm(['number_of_children' => $children])
        ->call('save')
        ->assertHasFormErrors(['number_of_children' => $rule]);
})->with([
    'negative' => [-5, 'min'],
    'fractional' => [2.5, 'integer'],
    'too many' => [11, 'max'],
]);

it('caps Pillar 3a as soon as the amount is entered', function () {
    Queue::fake();

    Livewire::test(EditTaxProfile::class)
        ->set('data.pillar3a_amount', 999999)
        ->assertSchemaStateSet(['pillar3a_amount' => 35280])
        ->assertNotified('Pillar 3a is capped at CHF 35\'280')
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $this->owner->taxProfile()->firstOrFail()->pillar3a_amount)->toBe(35280.0);
});

it('explains the income-based Pillar 3a limit with the current rates', function () {
    config(['settlo.current_fiscal_year' => 2026]);

    expect(ProfileFields::pillar3aTooltip())
        ->toContain('up to 20 % of your net self-employment income, max CHF 35\'280')
        ->toContain('max CHF 7\'056')
        ->toContain('(2026)');

    Livewire::test(EditTaxProfile::class)
        ->set('data.pillar3a_amount', 999999)
        ->assertNotified(Notification::make()
            ->title('Pillar 3a is capped at CHF 35\'280')
            ->body('We reduced the amount to the legal maximum. Your tax estimate also limits it to 20 % of your net self-employment income.')
            ->info());
});

it('uses the lower Pillar 3a cap with a pension fund', function () {
    Queue::fake();

    Livewire::test(EditTaxProfile::class)
        ->set('data.pillar3a_amount', 20000)
        ->assertSchemaStateSet(['pillar3a_amount' => 20000])
        ->set('data.has_pillar2', true)
        ->assertSchemaStateSet(['pillar3a_amount' => 7056])
        ->call('save')
        ->assertHasNoFormErrors();

    $taxProfile = $this->owner->taxProfile()->firstOrFail();
    expect($taxProfile->has_pillar2)->toBeTrue()
        ->and((float) $taxProfile->pillar3a_amount)->toBe(7056.0);
});

it('saves every residence status and warns about Quellensteuer for B, L and G', function (ResidencePermit $status) {
    Queue::fake();

    $component = Livewire::test(EditTaxProfile::class)
        ->fillForm(['residence_permit' => $status->value, 'marital_status' => MaritalStatus::MarriedDualIncome->value]);

    $status->triggersQuellensteuer()
        ? $component->assertSee('withheld at source (Quellensteuer)')
        : $component->assertDontSee('withheld at source (Quellensteuer)');

    $component->call('save')->assertHasNoFormErrors();

    $taxProfile = $this->owner->taxProfile()->firstOrFail();
    expect($taxProfile->residence_permit)->toBe($status)
        ->and($taxProfile->marital_status)->toBe(MaritalStatus::MarriedDualIncome);
})->with(ResidencePermit::cases());

it('lists only current communes of the chosen canton and clears the commune when the canton changes', function () {
    $zurich = Canton::where('code', 'ZH')->firstOrFail();
    $zug = Canton::where('code', 'ZG')->firstOrFail();
    $current = Commune::where('canton_id', $zurich->getKey())->where('bfs_number', '261')->firstOrFail();
    $merged = Commune::create([
        'canton_id' => $zurich->getKey(),
        'name' => 'Merged Away',
        'bfs_number' => '9999',
        'tax_multiplier' => 100,
        'effective_from' => '2020-01-01',
        'effective_to' => '2025-12-31',
    ]);

    Livewire::test(EditTaxProfile::class)
        ->fillForm(['tax_canton_id' => $zurich->getKey(), 'commune_id' => $current->getKey()])
        ->assertSchemaComponentExists(
            'commune_id',
            checkComponentUsing: fn (Select $field): bool => array_key_exists($current->getKey(), $field->getOptions())
                && ! array_key_exists($merged->getKey(), $field->getOptions()),
        )
        ->set('data.tax_canton_id', $zug->getKey())
        ->assertSchemaStateSet(['commune_id' => null]);
});

it('offers Aargau communes and flags estimated multipliers', function () {
    $aargau = Canton::where('code', 'AG')->firstOrFail();
    $aarau = Commune::where('bfs_number', '4001')->firstOrFail();

    Livewire::test(EditTaxProfile::class)
        ->set('data.tax_canton_id', $aargau->getKey())
        ->assertSchemaComponentExists('commune_id', checkComponentUsing: fn (Select $field): bool => ($field->getOptions()[$aarau->getKey()] ?? null) === 'Aarau')
        ->set('data.commune_id', $aarau->getKey())
        ->assertSee('Communal tax rate estimated from the canton average.');
});

it('requires a commune when the canton has communes', function () {
    Livewire::test(EditTaxProfile::class)
        ->fillForm(['commune_id' => null])
        ->call('save')
        ->assertHasFormErrors(['commune_id' => 'required']);
});

it('defaults the tax residence to the home address', function () {
    $this->owner->taxProfile()->delete();
    $zug = Canton::where('code', 'ZG')->firstOrFail();
    $zugCommune = Commune::where('bfs_number', '1711')->firstOrFail();
    $this->owner->forceFill(['canton_id' => $zug->getKey(), 'commune_id' => $zugCommune->getKey()])->save();

    Livewire::test(EditTaxProfile::class)
        ->assertSchemaStateSet([
            'tax_canton_id' => $zug->getKey(),
            'commune_id' => $zugCommune->getKey(),
            'residence_permit' => ResidencePermit::SwissCitizen,
            'has_pillar2' => false,
        ]);
});

it('saves the year of birth and validates it', function () {
    Queue::fake();

    Livewire::test(EditTaxProfile::class)
        ->fillForm(['birth_year' => 1800])
        ->call('save')
        ->assertHasFormErrors(['birth_year' => 'min'])
        ->fillForm(['birth_year' => 1960])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Tax profile saved');

    expect($this->owner->taxProfile()->firstOrFail()->birth_year)->toBe(1960);
});

it('lists the income of the sole-proprietorship workspaces only', function () {
    config(['settlo.current_fiscal_year' => (int) now()->year]);
    $soleProp = $this->owner->ownedEntities()->firstOrFail();
    $soleProp->forceFill(['name' => 'Atelier Anna'])->save();
    $gmbh = BusinessEntity::factory()->for($this->owner, 'owner')->create([
        'name' => 'Anna Holding GmbH',
        'type' => BusinessEntityType::GmbH,
    ]);
    $foreign = BusinessEntity::factory()->create(['name' => 'Someone Else']);

    Invoice::factory()->for($soleProp, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent, 'subtotal' => 12000, 'vat_amount' => 0, 'total' => 12000, 'issue_date' => now(),
    ]);
    Expense::factory()->for($soleProp, 'businessEntity')->create([
        'amount' => 2500, 'deductible_amount' => 2500, 'expense_date' => now(),
    ]);

    Livewire::test(EditTaxProfile::class)
        ->assertSee('Income from your businesses')
        ->assertSee('Atelier Anna')
        ->assertSee("CHF 12'000.00", false)
        ->assertSee("CHF 2'500.00", false)
        ->assertSee("CHF 9'500.00", false)
        ->assertDontSee($gmbh->name)
        ->assertDontSee($foreign->name)
        ->assertSee('Salaries and dividends from your GmbH/AG will appear here');
});
