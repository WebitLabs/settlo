<?php

namespace App\Services\Tax;

use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;

/**
 * Immutable set of inputs for one tax calculation. All monetary values are
 * plain numeric strings/floats in CHF; the calculator does the decimal math.
 */
final readonly class TaxInput
{
    public function __construct(
        public string $cantonCode,
        public int $fiscalYear,
        public float $grossRevenue,
        public float $deductibleExpenses,
        public MaritalStatus $maritalStatus = MaritalStatus::Single,
        public int $numberOfChildren = 0,
        public float $pillar3aAmount = 0.0,
        public bool $hasPillar2 = false,
        public bool $kirchensteuer = false,
        public ResidencePermit $residencePermit = ResidencePermit::SwissOrCPermit,
        public ?int $age = null,
        public float $otherIncome = 0.0,
        /** Communal multiplier (Steuerfuss) as a percentage, e.g. 119. Null = canton default. */
        public ?float $communeMultiplier = null,
        /** Days elapsed in the fiscal year for annualisation; null = full-year figures. */
        public ?int $daysElapsed = null,
    ) {}
}
