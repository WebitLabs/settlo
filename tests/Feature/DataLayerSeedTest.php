<?php

use App\Models\Canton;
use App\Models\Commune;
use App\Models\ExpenseCategory;
use App\Models\FederalTaxBracket;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceDataSeeder;

it('seeds the 2026 Swiss reference data', function () {
    $this->seed(ReferenceDataSeeder::class);

    expect(Canton::count())->toBe(26)
        ->and(Canton::has('fiscalConfigs')->count())->toBe(26)
        ->and(FederalTaxBracket::where('tariff', 'A')->count())->toBe(11)
        ->and(FederalTaxBracket::where('tariff', 'B')->count())->toBe(12)
        ->and(ExpenseCategory::count())->toBe(19)
        ->and(Plan::count())->toBe(3);

    $zh = Canton::where('code', 'ZH')->first()->fiscalConfigForYear(2026);
    expect((float) $zh->cantonal_rate)->toBe(8.0)
        ->and((int) $zh->communal_multiplier_default)->toBe(119)
        ->and((int) $zh->child_deduction)->toBe(9000);
});

it('builds the Anna Müller demo with canonical figures', function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(DemoSeeder::class);

    $anna = User::where('email', 'anna@test.ch')->first();
    $entity = $anna->ownedEntities()->first();

    expect($entity->name)->toBe('Anna Müller Consulting')
        ->and($entity->canton->code)->toBe('ZH')
        ->and((float) $entity->invoices()->countsAsRevenue()->sum('total'))->toBe(68400.0)
        ->and((float) $entity->expenses()->where('status', 'reviewed')->sum('deductible_amount'))->toBe(14200.0)
        ->and($anna->workspaceSubscriptions()->firstOrFail()->plan->code)->toBe('pro');
});

it('grants the demo firm an active assignment to Anna', function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(DemoSeeder::class);

    $maria = User::where('email', 'maria@test.ch')->first();
    $firm = $maria->accountingFirms()->first();

    expect($firm->name)->toBe('Müller Treuhand AG')
        ->and($firm->activeAssignments()->count())->toBe(1);
});

it('seeds every Swiss commune with the known multipliers intact', function () {
    $this->seed(ReferenceDataSeeder::class);

    expect(Commune::count())->toBeGreaterThanOrEqual(2100)
        ->and(Canton::doesntHave('communes')->count())->toBe(0);

    $aarau = Commune::where('bfs_number', '4001')->firstOrFail();
    expect($aarau->name)->toBe('Aarau')
        ->and($aarau->canton->code)->toBe('AG')
        ->and($aarau->multiplier_is_estimated)->toBeTrue()
        ->and((float) $aarau->tax_multiplier)->toBe((float) $aarau->canton->fiscalConfigForYear(2026)->communal_multiplier_default);

    $known = ['261' => 119.0, '154' => 70.0, '230' => 125.0, '1711' => 138.0, '6621' => 100.0, '2701' => 100.0];
    foreach ($known as $bfs => $multiplier) {
        $commune = Commune::where('bfs_number', $bfs)->firstOrFail();

        expect((float) $commune->tax_multiplier)->toBe($multiplier)
            ->and($commune->multiplier_is_estimated)->toBeFalse();
    }

    // Seeding again changes nothing.
    $this->seed(ReferenceDataSeeder::class);
    expect(Commune::count())->toBe(2110)
        ->and((float) Commune::where('bfs_number', '154')->value('tax_multiplier'))->toBe(70.0);
});
