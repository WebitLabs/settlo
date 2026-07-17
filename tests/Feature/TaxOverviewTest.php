<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\App\Pages\TaxOverview;
use App\Filament\App\Widgets\BusinessOverview;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Tax\TaxEngine;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

function activeSubscription(User $owner, string $code): void
{
    $plan = Plan::where('code', $code)->firstOrFail();
    Subscription::factory()->for($owner, 'user')->create([
        'plan_id' => $plan->getKey(),
        'status' => SubscriptionStatus::Active,
    ]);
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->entity);
});

it('renders the business overview widget', function () {
    activeSubscription($this->owner, 'pro');

    Livewire::test(BusinessOverview::class)
        ->assertOk()
        ->assertSee('Revenue YTD');
});

it('renders the tax page with the latest estimation for a pro user', function () {
    activeSubscription($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'total' => 50000,
        'issue_date' => now(),
    ]);
    app(TaxEngine::class)->estimateFor($this->entity);

    Livewire::test(TaxOverview::class)
        ->assertOk()
        ->assertSee('Total tax burden')
        ->assertSee('Canton comparison');
});

it('gates the tax page on the Solo plan', function () {
    activeSubscription($this->owner, 'solo');

    $this->get(TaxOverview::getUrl(tenant: $this->entity))->assertForbidden();
});

it('allows the tax page on a plan that includes the tax engine', function () {
    activeSubscription($this->owner, 'pro');

    $this->get(TaxOverview::getUrl(tenant: $this->entity))->assertSuccessful();
});
