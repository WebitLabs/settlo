<?php

use App\Enums\InvoiceStatus;
use App\Filament\Personal\Pages\Billing;
use App\Filament\Personal\Pages\SetUpBusiness;
use App\Filament\Personal\Resources\Businesses\BusinessResource;
use App\Filament\Personal\Resources\Businesses\Pages\ListBusinesses;
use App\Filament\Workspace\Pages\BusinessSettings;
use App\Filament\Workspace\Pages\Dashboard;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    config(['settlo.current_fiscal_year' => (int) now()->year]);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('is served at /app/businesses', function () {
    [$owner] = workspaceOwner();

    $this->actingAs($owner)->get('/app/businesses')->assertOk()->assertSee('My businesses');
    expect(BusinessResource::getUrl())->toEndWith('/app/businesses');
});

it('lists only the owner businesses with their revenue', function () {
    [$owner, $entity] = workspaceOwner('pro');
    $foreign = BusinessEntity::factory()->create();
    Invoice::factory()->for($entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Paid, 'subtotal' => 4200, 'vat_amount' => 340.20, 'total' => 4540.20, 'issue_date' => now(),
    ]);
    Invoice::factory()->for($entity, 'businessEntity')->draft()->create(['subtotal' => 999, 'issue_date' => now()]);
    $this->actingAs($owner);

    Livewire::test(ListBusinesses::class)
        ->assertCanSeeTableRecords([$entity])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableColumnStateSet('revenue_ytd', 4200, $entity)
        ->assertTableColumnStateSet('subscription.plan.name', 'Pro', $entity);
});

it('links each business to its workspace, settings and billing', function () {
    [$owner, $entity] = workspaceOwner();
    $this->actingAs($owner);

    Livewire::test(ListBusinesses::class)
        ->assertActionHasUrl(TestAction::make('open')->table($entity), Dashboard::getUrl(panel: 'workspace', tenant: $entity))
        ->assertActionHasUrl(TestAction::make('settings')->table($entity), BusinessSettings::getUrl(panel: 'workspace', tenant: $entity))
        ->assertActionHasUrl(TestAction::make('billing')->table($entity), Billing::getUrl(['workspace' => $entity->getKey()]))
        ->assertActionHasUrl('setUpBusiness', SetUpBusiness::getUrl());
});

it('shows an empty state with a set-up action', function () {
    $this->actingAs(User::factory()->owner()->create());

    Livewire::test(ListBusinesses::class)
        ->assertSee('No businesses yet')
        ->assertSee('Create your first business workspace to start invoicing.')
        ->assertActionHasUrl(TestAction::make('setUpFirstBusiness')->table(), SetUpBusiness::getUrl());
});

it('cannot create, edit or delete businesses', function () {
    [$owner, $entity] = workspaceOwner();
    $this->actingAs($owner);

    expect(BusinessResource::canCreate())->toBeFalse()
        ->and(BusinessResource::canEdit($entity))->toBeFalse()
        ->and(BusinessResource::canDelete($entity))->toBeFalse()
        ->and(BusinessResource::hasPage('edit'))->toBeFalse();
});

it('is not available to accountants', function () {
    $this->actingAs(User::factory()->accountant()->create());

    expect(BusinessResource::canAccess())->toBeFalse();
});
