<?php

namespace App\Services\Tax;

use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Events\VatAlertRaised;
use App\Filament\App\Pages\TaxOverview;
use App\Models\BusinessEntity;
use App\Models\TaxEstimation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Throwable;

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

        [$grossRevenue, $deductibleExpenses, $daysElapsed] = $this->gather($entity, $fiscalYear);

        $input = $this->buildInput($entity, $cantonCode, $fiscalYear, $grossRevenue, $deductibleExpenses, $daysElapsed);
        $result = $this->calculator->calculate($input);

        $largestInvoice = (float) $entity->invoices()
            ->countsAsRevenue()
            ->whereYear('issue_date', $fiscalYear)
            ->max('total');

        $vat = $this->vat->evaluate($grossRevenue, $daysElapsed, $fiscalYear, $largestInvoice ?: null);

        $estimation = TaxEstimation::create([
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

        $this->syncVatAlert($entity, $vat['level'], (float) $vat['progress_pct'], $vat['crossing_date']);

        return $estimation;
    }

    /**
     * Alert bands ordered by escalation severity. A proactive owner
     * notification fires only when the level rises into an actionable band
     * (>= warning); the stored level always tracks the latest, so a drop below
     * a band silently resets it and re-arms the notification for next time.
     *
     * @var array<string, int>
     */
    private const ALERT_RANK = [
        'none' => 0,
        'info' => 1,
        'warning' => 2,
        'critical' => 3,
        'mandatory' => 4,
    ];

    /**
     * Persist the new alert level on the entity and, when it has escalated into
     * an actionable band, notify the owner (Filament DB notification) and
     * broadcast on the per-business channel. Guarded column written via
     * forceFill.
     */
    private function syncVatAlert(BusinessEntity $entity, string $newLevel, float $thresholdPct, ?string $crossingDate): void
    {
        $previousLevel = $entity->vat_alert_level ?? 'none';

        if ($newLevel === $previousLevel) {
            return;
        }

        $entity->forceFill(['vat_alert_level' => $newLevel])->save();

        $previousRank = self::ALERT_RANK[$previousLevel] ?? 0;
        $newRank = self::ALERT_RANK[$newLevel] ?? 0;

        if ($newRank <= $previousRank || $newRank < self::ALERT_RANK['warning']) {
            return;
        }

        $owner = $entity->owner;
        if ($owner === null) {
            return;
        }

        $body = $crossingDate !== null
            ? "You've reached {$thresholdPct}% of the CHF 100'000 VAT registration threshold, on track to cross around ".Carbon::parse($crossingDate)->translatedFormat('F Y').'.'
            : "You've reached {$thresholdPct}% of the CHF 100'000 VAT registration threshold.";

        $notification = Notification::make()
            ->title('Consider VAT registration')
            ->body($body)
            ->warning();

        $taxUrl = $this->taxPageUrl($entity);
        if ($taxUrl !== null) {
            $notification->actions([
                Action::make('review')
                    ->label('Consider VAT registration')
                    ->url($taxUrl)
                    ->button(),
            ]);
        }

        $notification->sendToDatabase($owner);

        VatAlertRaised::dispatch($entity->getKey(), $newLevel, $thresholdPct, $crossingDate);
    }

    /**
     * Resolve the tax overview URL for the notification CTA. Returns null when
     * no panel context is available (e.g. a queued recalculation), in which
     * case the notification is still delivered without the deep link.
     */
    private function taxPageUrl(BusinessEntity $entity): ?string
    {
        try {
            return TaxOverview::getUrl(tenant: $entity);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Compute the tax burden for the entity's current figures across several
     * cantons, for the "where would I pay less?" comparison. Cantons without a
     * fiscal config for the year are skipped.
     *
     * @param  list<string>  $cantonCodes
     * @return array<string, TaxResult>
     */
    public function compareCantons(BusinessEntity $entity, array $cantonCodes, ?int $fiscalYear = null): array
    {
        $fiscalYear ??= (int) config('settlo.current_fiscal_year', (int) date('Y'));
        [$grossRevenue, $deductibleExpenses, $daysElapsed] = $this->gather($entity, $fiscalYear);

        $results = [];
        foreach (array_unique($cantonCodes) as $code) {
            try {
                $input = $this->buildInput($entity, $code, $fiscalYear, $grossRevenue, $deductibleExpenses, $daysElapsed);
                $results[$code] = $this->calculator->calculate($input);
            } catch (Throwable) {
                // Skip a canton we have no rates for rather than fail the page.
            }
        }

        return $results;
    }

    /**
     * @return array{0: float, 1: float, 2: int}
     */
    private function gather(BusinessEntity $entity, int $fiscalYear): array
    {
        $grossRevenue = (float) $entity->invoices()
            ->countsAsRevenue()
            ->whereYear('issue_date', $fiscalYear)
            ->sum('total');

        $deductibleExpenses = (float) $entity->expenses()
            ->where('status', 'reviewed')
            ->whereYear('expense_date', $fiscalYear)
            ->sum('deductible_amount');

        return [$grossRevenue, $deductibleExpenses, $this->daysElapsed($fiscalYear)];
    }

    public function buildInput(
        BusinessEntity $entity,
        string $cantonCode,
        int $fiscalYear,
        float $grossRevenue,
        float $deductibleExpenses,
        int $daysElapsed,
    ): TaxInput {
        $profile = $entity->taxProfile;

        return new TaxInput(
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
