<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Personal\Pages\PersonalTax;
use App\Filament\Workspace\Pages\TaxOverview;
use App\Jobs\RecalculatePersonalTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TaxEstimation;
use App\Services\Tax\TaxEngine;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    config(['settlo.current_fiscal_year' => (int) now()->year]);

    [$this->owner, $this->entity] = workspaceOwner('pro');
    $this->entity->forceFill(['name' => 'Atelier Anna'])->save();
    $this->second = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['name' => 'Beta Consulting']);
    Subscription::factory()->forEntity($this->second)->create(['status' => SubscriptionStatus::Active]);

    foreach ([[$this->entity, 30000], [$this->second, 10000]] as [$entity, $amount]) {
        Invoice::factory()->for($entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent,
            'subtotal' => $amount,
            'vat_amount' => 0,
            'total' => $amount,
            'issue_date' => now(),
        ]);
    }

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('is served at /app/tax', function () {
    $this->get('/app/tax')->assertOk()->assertSee('Personal tax');
});

it('shows the consolidated totals, the per-business shares and the canton comparison', function () {
    $personal = app(TaxEngine::class)->estimateAllFor($this->owner);

    $component = Livewire::test(PersonalTax::class)
        ->assertOk()
        ->assertSee('Tax owed so far')
        ->assertSee('Expected for the full year')
        ->assertSee('Set aside monthly')
        ->assertSee("CHF 40'000.00")
        ->assertSee('Atelier Anna')
        ->assertSee('Beta Consulting')
        ->assertSee('75.0 %')
        ->assertSee('25.0 %')
        ->assertSee('Canton comparison')
        ->assertSee(number_format((float) $personal->total_tax_burden, 2, '.', "'"));

    $rows = $component->instance()->getBusinessRows($personal);
    expect(collect($rows)->sum('share'))->toBe(100.0);
});

it('shows each workspace share on the workspace tax page', function () {
    app(TaxEngine::class)->estimateAllFor($this->owner);

    actAsWorkspace($this->owner, $this->second);

    Livewire::test(TaxOverview::class)
        ->assertSee('This business is 25')
        ->assertSee(PersonalTax::getUrl(panel: 'app'), false);
});

it('recalculates the personal estimate and refreshes the workspaces', function () {
    Queue::fake();

    Livewire::test(PersonalTax::class)
        ->callAction('recalculate')
        ->assertNotified('Personal tax estimate updated');

    expect(TaxEstimation::where('user_id', $this->owner->getKey())->whereNull('business_entity_id')->count())->toBe(1);
    Queue::assertPushed(RecalculatePersonalTaxEstimation::class, fn (RecalculatePersonalTaxEstimation $job): bool => $job->userId === $this->owner->getKey());
});

it('shows an upsell to owners without the tax engine when feature gates are enforced', function () {
    config(['settlo.enforce_feature_gates' => true]);

    $solo = Plan::where('code', 'solo')->firstOrFail();
    Subscription::query()->where('user_id', $this->owner->getKey())->update(['plan_id' => $solo->getKey(), 'status' => SubscriptionStatus::Active->value]);

    Livewire::test(PersonalTax::class)
        ->assertOk()
        ->assertSee('Your personal tax estimate is part of the Pro plan')
        ->assertDontSee('Canton comparison')
        ->assertActionHidden('recalculate');
});
