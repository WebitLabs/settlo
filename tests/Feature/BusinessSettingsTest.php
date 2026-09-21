<?php

use App\Enums\BusinessEntityType;
use App\Enums\MaritalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\VatStatus;
use App\Filament\Personal\Pages\EditTaxProfile;
use App\Filament\Workspace\Pages\BusinessSettings;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\Subscription;
use App\Models\TaxProfile;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create([
        'name' => 'Original Consulting',
    ]);
    $this->entity->forceFill(['iban' => 'CH9300762011623852957'])->save();
    Subscription::factory()->forEntity($this->entity)->create(); // trialing → can write
    TaxProfile::factory()->for($this->owner)->create([
        'marital_status' => MaritalStatus::Single,
    ]);

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('workspace'));
    Filament::setTenant($this->entity);
});

it('shows the stored IBAN, grouped for readability, after a reload', function () {
    Livewire::test(BusinessSettings::class)
        ->assertSchemaStateSet(['iban' => 'CH93 0076 2011 6238 5295 7'], 'invoicingForm')
        ->assertSchemaStateSet(['name' => 'Original Consulting'], 'profileForm');
});

it('saves the business profile on its own', function () {
    Queue::fake();
    $canton = Canton::where('code', 'ZG')->firstOrFail();

    Livewire::test(BusinessSettings::class)
        ->fillForm(['name' => 'Renamed Consulting', 'canton_id' => $canton->getKey()], 'profileForm')
        ->call('saveProfile')
        ->assertHasNoFormErrors([], 'profileForm')
        ->assertNotified('Business profile saved');

    $this->entity->refresh();
    expect($this->entity->name)->toBe('Renamed Consulting')
        ->and($this->entity->canton_id)->toBe($canton->getKey())
        ->and($this->entity->iban)->toBe('CH9300762011623852957');

    Queue::assertPushed(RecalculateTaxEstimation::class);
});

it('reports profile errors only on the profile form and leaves other data untouched', function () {
    Livewire::test(BusinessSettings::class)
        ->fillForm(['name' => ''], 'profileForm')
        ->call('saveProfile')
        ->assertHasFormErrors(['name' => 'required'], 'profileForm')
        ->assertHasNoFormErrors(['iban', 'invoice_number_prefix'], 'invoicingForm');

    $this->entity->refresh();
    expect($this->entity->name)->toBe('Original Consulting')
        ->and($this->entity->iban)->toBe('CH9300762011623852957')
        ->and($this->entity->vat_status)->toBe(VatStatus::NotRegistered);
});

it('requires an IBAN when saving invoicing settings', function () {
    Livewire::test(BusinessSettings::class)
        ->fillForm(['iban' => ''], 'invoicingForm')
        ->call('saveInvoicing')
        ->assertHasFormErrors(['iban' => 'required'], 'invoicingForm')
        ->assertSee('The IBAN field is required.');

    expect($this->entity->refresh()->iban)->toBe('CH9300762011623852957');
});

it('saves invoicing settings and moves the matching default bank account to the new IBAN', function () {
    $account = new BankAccount;
    $account->forceFill([
        'business_entity_id' => $this->entity->getKey(),
        'bank_name' => 'PostFinance',
        'account_name' => 'Main',
        'iban' => 'CH9300762011623852957',
        'currency_code' => 'CHF',
        'is_default' => true,
    ])->save();

    Livewire::test(BusinessSettings::class)
        ->fillForm([
            'iban' => 'ch47 0023 0000 1234 5678 9',
            'invoice_number_prefix' => 'RC-',
        ], 'invoicingForm')
        ->call('saveInvoicing')
        ->assertHasNoFormErrors([], 'invoicingForm')
        ->assertNotified('Invoicing settings saved')
        ->assertSchemaStateSet(['iban' => 'CH47 0023 0000 1234 5678 9'], 'invoicingForm');

    $this->entity->refresh();
    expect($this->entity->iban)->toBe('CH4700230000123456789')
        ->and($this->entity->invoice_number_prefix)->toBe('RC-')
        ->and($account->refresh()->iban)->toBe('CH4700230000123456789');
});

it('refuses to save settings of a read-only (expired) workspace', function () {
    $this->entity->subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
    $this->entity->unsetRelation('subscription');
    Queue::fake();

    Livewire::test(BusinessSettings::class)
        ->assertActionDisabled(TestAction::make('saveProfile')->schemaComponent('profileForm-actions', schema: 'content'))
        ->assertActionDisabled(TestAction::make('saveInvoicing')->schemaComponent('invoicingForm-actions', schema: 'content'))
        ->fillForm(['name' => 'Changed Name'], 'profileForm')
        ->call('saveProfile')
        ->assertNotified('This business is read-only')
        ->fillForm(['invoice_number_prefix' => 'XX-'], 'invoicingForm')
        ->call('saveInvoicing')
        ->assertNotified('This business is read-only');

    $this->entity->refresh();
    expect($this->entity->name)->toBe('Original Consulting')
        ->and($this->entity->invoice_number_prefix)->not->toBe('XX-');
    Queue::assertNothingPushed();
});

it('blocks a user who does not own the active tenant', function () {
    $intruder = User::factory()->owner()->create();
    $this->actingAs($intruder);
    Filament::setTenant($this->entity);

    expect(BusinessSettings::canAccess())->toBeFalse();
});

describe('business profile fields (A16, A21)', function () {
    it('offers only sole proprietorships and marks the other types as coming soon', function () {
        Livewire::test(BusinessSettings::class)
            ->assertSchemaComponentExists('type', 'profileForm', fn (Select $field): bool => ! $field->isOptionDisabled(BusinessEntityType::SoleProprietorship->value, 'Sole proprietorship')
                && $field->isOptionDisabled(BusinessEntityType::GmbH->value, 'GmbH')
                && $field->isOptionDisabled(BusinessEntityType::AG->value, 'AG')
                && ! array_key_exists(BusinessEntityType::Association->value, $field->getOptions()))
            ->assertSee('Coming soon');
    });

    it('labels the trading name and UID in plain words', function () {
        Livewire::test(BusinessSettings::class)
            ->assertSee('Trading name')
            ->assertSee('Swiss business registration number (UID)')
            ->assertSee('Optional if your business is not registered in the Swiss Commercial Register yet.');
    });

    it('rejects a bad postal code and a UID with a wrong check digit', function () {
        Livewire::test(BusinessSettings::class)
            ->set('profileData.uid', 'CHE-123.456.789')
            ->assertHasErrors(['profileData.uid'])
            ->set('profileData.postal_code', '80001')
            ->assertHasErrors(['profileData.postal_code']);
    });

    it('normalises the UID when saving', function () {
        Queue::fake();

        Livewire::test(BusinessSettings::class)
            ->fillForm(['uid' => 'CHE148830302', 'postal_code' => '8001'], 'profileForm')
            ->call('saveProfile')
            ->assertHasNoFormErrors([], 'profileForm');

        expect($this->entity->refresh()->uid)->toBe('CHE-148.830.302');
    });

    it('accepts a custom payment term and rejects zero', function () {
        Livewire::test(BusinessSettings::class)
            ->fillForm(['default_payment_term_days' => 0], 'invoicingForm')
            ->call('saveInvoicing')
            ->assertHasFormErrors(['default_payment_term_days' => 'min'], 'invoicingForm')
            ->fillForm(['default_payment_term_days' => 21], 'invoicingForm')
            ->call('saveInvoicing')
            ->assertHasNoFormErrors([], 'invoicingForm');

        expect($this->entity->refresh()->default_payment_term_days)->toBe(21);
    });
});

describe('VAT status on the business (B4)', function () {
    it('saves the VAT status, the normalised VAT number and the estimated revenue on the business', function () {
        Queue::fake();

        Livewire::test(BusinessSettings::class)
            ->assertSchemaComponentHidden('mwst_number', 'profileForm')
            ->fillForm(['vat_status' => VatStatus::RegisteredMandatory->value], 'profileForm')
            ->assertSchemaComponentVisible('mwst_number', 'profileForm')
            ->fillForm([
                'mwst_number' => 'che148830302 mwst',
                'estimated_annual_revenue' => 150000,
            ], 'profileForm')
            ->call('saveProfile')
            ->assertHasNoFormErrors([], 'profileForm');

        $this->entity->refresh();
        expect($this->entity->vat_status)->toBe(VatStatus::RegisteredMandatory)
            ->and($this->entity->mwst_number)->toBe('CHE-148.830.302 MWST')
            ->and((float) $this->entity->estimated_annual_revenue)->toBe(150000.0)
            ->and($this->entity->isVatRegistered())->toBeTrue();
    });

    it('rejects an invalid VAT number', function () {
        Livewire::test(BusinessSettings::class)
            ->fillForm([
                'vat_status' => VatStatus::RegisteredVoluntary->value,
                'mwst_number' => 'CHE-123.456.789 MWST',
            ], 'profileForm')
            ->call('saveProfile')
            ->assertHasFormErrors(['mwst_number'], 'profileForm');
    });

    it('no longer edits the tax profile and links to the personal one instead', function () {
        Livewire::test(BusinessSettings::class)
            ->assertSee('Your tax profile is personal')
            ->assertSee(EditTaxProfile::getUrl(panel: 'app'))
            ->assertDontSee('Pillar 3a contributions / year');
    });
});
