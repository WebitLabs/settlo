<?php

use App\Filament\Admin\Resources\CantonFiscalConfigs\CantonFiscalConfigResource;
use App\Filament\Admin\Resources\CantonFiscalConfigs\Pages\ListCantonFiscalConfigs;
use App\Filament\Admin\Resources\Communes\Pages\EditCommune;
use App\Filament\Admin\Resources\VatConfigs\Pages\EditVatConfig;
use App\Filament\Admin\Resources\VatConfigs\Pages\ListVatConfigs;
use App\Filament\Admin\Resources\VatConfigs\VatConfigResource;
use App\Models\Canton;
use App\Models\CantonFiscalConfig;
use App\Models\Commune;
use App\Models\User;
use App\Models\VatConfig;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->admin = User::factory()->superadmin()->create();
});

function actAsTaxSuperadmin(): void
{
    test()->actingAs(test()->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

it('supersedes an in-force VAT config with a new effective-dated version and audits it', function () {
    $current = VatConfig::where('year', 2026)->firstOrFail();

    actAsTaxSuperadmin();

    Livewire::test(ListVatConfigs::class)
        ->callAction(TestAction::make('newVersion')->table($current), [
            'year' => 2027,
            'standard_rate' => 8.5,
            'reduced_rate' => 2.6,
            'special_rate' => 3.8,
            'registration_threshold' => 100000,
            'registration_window_days' => 30,
            'effective_from' => '2027-01-01',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $current->refresh();

    // Old period is closed the day before the new one begins, values untouched.
    expect($current->effective_to?->toDateString())->toBe('2026-12-31')
        ->and((float) $current->standard_rate)->toBe(8.1);

    $new = VatConfig::where('year', 2027)->firstOrFail();

    expect((float) $new->standard_rate)->toBe(8.5)
        ->and($new->effective_from->toDateString())->toBe('2027-01-01')
        ->and($new->effective_to)->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'taxconfig.updated',
        'subject_type' => $new->getMorphClass(),
        'subject_id' => (string) $new->getKey(),
    ]);
});

it('blocks direct edits of an in-force (past-period) config row', function () {
    $inForce = VatConfig::where('year', 2026)->firstOrFail();

    // The seeded 2026 row started 2026-01-01, before today (2026-07+).
    expect($inForce->effective_from->isPast())->toBeTrue()
        ->and(VatConfigResource::canEdit($inForce))->toBeFalse();
});

it('allows a direct edit of a future config row and audits the change', function () {
    $future = new VatConfig;
    $future->forceFill([
        'year' => 2030,
        'standard_rate' => 8.1,
        'reduced_rate' => 2.6,
        'special_rate' => 3.8,
        'registration_threshold' => 100000,
        'registration_window_days' => 30,
        'effective_from' => '2030-01-01',
        'effective_to' => null,
    ])->save();

    expect(VatConfigResource::canEdit($future))->toBeTrue();

    actAsTaxSuperadmin();

    Livewire::test(EditVatConfig::class, ['record' => $future->getKey()])
        ->fillForm(['standard_rate' => 9.0])
        ->call('save')
        ->assertHasNoFormErrors();

    $future->refresh();

    expect((float) $future->standard_rate)->toBe(9.0);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'taxconfig.updated',
        'subject_type' => $future->getMorphClass(),
        'subject_id' => (string) $future->getKey(),
    ]);
});

it('offers a new version only on the open in-force cantonal fiscal row', function () {
    $zh = Canton::where('code', 'ZH')->firstOrFail();
    $current = CantonFiscalConfig::where('canton_id', $zh->getKey())->where('year', 2026)->firstOrFail();

    actAsTaxSuperadmin();

    Livewire::test(ListCantonFiscalConfigs::class)
        ->callAction(TestAction::make('newVersion')->table($current), [
            'canton_id' => $zh->getKey(),
            'year' => 2027,
            'cantonal_rate' => 8.5,
            'communal_multiplier_default' => 119,
            'church_rate' => 10,
            'child_deduction' => 9000,
            'effective_from' => '2027-01-01',
        ])
        ->assertHasNoActionErrors();

    $current->refresh();

    expect($current->effective_to?->toDateString())->toBe('2026-12-31');

    $new = CantonFiscalConfig::where('canton_id', $zh->getKey())->where('year', 2027)->firstOrFail();

    expect((float) $new->cantonal_rate)->toBe(8.5)
        ->and($new->canton_id)->toBe($zh->getKey())
        ->and($new->effective_to)->toBeNull()
        // The superseded 2026 row is now closed and no longer directly editable;
        // the fresh 2027 row is a future period and may still be corrected.
        ->and(CantonFiscalConfigResource::canEdit($current))->toBeFalse()
        ->and(CantonFiscalConfigResource::canEdit($new))->toBeTrue();
});

it('edits a commune multiplier in place and audits it', function () {
    $commune = Commune::where('name', 'Zürich')->firstOrFail();

    actAsTaxSuperadmin();

    Livewire::test(EditCommune::class, ['record' => $commune->getKey()])
        ->fillForm(['tax_multiplier' => 122])
        ->call('save')
        ->assertHasNoFormErrors();

    $commune->refresh();

    expect((float) $commune->tax_multiplier)->toBe(122.0);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'taxconfig.updated',
        'subject_type' => $commune->getMorphClass(),
        'subject_id' => (string) $commune->getKey(),
    ]);
});
