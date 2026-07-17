<?php

namespace App\Services\Tax;

use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Models\BusinessEntity;
use App\Models\TaxEstimation;
use Illuminate\Support\Carbon;

/**
 * Orchestrates a tax estimation for a business entity: gathers the current
 * revenue/expense figures and tax profile, runs the calculator, evaluates the
 * VAT threshold, and persists an immutable TaxEstimation snapshot.
 */
class TaxEngine
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly VatThresholdService $vat,
    ) {}

    public function estimateFor(BusinessEntity $entity, ?int $fiscalYear = null): ?TaxEstimation
    {
        $fiscalYear ??= (int) config('settlo.current_fiscal_year', (int) date('Y'));
        $profile = $entity->taxProfile;

        $cantonCode = $profile?->canton?->code ?? $entity->canton?->code;
        if ($cantonCode === null) {
            return null; // Cannot estimate without a canton.
        }

        $grossRevenue = (float) $entity->invoices()
            ->countsAsRevenue()
            ->whereYear('issue_date', $fiscalYear)
            ->sum('total');

        $deductibleExpenses = (float) $entity->expenses()
            ->where('status', 'reviewed')
            ->whereYear('expense_date', $fiscalYear)
            ->sum('deductible_amount');

        $daysElapsed = $this->daysElapsed($fiscalYear);

        $input = new TaxInput(
            cantonCode: $cantonCode,
            fiscalYear: $fiscalYear,
            grossRevenue: $grossRevenue,
            deductibleExpenses: $deductibleExpenses,
            maritalStatus: $profile?->marital_status ?? MaritalStatus::Single,
            numberOfChildren: $profile?->number_of_children ?? 0,
            pillar3aAmount: (float) ($profile?->pillar3a_amount ?? 0),
            hasPillar2: (bool) ($profile?->has_pillar2 ?? false),
            kirchensteuer: (bool) ($profile?->kirchensteuer ?? false),
            residencePermit: $profile?->residence_permit ?? ResidencePermit::SwissOrCPermit,
            age: $profile?->age($fiscalYear),
            otherIncome: (float) ($profile?->other_income ?? 0),
            communeMultiplier: $profile?->commune ? (float) $profile->commune->tax_multiplier : null,
            daysElapsed: $daysElapsed,
        );

        $result = $this->calculator->calculate($input);

        $largestInvoice = (float) $entity->invoices()
            ->countsAsRevenue()
            ->whereYear('issue_date', $fiscalYear)
            ->max('total');

        $vat = $this->vat->evaluate($grossRevenue, $daysElapsed, $fiscalYear, $largestInvoice ?: null);

        return TaxEstimation::create([
            'business_entity_id' => $entity->getKey(),
            'canton_id' => $profile?->canton_id ?? $entity->canton_id,
            'fiscal_year' => $fiscalYear,
            'calculated_at' => Carbon::now(),
            ...$result->toArray(),
            'vat_threshold_pct' => $vat['progress_pct'],
            'vat_alert_level' => $vat['level'],
            'vat_crossing_date' => $vat['crossing_date'],
            'inputs' => [
                'gross_revenue' => $grossRevenue,
                'deductible_expenses' => $deductibleExpenses,
                'days_elapsed' => $daysElapsed,
                'canton_code' => $cantonCode,
            ],
            'rates_snapshot' => $result->ratesSnapshot,
        ]);
    }

    private function daysElapsed(int $fiscalYear): int
    {
        $start = Carbon::create($fiscalYear, 1, 1)->startOfDay();
        $now = Carbon::now();

        if ($now->year > $fiscalYear) {
            return 365;
        }

        if ($now->year < $fiscalYear) {
            return 1;
        }

        return max(1, $start->diffInDays($now) + 1);
    }
}
