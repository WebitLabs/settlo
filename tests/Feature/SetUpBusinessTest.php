<?php

use App\Enums\BillingInterval;
use App\Enums\BusinessEntityType;
use App\Enums\Language;
use App\Enums\MaritalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\VatStatus;
use App\Filament\Personal\Pages\Billing;
use App\Filament\Personal\Pages\PersonalDashboard;
use App\Filament\Personal\Pages\SetUpBusiness;
use App\Filament\Workspace\Pages\Dashboard;
use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\Plan;
use App\Models\TaxProfile;
use App\Models\User;
use App\Rules\ValidIban;
use App\Services\Billing\SubscriptionService;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Http::fake(['www.uid-wse.admin.ch/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/uid_register/not_found.xml')))]);
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/**
 * @return array<string, mixed>
 */
function validBusinessProfile(array $overrides = []): array
{
    return [
        'name' => 'Test Consulting',
        'legal_name' => 'Test Consulting Studio',
        'type' => BusinessEntityType::SoleProprietorship->value,
        'uid' => 'CHE-148.830.302',
        'street' => 'Bahnhofstrasse',
        'street_number' => '1',
        'postal_code' => '8001',
        'city' => 'Zürich',
        'canton_id' => Canton::where('code', 'ZH')->value('id'),
        'vat_status' => VatStatus::NotRegistered->value,
        'estimated_annual_revenue' => 120000,
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function validInvoicing(array $overrides = []): array
{
    return [
        'iban' => 'CH93 0076 2011 6238 5295 7',
        'bank_name' => 'UBS',
        'default_payment_term_days' => 30,
        'default_language' => 'de',
        'invoice_number_prefix' => 'TC-',
        ...$overrides,
    ];
}

function nextAction(): TestAction
{
    return TestAction::make('next')->schemaComponent('set-up-business-actions', schema: 'content');
}

function completeProfileStep(Testable $component, array $overrides = []): Testable
{
    return $component
        ->fillForm(validBusinessProfile($overrides), 'businessForm')
        ->call('next')
        ->assertHasNoFormErrors([], 'businessForm')
        ->assertSet('step', 2);
}

function completeInvoicingStep(Testable $component, array $overrides = []): Testable
{
    return $component
        ->fillForm(validInvoicing($overrides), 'invoicingForm')
        ->call('next')
        ->assertHasNoFormErrors([], 'invoicingForm')
        ->assertSet('step', 3);
}

function ownedBusiness(User $owner): BusinessEntity
{
    return BusinessEntity::where('owner_id', $owner->getKey())->latest()->firstOrFail();
}

describe('access', function () {
    it('is only available to verified owners', function () {
        expect(SetUpBusiness::canAccess())->toBeTrue();

        $this->actingAs(User::factory()->owner()->unverified()->create());
        expect(SetUpBusiness::canAccess())->toBeFalse();

        $this->actingAs(User::factory()->accountant()->create());
        expect(SetUpBusiness::canAccess())->toBeFalse();
    });

    it('forbids accountants and renders for owners', function () {
        $this->get('/app/businesses/new')->assertOk()->assertSee('Set up your business');

        $this->actingAs(User::factory()->accountant()->create())
            ->get('/app/businesses/new')
            ->assertForbidden();
    });

    it('requires a verified phone when phone verification is enabled', function () {
        config(['settlo.phone_verification.enabled' => true]);

        expect(SetUpBusiness::canAccess())->toBeFalse();

        $this->owner->forceFill(['phone_verified_at' => now()])->save();
        expect(SetUpBusiness::canAccess())->toBeTrue();
    });
});

describe('stepper', function () {
    it('explains the set-up and offers only supported business types', function () {
        Livewire::test(SetUpBusiness::class)
            ->assertSee('It only takes about a minute.')
            ->assertSee('Step 1 of 3')
            ->assertSchemaComponentExists('type', 'businessForm', checkComponentUsing: fn (Select $field): bool => $field->isOptionDisabled('gmbh', 'GmbH')
                && $field->isOptionDisabled('ag', 'AG')
                && ! $field->isOptionDisabled('sole_proprietorship', 'Sole proprietorship'));
    });

    it('keeps "Next" disabled until the visible step is valid', function () {
        Livewire::test(SetUpBusiness::class)
            ->assertActionDisabled(nextAction())
            ->fillForm(validBusinessProfile(), 'businessForm')
            ->assertActionEnabled(nextAction())
            ->fillForm(['city' => ''], 'businessForm')
            ->assertActionDisabled(nextAction());
    });

    it('requires the full business address', function () {
        Livewire::test(SetUpBusiness::class)
            ->fillForm(validBusinessProfile(['street' => '', 'street_number' => '', 'postal_code' => '', 'city' => '']), 'businessForm')
            ->call('next')
            ->assertHasFormErrors(['street' => 'required', 'street_number' => 'required', 'postal_code' => 'required', 'city' => 'required'], 'businessForm')
            ->assertSet('step', 1);
    });

    it('moves between steps and keeps the entered values', function () {
        $component = completeProfileStep(Livewire::test(SetUpBusiness::class))
            ->assertSee('Step 2 of 3')
            ->assertSeeHtml('data-step="1" data-state="complete"')
            ->assertSeeHtml('data-step="2" data-state="current"')
            ->assertSeeHtml('data-step="3" data-state="upcoming"')
            ->fillForm(['invoice_number_prefix' => 'ABC-'], 'invoicingForm')
            ->call('back')
            ->assertSet('step', 1)
            ->assertSeeHtml('data-step="1" data-state="current"')
            ->assertDontSeeHtml('data-state="complete"')
            ->assertSchemaStateSet(['name' => 'Test Consulting', 'city' => 'Zürich'], 'businessForm');

        $component
            ->call('next')
            ->assertSet('step', 2)
            ->assertSchemaStateSet(['invoice_number_prefix' => 'ABC-'], 'invoicingForm');
    });

    it('prefills the business address from the personal address', function () {
        $canton = Canton::where('code', 'BE')->firstOrFail();
        $this->owner->forceFill([
            'street' => 'Marktgasse',
            'street_number' => '12',
            'postal_code' => '3011',
            'city' => 'Bern',
            'canton_id' => $canton->getKey(),
            'preferred_language' => 'fr',
        ])->save();

        Livewire::test(SetUpBusiness::class)
            ->assertSchemaStateSet([
                'street' => 'Marktgasse',
                'street_number' => '12',
                'postal_code' => '3011',
                'city' => 'Bern',
                'canton_id' => $canton->getKey(),
                'type' => BusinessEntityType::SoleProprietorship->value,
            ], 'businessForm')
            ->assertSchemaStateSet([
                'default_payment_term_days' => 30,
                'default_language' => Language::French,
                'invoice_number_prefix' => 'INV-',
            ], 'invoicingForm');
    });

    it('rejects business types that are not supported yet', function () {
        Livewire::test(SetUpBusiness::class)
            ->fillForm(validBusinessProfile(['type' => BusinessEntityType::GmbH->value]), 'businessForm')
            ->call('next')
            ->assertHasFormErrors(['type' => 'in'], 'businessForm')
            ->assertSet('step', 1);
    });

    it('validates the UID while typing', function () {
        Livewire::test(SetUpBusiness::class)
            ->set('businessData.uid', 'CHE-123.456.789')
            ->assertHasErrors(['businessData.uid'])
            ->set('businessData.uid', 'CHE-148.830.302')
            ->assertHasNoErrors(['businessData.uid']);
    });

    it('requires a valid VAT number when the business is VAT registered', function () {
        $component = Livewire::test(SetUpBusiness::class)
            ->assertSchemaComponentHidden('mwst_number', 'businessForm')
            ->fillForm(validBusinessProfile(['vat_status' => VatStatus::RegisteredVoluntary->value]), 'businessForm')
            ->assertSchemaComponentVisible('mwst_number', 'businessForm')
            ->assertActionDisabled(nextAction())
            ->call('next')
            ->assertHasFormErrors(['mwst_number' => 'required'], 'businessForm')
            ->fillForm(['mwst_number' => 'che148830302 mwst'], 'businessForm')
            ->assertActionEnabled(nextAction());

        completeInvoicingStep($component->call('next')->assertSet('step', 2))
            ->call('create')
            ->assertHasNoFormErrors();

        $entity = ownedBusiness($this->owner);
        expect($entity->vat_status)->toBe(VatStatus::RegisteredVoluntary)
            ->and($entity->mwst_number)->toBe('CHE-148.830.302 MWST');
    });

    it('keeps "Next" disabled for an invalid IBAN and rejects it', function () {
        completeProfileStep(Livewire::test(SetUpBusiness::class))
            ->fillForm(validInvoicing(), 'invoicingForm')
            ->assertActionEnabled(nextAction())
            ->set('invoicingData.iban', 'CH93 0076 2011 6238 5295 8')
            ->assertHasErrors(['invoicingData.iban'])
            ->assertActionDisabled(nextAction())
            ->call('next')
            ->assertHasFormErrors(['iban'], 'invoicingForm')
            ->assertSet('step', 2);

        expect(BusinessEntity::where('owner_id', $this->owner->getKey())->exists())->toBeFalse();
    });

    it('rejects a zero payment term', function () {
        completeProfileStep(Livewire::test(SetUpBusiness::class))
            ->fillForm(validInvoicing(['default_payment_term_days' => 0]), 'invoicingForm')
            ->call('next')
            ->assertHasFormErrors(['default_payment_term_days' => 'min'], 'invoicingForm')
            ->assertSet('step', 2);
    });

    it('labels the last step for a trial', function () {
        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class)))
            ->assertSee('Step 3 of 3')
            ->assertActionHasLabel(nextAction(), 'Start 14-day free trial')
            ->assertSee('No credit card required');
    });

    it('takes the trial length from the configuration (C5)', function () {
        config(['settlo.billing.trial_days' => 30]);

        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class)))
            ->assertActionHasLabel(nextAction(), 'Start 30-day free trial')
            ->assertSee('30 days free.')
            ->call('submitStep')
            ->assertNotified('Test Consulting is ready');

        expect(ownedBusiness($this->owner)->subscription->trial_ends_at->isSameDay(now()->addDays(30)))->toBeTrue();
    });
});

describe('skipping', function () {
    it('creates nothing when the first step is skipped', function () {
        Livewire::test(SetUpBusiness::class)
            ->assertActionHasLabel(TestAction::make('skip')->schemaComponent('set-up-business-actions', schema: 'content'), "I'll do it later")
            ->call('skip')
            ->assertNotified('You can set up your business any time from your dashboard.')
            ->assertRedirect(PersonalDashboard::getUrl(panel: 'app'));

        expect(BusinessEntity::where('owner_id', $this->owner->getKey())->exists())->toBeFalse();
    });

    it('creates the business without banking when the invoicing step is skipped', function () {
        $this->owner->forceFill(['preferred_language' => 'it'])->save();
        $pro = Plan::where('code', 'pro')->firstOrFail();

        completeProfileStep(Livewire::test(SetUpBusiness::class))
            ->set('invoicingData.iban', 'not-an-iban')
            ->call('skip')
            ->assertHasNoErrors()
            ->assertRedirect(Dashboard::getUrl(tenant: ownedBusiness($this->owner), panel: 'workspace'));

        $entity = ownedBusiness($this->owner);
        expect($entity->iban)->toBeNull()
            ->and($entity->default_payment_term_days)->toBe(30)
            ->and($entity->default_language)->toBe('it')
            ->and($entity->invoice_number_prefix)->toBe('INV-')
            ->and(BankAccount::where('business_entity_id', $entity->getKey())->exists())->toBeFalse();

        $subscription = $entity->subscription()->firstOrFail();
        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->plan_id)->toBe($pro->getKey())
            ->and($subscription->billing_interval)->toBe(BillingInterval::Month);
    });

    it('uses the default plan when the plan step is skipped', function () {
        $solo = Plan::where('code', 'solo')->firstOrFail();
        $pro = Plan::where('code', 'pro')->firstOrFail();

        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class)))
            ->fillForm(['plan_id' => $solo->getKey(), 'billing_interval' => BillingInterval::Year->value], 'planForm')
            ->call('skip')
            ->assertRedirect(Dashboard::getUrl(tenant: ownedBusiness($this->owner), panel: 'workspace'));

        $subscription = ownedBusiness($this->owner)->subscription()->firstOrFail();
        expect($subscription->plan_id)->toBe($pro->getKey())
            ->and($subscription->billing_interval)->toBe(BillingInterval::Month);
    });
});

describe('skipping the plan of a second business (decision 5)', function () {
    beforeEach(function () {
        $first = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
        app(SubscriptionService::class)->startWorkspaceSubscription($first, Plan::where('code', 'pro')->firstOrFail());
        $this->owner->refresh();
    });

    it('creates the business locked, without a checkout, when the plan step is skipped', function () {
        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class), ['name' => 'Second Venture']))
            ->call('skip')
            ->assertNotified('Second Venture is set up')
            ->assertRedirect(PersonalDashboard::getUrl(panel: 'app'));

        $entity = BusinessEntity::where('name', 'Second Venture')->firstOrFail();
        expect($entity->subscription->status)->toBe(SubscriptionStatus::Incomplete)
            ->and($entity->subscription->discount_percent)->toBe(20)
            ->and($entity->subscription->payments()->count())->toBe(0)
            ->and($entity->canWrite())->toBeFalse();
    });

    it('creates the business locked, without a checkout, when the invoicing step is skipped', function () {
        completeProfileStep(Livewire::test(SetUpBusiness::class), ['name' => 'Second Venture'])
            ->call('skip')
            ->assertRedirect(PersonalDashboard::getUrl(panel: 'app'));

        expect(BusinessEntity::where('name', 'Second Venture')->firstOrFail()->subscription->status)
            ->toBe(SubscriptionStatus::Incomplete);
    });
});

describe('simulated payment for a second business', function () {
    beforeEach(function () {
        config(['settlo.payment_gateway' => 'simulated']);

        $first = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
        app(SubscriptionService::class)->startWorkspaceSubscription($first, Plan::where('code', 'pro')->firstOrFail());
        $this->owner->refresh();
    });

    it('pays in-process and opens the new workspace as an active business', function () {
        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class), ['name' => 'Second Venture']))
            ->assertActionHasLabel(nextAction(), 'Pay now (simulated)')
            ->assertSee('This payment is simulated: no card is charged and no real payment is taken.')
            ->call('submitStep')
            ->assertHasNoFormErrors([], 'planForm')
            ->assertNotified('Payment simulated — Second Venture is ready')
            ->assertRedirect(Dashboard::getUrl(
                tenant: BusinessEntity::where('name', 'Second Venture')->firstOrFail(),
                panel: 'workspace',
            ));

        $entity = BusinessEntity::where('name', 'Second Venture')->firstOrFail();

        expect($entity->subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($entity->subscription->gateway)->toBe('simulated')
            ->and($entity->subscription->discount_percent)->toBe(20)
            ->and($entity->subscription->payments()->count())->toBe(1)
            ->and($entity->canWrite())->toBeTrue();

        $this->get(Dashboard::getUrl(tenant: $entity, panel: 'workspace'))->assertOk();
    });

    it('still creates a locked business with no payment when the plan is skipped', function () {
        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class), ['name' => 'Second Venture']))
            ->call('skip')
            ->assertNotified('Second Venture is set up')
            ->assertRedirect(PersonalDashboard::getUrl(panel: 'app'));

        $entity = BusinessEntity::where('name', 'Second Venture')->firstOrFail();

        expect($entity->subscription->status)->toBe(SubscriptionStatus::Incomplete)
            ->and($entity->subscription->payments()->count())->toBe(0)
            ->and($entity->canWrite())->toBeFalse();
    });

    it('still starts the free trial of a first business instead of charging', function () {
        $newOwner = User::factory()->owner()->create();
        $this->actingAs($newOwner);

        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class)))
            ->assertActionHasLabel(nextAction(), 'Start 14-day free trial')
            ->call('submitStep')
            ->assertNotified('Test Consulting is ready');

        $subscription = ownedBusiness($newOwner)->subscription;

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->payments()->count())->toBe(0);
    });
});

describe('checkout availability (M7)', function () {
    beforeEach(function () {
        config(['settlo.payment_gateway' => 'stripe', 'cashier.secret' => 'sk_test_fake']);
    });

    it('creates nothing when the checkout of a second business cannot start', function () {
        $first = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
        app(SubscriptionService::class)->startWorkspaceSubscription($first, Plan::where('code', 'pro')->firstOrFail());
        $this->owner->refresh();

        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class), ['name' => 'Second Venture']))
            ->assertActionHasLabel(nextAction(), 'Continue to payment')
            ->call('submitStep')
            ->assertNotified('The payment could not be started')
            ->assertNoRedirect();

        expect(BusinessEntity::where('name', 'Second Venture')->exists())->toBeFalse()
            ->and(BusinessEntity::where('owner_id', $this->owner->getKey())->count())->toBe(1);
    });

    it('still starts the trial of a first business', function () {
        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class)))
            ->call('submitStep')
            ->assertNotified('Test Consulting is ready');

        expect(ownedBusiness($this->owner)->subscription->status)->toBe(SubscriptionStatus::Trialing);
    });
});

describe('creating', function () {
    it('provisions the business, bank account and trial on the chosen plan', function () {
        $solo = Plan::where('code', 'solo')->firstOrFail();

        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class)))
            ->fillForm(['plan_id' => $solo->getKey(), 'billing_interval' => BillingInterval::Year->value], 'planForm')
            ->call('submitStep')
            ->assertHasNoFormErrors([], 'planForm')
            ->assertNotified('Test Consulting is ready')
            ->assertRedirect(Dashboard::getUrl(tenant: ownedBusiness($this->owner), panel: 'workspace'));

        $entity = ownedBusiness($this->owner);
        expect($entity->name)->toBe('Test Consulting')
            ->and($entity->legal_name)->toBe('Test Consulting Studio')
            ->and($entity->uid)->toBe('CHE-148.830.302')
            ->and($entity->street)->toBe('Bahnhofstrasse')
            ->and($entity->canton_id)->toBe(Canton::where('code', 'ZH')->value('id'))
            ->and($entity->iban)->toBe('CH9300762011623852957')
            ->and($entity->vat_status)->toBe(VatStatus::NotRegistered)
            ->and((float) $entity->estimated_annual_revenue)->toBe(120000.0)
            ->and($entity->default_language)->toBe('de')
            ->and($entity->invoice_number_prefix)->toBe('TC-');

        $bankAccount = BankAccount::where('business_entity_id', $entity->getKey())->firstOrFail();
        expect($bankAccount->is_default)->toBeTrue()
            ->and($bankAccount->bank_name)->toBe('UBS')
            ->and($bankAccount->account_name)->toBe('Test Consulting')
            ->and($bankAccount->iban)->toBe('CH9300762011623852957');

        $subscription = $entity->subscription()->firstOrFail();
        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->plan_id)->toBe($solo->getKey())
            ->and($subscription->billing_interval)->toBe(BillingInterval::Year);

        $this->owner->refresh();
        expect($this->owner->hasUsedTrial())->toBeTrue()
            ->and($this->owner->onboarding_completed_at)->not->toBeNull()
            ->and($this->owner->last_business_entity_id)->toBe($entity->getKey());
    });

    it('requires payment and keeps the personal tax profile when a second business is set up', function () {
        $zurich = Canton::where('code', 'ZH')->firstOrFail();
        $zug = Canton::where('code', 'ZG')->firstOrFail();
        $pro = Plan::where('code', 'pro')->firstOrFail();
        $profile = TaxProfile::factory()->for($this->owner)->create([
            'canton_id' => $zurich->getKey(),
            'marital_status' => MaritalStatus::Married,
        ]);
        $first = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
        $subscription = app(SubscriptionService::class)->startWorkspaceSubscription($first, $pro);
        $subscription->forceFill(['trial_ends_at' => now()->addDays(3)])->save();
        $this->owner->refresh();

        $component = completeProfileStep(Livewire::test(SetUpBusiness::class), [
            'name' => 'Second Venture',
            'canton_id' => $zug->getKey(),
            'vat_status' => VatStatus::RegisteredVoluntary->value,
            'mwst_number' => 'CHE-148.830.302 MWST',
        ]);

        completeInvoicingStep($component, ['invoice_number_prefix' => 'SV-'])
            ->assertActionHasLabel(nextAction(), 'Continue to payment')
            ->assertSee('Multi-business discount: 20 %')
            ->call('submitStep')
            ->assertHasNoFormErrors([], 'planForm')
            ->assertRedirectContains('/billing/dummy-checkout/')
            ->assertRedirectContains(rawurlencode(Billing::getUrl(['checkout' => 'success'], panel: 'app')));

        $entity = BusinessEntity::where('name', 'Second Venture')->firstOrFail();
        expect($entity->subscription->status)->toBe(SubscriptionStatus::Incomplete)
            ->and($entity->subscription->discount_percent)->toBe(20)
            ->and($entity->canton_id)->toBe($zug->getKey())
            ->and($entity->vat_status)->toBe(VatStatus::RegisteredVoluntary)
            ->and(TaxProfile::where('user_id', $this->owner->getKey())->pluck('id')->all())->toBe([$profile->getKey()])
            ->and($profile->fresh()->canton_id)->toBe($zurich->getKey())
            ->and($profile->fresh()->marital_status)->toBe(MaritalStatus::Married)
            ->and($subscription->fresh()->trial_ends_at->isSameDay(now()->addDays(3)))->toBeTrue();
    });

    it('gives the third business the 30 % discount', function () {
        $pro = Plan::where('code', 'pro')->firstOrFail();

        foreach (range(1, 2) as $index) {
            $existing = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
            app(SubscriptionService::class)->startWorkspaceSubscription($existing, $pro)
                ->forceFill(['status' => SubscriptionStatus::Active])
                ->save();
        }

        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class), ['name' => 'Third Venture']))
            ->call('submitStep')
            ->assertRedirectContains('/billing/dummy-checkout/');

        $entity = BusinessEntity::where('name', 'Third Venture')->firstOrFail();
        expect($entity->subscription->status)->toBe(SubscriptionStatus::Incomplete)
            ->and($entity->subscription->discount_percent)->toBe(30);
    });

    it('never lets the payload choose the owner', function () {
        $intruder = User::factory()->owner()->create();

        completeInvoicingStep(completeProfileStep(Livewire::test(SetUpBusiness::class), ['owner_id' => $intruder->getKey()]))
            ->call('submitStep');

        expect(BusinessEntity::where('owner_id', $intruder->getKey())->exists())->toBeFalse()
            ->and(ownedBusiness($this->owner)->name)->toBe('Test Consulting');
    });
});

it('validates Swiss and Liechtenstein IBANs and rejects bad checksums', function (string $iban, bool $valid) {
    expect(ValidIban::isValid($iban))->toBe($valid);
})->with([
    'valid CH with spaces' => ['CH93 0076 2011 6238 5295 7', true],
    'valid CH normalized' => ['CH9300762011623852957', true],
    'valid LI' => ['LI44 0881 0000 2324 0130 0', true],
    'invalid CH checksum' => ['CH93 0076 2011 6238 5295 8', false],
    'wrong length' => ['CH9300762011623852', false],
    'non CH/LI country' => ['DE89 3704 0044 0532 0130 00', false],
    'garbage' => ['not-an-iban', false],
]);
