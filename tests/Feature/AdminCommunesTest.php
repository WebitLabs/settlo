<?php

use App\Filament\Admin\Resources\Communes\Pages\EditCommune;
use App\Filament\Admin\Resources\Communes\Pages\ListCommunes;
use App\Models\Canton;
use App\Models\Commune;
use App\Models\User;
use Database\Seeders\CantonSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(CantonSeeder::class);
    $this->aargau = Canton::where('code', 'AG')->firstOrFail();

    $this->estimated = Commune::create([
        'canton_id' => $this->aargau->getKey(), 'name' => 'Aarau', 'bfs_number' => '4001',
        'tax_multiplier' => 100, 'multiplier_is_estimated' => true, 'effective_from' => '2026-01-01',
    ]);
    $this->real = Commune::create([
        'canton_id' => $this->aargau->getKey(), 'name' => 'Biberstein', 'bfs_number' => '4002',
        'tax_multiplier' => 97, 'multiplier_is_estimated' => false, 'effective_from' => '2026-01-01',
    ]);

    $this->actingAs(User::factory()->superadmin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('filters communes by whether their multiplier is estimated', function () {
    Livewire::test(ListCommunes::class)
        ->assertCanSeeTableRecords([$this->estimated, $this->real])
        ->filterTable('multiplier_is_estimated', true)
        ->assertCanSeeTableRecords([$this->estimated])
        ->assertCanNotSeeTableRecords([$this->real])
        ->filterTable('multiplier_is_estimated', false)
        ->assertCanSeeTableRecords([$this->real])
        ->assertCanNotSeeTableRecords([$this->estimated]);
});

it('toggles the estimated flag from the table', function () {
    Livewire::test(ListCommunes::class)
        ->call('updateTableColumnState', 'multiplier_is_estimated', $this->estimated->getKey(), false);

    expect($this->estimated->fresh()->multiplier_is_estimated)->toBeFalse();
});

it('marks a multiplier as real once the admin enters it', function () {
    Livewire::test(EditCommune::class, ['record' => $this->estimated->getKey()])
        ->assertSchemaStateSet(['multiplier_is_estimated' => true])
        ->fillForm(['tax_multiplier' => 104])
        ->assertSchemaStateSet(['multiplier_is_estimated' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->estimated->fresh())
        ->tax_multiplier->toEqual('104.0000')
        ->multiplier_is_estimated->toBeFalse();
});

it('keeps an admin-entered multiplier when the communes are imported again', function () {
    Livewire::test(EditCommune::class, ['record' => $this->estimated->getKey()])
        ->fillForm(['tax_multiplier' => 104])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->artisan('settlo:import-communes', [
        '--file' => base_path('tests/Fixtures/bfs_communes_snapshot.csv'),
        '--date' => '01-01-2027',
    ])->assertSuccessful();

    expect($this->estimated->fresh())
        ->tax_multiplier->toEqual('104.0000')
        ->multiplier_is_estimated->toBeFalse()
        ->effective_from->toDateString()->toBe('2026-01-01');
});
