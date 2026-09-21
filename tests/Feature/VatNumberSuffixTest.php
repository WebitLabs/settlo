<?php

use App\Enums\VatStatus;
use App\Filament\Workspace\Pages\BusinessSettings;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('stores a business VAT number typed without a suffix with " MWST" (L9)', function (string $typed, string $stored) {
    Queue::fake();
    [$owner, $entity] = workspaceOwner();
    $entity->forceFill(['iban' => 'CH9300762011623852957'])->save();
    actAsWorkspace($owner, $entity);

    Livewire::test(BusinessSettings::class)
        ->fillForm(['vat_status' => VatStatus::RegisteredVoluntary->value, 'mwst_number' => $typed], 'profileForm')
        ->call('saveProfile')
        ->assertHasNoFormErrors([], 'profileForm');

    expect($entity->refresh()->mwst_number)->toBe($stored);
})->with([
    'no suffix' => ['CHE-105.829.940', 'CHE-105.829.940 MWST'],
    'TVA suffix kept' => ['che105829940 tva', 'CHE-105.829.940 TVA'],
]);
