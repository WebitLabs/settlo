<?php

use App\Models\User;
use App\Services\Tax\TaxEngine;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceDataSeeder;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(DemoSeeder::class);
});

it('persists an estimation snapshot for the demo entity matching the fixture', function () {
    $entity = User::where('email', 'anna@test.ch')->first()->ownedEntities()->first();

    $estimation = app(TaxEngine::class)->estimateFor($entity, 2026);

    // Anna's real profile (revenue 68,400, deductible 14,200, ZH, 3a 7,056)
    // reproduces the canonical total tax burden.
    expect((float) $estimation->total_tax_burden)->toBe(14825.05)
        ->and((float) $estimation->monthly_reserve)->toBe(1235.42)
        ->and($estimation->vat_alert_level)->toBe('info') // 68.4% of threshold
        ->and($estimation->rates_snapshot['canton_code'])->toBe('ZH');
});

it('writes an immutable snapshot on each recalculation rather than mutating', function () {
    $entity = User::where('email', 'anna@test.ch')->first()->ownedEntities()->first();
    $engine = app(TaxEngine::class);

    $first = $engine->estimateFor($entity, 2026);
    $second = $engine->estimateFor($entity, 2026);

    expect($second->id)->not->toBe($first->id)
        ->and($entity->taxEstimations()->count())->toBe(2);
});
