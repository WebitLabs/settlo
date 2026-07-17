<?php

use App\Enums\ExpenseStatus;
use App\Filament\App\Pages\VatSummary;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->owner = User::factory()->owner()->create();
    Subscription::factory()->for($this->owner, 'user')->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->entity);
});

it('sums only reviewed expenses by VAT rate for the fiscal year', function () {
    $year = (int) config('settlo.current_fiscal_year', now()->year);

    // Two reviewed 8.1% expenses in-year.
    Expense::factory()->for($this->entity, 'businessEntity')->create([
        'status' => ExpenseStatus::Reviewed,
        'expense_date' => "{$year}-03-01",
        'amount' => 108.10, 'vat_amount' => 8.10, 'net_amount' => 100.00, 'vat_rate' => 8.1,
    ]);
    Expense::factory()->for($this->entity, 'businessEntity')->create([
        'status' => ExpenseStatus::Reviewed,
        'expense_date' => "{$year}-04-01",
        'amount' => 216.20, 'vat_amount' => 16.20, 'net_amount' => 200.00, 'vat_rate' => 8.1,
    ]);
    // Pending expense — excluded.
    Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')->create([
        'expense_date' => "{$year}-05-01",
        'amount' => 500, 'vat_amount' => 40, 'net_amount' => 460, 'vat_rate' => 8.1,
    ]);

    $page = new VatSummary;
    $rows = $page->getVatRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['rate'])->toBe('8.1')
        ->and($rows[0]['gross'])->toBe('324.30')
        ->and($rows[0]['net'])->toBe('300.00')
        ->and($rows[0]['input_vat'])->toBe('24.30')
        ->and($page->getTotalInputVat())->toBe('24.30');
});

it('shows reclaimable copy for a VAT-registered business', function () {
    $this->entity->forceFill(['mwst_number' => 'CHE-123.456.789 MWST'])->save();

    Livewire::test(VatSummary::class)
        ->assertOk()
        ->assertSee('reclaimed');
});

it('shows informational copy for a business without an MWST number', function () {
    Livewire::test(VatSummary::class)
        ->assertOk()
        ->assertSee('not VAT-registered');
});
