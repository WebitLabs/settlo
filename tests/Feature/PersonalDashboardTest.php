<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Personal\Pages\Billing;
use App\Filament\Personal\Pages\EditTaxProfile;
use App\Filament\Personal\Pages\PersonalDashboard;
use App\Filament\Personal\Pages\PersonalProfile;
use App\Filament\Personal\Pages\SetUpBusiness;
use App\Filament\Personal\Widgets\ConsolidatedFinancials;
use App\Filament\Personal\Widgets\OnboardingChecklist;
use App\Filament\Personal\Widgets\PersonalTaxSummary;
use App\Filament\Personal\Widgets\RecentActivity;
use App\Filament\Personal\Widgets\TaxProfileCard;
use App\Filament\Personal\Widgets\WorkspacesOverview;
use App\Filament\Workspace\Pages\BusinessSettings;
use App\Filament\Workspace\Pages\Dashboard;
use App\Models\BusinessEntity;
use App\Models\Commune;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Tax\TaxEngine;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    config(['settlo.current_fiscal_year' => (int) now()->year]);

    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/**
 * An owner with a complete home address and tax profile.
 */
function completeOwner(): User
{
    [$owner, $entity] = workspaceOwner('pro');
    $commune = Commune::where('bfs_number', '261')->firstOrFail();

    $owner->forceFill([
        'street' => 'Bahnhofstrasse',
        'postal_code' => '8001',
        'city' => 'Zürich',
        'canton_id' => $commune->canton_id,
        'commune_id' => $commune->getKey(),
    ])->save();
    $owner->taxProfile->forceFill(['canton_id' => $commune->canton_id, 'commune_id' => $commune->getKey()])->save();

    return $owner->fresh();
}

describe('page', function () {
    it('greets the owner and offers the main actions', function () {
        $owner = User::factory()->owner()->create(['first_name' => 'Anna']);
        $this->actingAs($owner);

        $this->get('/app')
            ->assertOk()
            ->assertSee('Anna')
            ->assertSee('Here&#039;s everything across your businesses.', false);

        Livewire::test(PersonalDashboard::class)
            ->assertActionHasUrl('setUpBusiness', SetUpBusiness::getUrl())
            ->assertActionHasUrl('taxProfile', EditTaxProfile::getUrl());
    });

    it('renders every widget for an owner with businesses', function () {
        $owner = completeOwner();
        $entity = $owner->ownedEntities()->firstOrFail();
        Invoice::factory()->for($entity, 'businessEntity')->create();
        app(TaxEngine::class)->estimateAllFor($owner);
        $this->actingAs($owner);

        $this->get('/app')
            ->assertOk()
            ->assertSee('Your businesses')
            ->assertSee('Profit YTD')
            ->assertSee('Personal tax')
            ->assertSee('Edit tax profile')
            ->assertSee('Recent invoices')
            ->assertDontSee('Get started');
    });
});

describe('onboarding checklist', function () {
    it('lists the open steps with links for a new owner', function () {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);

        expect(OnboardingChecklist::canView())->toBeTrue();

        Livewire::test(OnboardingChecklist::class)
            ->assertSee('Get started — 1 of 4')
            ->assertSeeInOrder(['Verify your email', 'Set up your business', 'Add your home address', 'Complete your tax profile'])
            ->assertSee(PersonalProfile::getUrl())
            ->assertSee(SetUpBusiness::getUrl())
            ->assertDontSee('Add an IBAN to every business')
            ->assertDontSee('Verify your mobile number');
    });

    it('lists setting up the business as the first open item', function () {
        config(['settlo.phone_verification.enabled' => true]);
        $owner = User::factory()->owner()->create(['phone_verified_at' => now()]);

        $firstOpen = collect(OnboardingChecklist::items($owner))->first(fn (array $item): bool => ! $item['done']);

        expect($firstOpen['label'])->toBe('Set up your business')
            ->and($firstOpen['url'])->toBe(SetUpBusiness::getUrl(panel: 'app'))
            ->and(collect(OnboardingChecklist::items($owner))->pluck('label')->all())->toBe([
                'Verify your email',
                'Verify your mobile number',
                'Set up your business',
                'Add your home address',
                'Complete your tax profile',
            ]);
    });

    it('asks for the mobile number only when SMS verification is on', function () {
        config(['settlo.phone_verification.enabled' => true]);
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);

        Livewire::test(OnboardingChecklist::class)->assertSee('Verify your mobile number');
    });

    it('asks for an IBAN on a business without one', function () {
        $owner = completeOwner();
        $entity = $owner->ownedEntities()->firstOrFail();
        $entity->forceFill(['iban' => null])->save();
        $this->actingAs($owner);

        expect(OnboardingChecklist::canView())->toBeTrue();

        Livewire::test(OnboardingChecklist::class)
            ->assertSee('Get started — 4 of 5')
            ->assertSee('Add an IBAN to every business')
            ->assertSee(BusinessSettings::getUrl(['tab' => BusinessSettings::INVOICING_TAB], panel: 'workspace', tenant: $entity));
    });

    it('requires the commune for a complete tax profile', function () {
        $owner = completeOwner();
        $owner->taxProfile->forceFill(['commune_id' => null])->save();
        $this->actingAs($owner);

        $items = collect(OnboardingChecklist::items($owner->fresh()))->keyBy('label');

        expect($items['Complete your tax profile']['done'])->toBeFalse()
            ->and($items['Add your home address']['done'])->toBeTrue();
    });

    it('is hidden once everything is done', function () {
        $this->actingAs(completeOwner());

        expect(OnboardingChecklist::canView())->toBeFalse();
    });
});

describe('workspaces overview', function () {
    it('shows a card per business with its subscription, figures and links', function () {
        [$owner, $entity] = workspaceOwner('pro');
        $entity->forceFill(['name' => 'Atelier Anna'])->save();
        $entity->subscription->forceFill(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(3)->subHour()])->save();
        $locked = BusinessEntity::factory()->for($owner, 'owner')->create(['name' => 'Beta Studio']);
        Subscription::factory()->forEntity($locked)->incomplete()->create();
        $foreign = BusinessEntity::factory()->create(['name' => 'Foreign Ltd']);
        Invoice::factory()->for($entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent, 'subtotal' => 12345, 'vat_amount' => 0, 'total' => 12345, 'issue_date' => now(), 'due_date' => now()->addMonth(),
        ]);
        $this->actingAs($owner);

        Livewire::test(WorkspacesOverview::class)
            ->assertSeeInOrder(['Atelier Anna', 'Beta Studio'])
            ->assertSee('Trial — 3 days left')
            ->assertSee('Payment required')
            ->assertSee("CHF 12'345")
            ->assertSee(Dashboard::getUrl(panel: 'workspace', tenant: $entity))
            ->assertSee(Billing::getUrl(['workspace' => $locked->getKey()]))
            ->assertSee('Set up another business')
            ->assertSee('GmbH &amp; AG — coming soon', false)
            ->assertDontSee('Foreign Ltd');
    });

    it('shows a call to action without businesses', function () {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(WorkspacesOverview::class)
            ->assertSee('Set up your business')
            ->assertSee(SetUpBusiness::getUrl());
    });
});

describe('consolidated financials', function () {
    it('sums the figures over all businesses', function () {
        [$owner, $entity] = workspaceOwner('pro');
        $second = BusinessEntity::factory()->for($owner, 'owner')->create();
        foreach ([$entity, $second] as $business) {
            Invoice::factory()->for($business, 'businessEntity')->create([
                'status' => InvoiceStatus::Sent, 'subtotal' => 1000, 'vat_amount' => 81, 'total' => 1081, 'issue_date' => now(), 'due_date' => now()->addMonth(),
            ]);
        }
        $this->actingAs($owner);

        Livewire::test(ConsolidatedFinancials::class)
            ->assertSee('Revenue YTD (excl. VAT)')
            ->assertSee("CHF 2'000")
            ->assertSee("CHF 2'162")
            ->assertSee('Across 2 businesses');
    });

    it('is hidden without businesses', function () {
        $this->actingAs(User::factory()->owner()->create());

        expect(ConsolidatedFinancials::canView())->toBeFalse();
    });
});

describe('personal tax summary', function () {
    it('shows the consolidated estimate', function () {
        [$owner, $entity] = workspaceOwner('pro');
        Invoice::factory()->for($entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent, 'subtotal' => 60000, 'vat_amount' => 0, 'total' => 60000, 'issue_date' => now(),
        ]);
        $personal = app(TaxEngine::class)->estimateAllFor($owner);
        $this->actingAs($owner);

        Livewire::test(PersonalTaxSummary::class)
            ->assertSee('Consolidated across 1 business')
            ->assertSee('Tax owed so far')
            ->assertSee('Expected for the full year')
            ->assertSee('Set aside monthly')
            ->assertSee('CHF '.number_format((float) $personal->total_tax_burden, 0, '.', "'"))
            ->assertSee('CHF '.number_format((float) $personal->projected_monthly_reserve, 0, '.', "'"))
            ->assertSee('View personal tax');
    });

    it('asks to complete the tax profile without an estimate', function () {
        [$owner] = workspaceOwner('pro');
        $this->actingAs($owner);

        Livewire::test(PersonalTaxSummary::class)->assertSee('Complete your tax profile');
    });

    it('shows an upsell without the tax engine when feature gates are enforced', function () {
        config(['settlo.enforce_feature_gates' => true]);

        [$owner, $entity] = workspaceOwner('solo');
        $entity->subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
        $this->actingAs($owner);

        Livewire::test(PersonalTaxSummary::class)
            ->assertSee('included in the Pro plan')
            ->assertDontSee('Total tax burden');
    });
});

describe('tax profile card', function () {
    it('summarises the tax profile', function () {
        $this->actingAs(completeOwner());

        Livewire::test(TaxProfileCard::class)
            ->assertSee('Zürich, ZH')
            ->assertSee('Single (Tariff A)')
            ->assertSee('Edit tax profile');
    });

    it('asks for the tax profile when missing', function () {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(TaxProfileCard::class)->assertSee('Complete your tax profile');
    });
});

describe('recent activity', function () {
    it('lists only the owner invoices across businesses', function () {
        [$owner, $entity] = workspaceOwner('pro');
        $mine = Invoice::factory()->for($entity, 'businessEntity')->create();
        $foreign = Invoice::factory()->create();
        $this->actingAs($owner);

        Livewire::test(RecentActivity::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertSee($entity->name);
    });
});
