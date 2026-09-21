<?php

namespace App\Services\Tax;

use App\Enums\BusinessEntityType;
use App\Enums\ExpenseStatus;
use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Events\VatAlertRaised;
use App\Filament\Workspace\Pages\TaxOverview;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TaxEstimation;
use App\Models\TaxProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Orchestrates tax estimations. Income tax and AHV are levied on the person,
 * so the source of truth is the consolidated personal estimate across all of
 * an owner's sole proprietorships (estimateForUser). Each business row
 * (estimateFor) carries its own revenue figures, its VAT threshold evaluation
 * and a share of the personal tax proportional to its positive net income.
 * Every run persists immutable TaxEstimation snapshots.
 */
class TaxEngine
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly VatThresholdService $vat,
    ) {}

    /**
     * The consolidated personal estimate for all of the user's sole
     * proprietorships. Returns null when no canton can be determined.
     */
    public function estimateForUser(User $user, ?int $fiscalYear = null): ?TaxEstimation
    {
        $fiscalYear ??= $this->currentFiscalYear();
        $profile = $this->profileFor($user);

        $cantonCode = $this->cantonCodeFor($user, $profile);
        if ($cantonCode === null) {
            return null;
        }

        $businesses = $this->businessFigures($user, $fiscalYear);
        $grossRevenue = $this->sumOf($businesses, 'net_revenue');
        $deductibleExpenses = $this->sumOf($businesses, 'deductible_expenses');
        $daysElapsed = $this->daysElapsed(
            $fiscalYear,
            $this->activityStart($businesses->keys()->all(), $fiscalYear, $user->created_at),
        );

        $input = $this->buildInput($profile, $cantonCode, $fiscalYear, $grossRevenue, $deductibleExpenses, $daysElapsed);
        $result = $this->calculator->calculate($input);

        // The VAT registration threshold is levied on the person, not on each
        // sole proprietorship: it is evaluated once here, on the consolidated
        // revenue and the largest single invoice across all of them.
        $vat = $this->vat->evaluate(
            $grossRevenue,
            $daysElapsed,
            $fiscalYear,
            $this->largestInvoiceOf($businesses->keys()->all(), $fiscalYear),
        );

        return TaxEstimation::create([
            'user_id' => $user->getKey(),
            'business_entity_id' => null,
            'canton_id' => $this->cantonIdFor($user, $profile),
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
                'vat' => [...$vat, 'scope' => 'owner'],
                'businesses' => $businesses->map(fn (array $figures): array => [
                    'name' => $figures['name'],
                    'net_revenue' => $figures['net_revenue'],
                    'deductible_expenses' => $figures['deductible_expenses'],
                    'net_income' => $figures['net_income'],
                ])->all(),
            ],
            'rates_snapshot' => $result->ratesSnapshot,
        ]);
    }

    /**
     * Estimate one business. For a sole proprietorship this refreshes the
     * owner's personal estimate and every sibling workspace's share of it (a
     * change on one business moves the shares of all the others). Returns
     * this business' fresh row.
     */
    public function estimateFor(BusinessEntity $entity, ?int $fiscalYear = null): ?TaxEstimation
    {
        $fiscalYear ??= $this->currentFiscalYear();
        $owner = $entity->owner;

        if ($owner !== null && $entity->type === BusinessEntityType::SoleProprietorship) {
            [, $rows] = $this->estimateOwner($owner, $fiscalYear, $entity);

            if (isset($rows[$entity->getKey()])) {
                return $rows[$entity->getKey()];
            }
        }

        $personal = $owner !== null ? $this->estimateForUser($owner, $fiscalYear) : null;

        return $this->estimateBusiness($entity, $fiscalYear, $personal);
    }

    /**
     * Refresh the personal estimate once, then every sole-proprietorship
     * workspace's share of it.
     */
    public function estimateAllFor(User $user, ?int $fiscalYear = null): ?TaxEstimation
    {
        [$personal] = $this->estimateOwner($user, $fiscalYear ?? $this->currentFiscalYear());

        return $personal;
    }

    /**
     * Compute the consolidated tax burden across several cantons, for the
     * "where would I pay less?" comparison. Cantons without a fiscal config for
     * the year are skipped.
     *
     * @param  list<string>  $cantonCodes
     * @return array<string, TaxResult>
     */
    public function compareCantons(User $user, array $cantonCodes, ?int $fiscalYear = null): array
    {
        $fiscalYear ??= $this->currentFiscalYear();
        $profile = $this->profileFor($user);
        $businesses = $this->businessFigures($user, $fiscalYear);
        $grossRevenue = $this->sumOf($businesses, 'net_revenue');
        $deductibleExpenses = $this->sumOf($businesses, 'deductible_expenses');
        $daysElapsed = $this->daysElapsed(
            $fiscalYear,
            $this->activityStart($businesses->keys()->all(), $fiscalYear, $user->created_at),
        );

        $results = [];
        foreach (array_unique($cantonCodes) as $code) {
            try {
                $input = $this->buildInput($profile, $code, $fiscalYear, $grossRevenue, $deductibleExpenses, $daysElapsed);
                $results[$code] = $this->calculator->calculate($input);
            } catch (Throwable) {
                // Skip a canton we have no rates for rather than fail the page.
            }
        }

        return $results;
    }

    public function buildInput(
        ?TaxProfile $profile,
        string $cantonCode,
        int $fiscalYear,
        float $grossRevenue,
        float $deductibleExpenses,
        int $daysElapsed,
    ): TaxInput {
        $communeMultiplier = $profile?->commune !== null && $profile->canton?->code === $cantonCode
            ? (float) $profile->commune->tax_multiplier
            : null;

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
            residencePermit: $profile?->residence_permit ?? ResidencePermit::SwissCitizen,
            age: $profile?->age($fiscalYear),
            otherIncome: (float) ($profile?->other_income ?? 0),
            communeMultiplier: $communeMultiplier,
            daysElapsed: $daysElapsed,
        );
    }

    /**
     * Refresh the owner's personal estimate and all of their sole
     * proprietorships' rows. $current, when given, is used in place of the
     * freshly loaded instance of the same business so its in-memory state
     * (e.g. the VAT alert level) stays in sync.
     *
     * @return array{0: ?TaxEstimation, 1: array<array-key, TaxEstimation>}
     */
    private function estimateOwner(User $user, int $fiscalYear, ?BusinessEntity $current = null): array
    {
        $personal = $this->estimateForUser($user, $fiscalYear);
        $rows = [];

        foreach ($user->soleProprietorships()->get() as $entity) {
            if ($current !== null && $current->is($entity)) {
                $entity = $current;
            }

            $rows[$entity->getKey()] = $this->estimateBusiness($entity->setRelation('owner', $user), $fiscalYear, $personal, $this->ownerVatOf($personal));
        }

        return [$personal, $rows];
    }

    /**
     * @param  array<string, mixed>|null  $ownerVat  the owner-level VAT evaluation, for a
     *                                               sole proprietorship whose threshold is
     *                                               levied on the person
     */
    private function estimateBusiness(BusinessEntity $entity, int $fiscalYear, ?TaxEstimation $personal, ?array $ownerVat = null): TaxEstimation
    {
        $figures = $this->figuresFor($entity, $fiscalYear);
        $daysElapsed = $this->daysElapsed(
            $fiscalYear,
            $this->activityStart([$entity->getKey()], $fiscalYear, $entity->created_at),
        );
        $share = $this->shareOf($entity, $figures['net_income'], $personal);
        $ratesSnapshot = $personal->rates_snapshot ?? [];

        $taxFields = $personal !== null
            ? $this->personalResult($personal)->scaled($share)->toArray()
            : TaxResult::quellensteuer()->toArray();

        if ($personal !== null && isset($ratesSnapshot['deductions'])) {
            $ratesSnapshot['deductions'] = $this->scaledDeductions($personal, $ratesSnapshot['deductions'], $share);
            $taxFields['taxable_income'] = $ratesSnapshot['deductions']['taxable_income'];
        }

        $grossRevenue = $figures['net_revenue'];
        $totalTax = (float) ($taxFields['total_tax_burden'] ?? 0);

        // A sole proprietorship inherits the owner's consolidated threshold
        // evaluation (it is one registration per person); any other entity is
        // evaluated on its own figures.
        $vat = $ownerVat ?? $this->vat->evaluate(
            $grossRevenue,
            $daysElapsed,
            $fiscalYear,
            $this->largestInvoiceOf([$entity->getKey()], $fiscalYear),
        );

        $estimation = TaxEstimation::create([
            'user_id' => $entity->owner_id,
            'business_entity_id' => $entity->getKey(),
            'canton_id' => $personal?->canton_id ?? $entity->canton_id,
            'fiscal_year' => $fiscalYear,
            'calculated_at' => Carbon::now(),
            ...$taxFields,
            'quellensteuer_regime' => (bool) ($personal?->quellensteuer_regime ?? false),
            'gross_revenue' => $grossRevenue,
            'total_expenses' => $figures['deductible_expenses'],
            'net_income' => $figures['net_income'],
            'effective_rate' => $this->percentOf($totalTax, $grossRevenue),
            'projected_annual_revenue' => $this->annualised($grossRevenue, $daysElapsed),
            'vat_threshold_pct' => $vat['progress_pct'],
            'vat_alert_level' => $vat['level'],
            'vat_crossing_date' => $vat['crossing_date'],
            'inputs' => [
                'gross_revenue' => $grossRevenue,
                'deductible_expenses' => $figures['deductible_expenses'],
                'days_elapsed' => $daysElapsed,
                'vat' => [
                    ...$vat,
                    'scope' => $ownerVat === null ? 'entity' : 'owner',
                    'own_revenue' => $grossRevenue,
                    'own_pct' => $this->percentOf($grossRevenue, (float) $vat['threshold']),
                ],
                'canton_code' => $personal?->inputs['canton_code'] ?? $entity->canton?->code,
                'share' => $share,
                'share_percent' => $this->sharePercentOf($entity, $personal),
                'personal_estimation_id' => $personal?->getKey(),
            ],
            'rates_snapshot' => $ratesSnapshot,
        ]);

        $this->syncVatAlert($entity, $vat);

        return $estimation;
    }

    /**
     * This business' share of the personal tax: its positive net income over
     * the sum of all positive net incomes (0 for a loss-making business or a
     * business that is not taxed on the person). Shares of all businesses add
     * up to exactly 1.
     */
    private function shareOf(BusinessEntity $entity, float $netIncome, ?TaxEstimation $personal): string
    {
        if ($personal === null || $netIncome <= 0) {
            return '0';
        }

        return IncomeShares::fractions($this->netIncomesOf($personal))[$entity->getKey()] ?? '0';
    }

    /**
     * This business' share in percent (one decimal), consistent with the
     * shares shown for its siblings so they add up to 100 %.
     */
    private function sharePercentOf(BusinessEntity $entity, ?TaxEstimation $personal): float
    {
        if ($personal === null) {
            return 0.0;
        }

        return IncomeShares::percentages($this->netIncomesOf($personal))[$entity->getKey()] ?? 0.0;
    }

    /**
     * @return array<array-key, float>
     */
    private function netIncomesOf(TaxEstimation $personal): array
    {
        return array_map(
            fn (array $figures): float => (float) $figures['net_income'],
            $personal->inputs['businesses'] ?? [],
        );
    }

    /**
     * The personal deduction lines scaled to this business' share, so the
     * business breakdown adds up: share of net income − deductions = taxable
     * income (derived from the scaled parts, never off by a rounding cent).
     *
     * @param  array<string, mixed>  $deductions
     * @return array<string, mixed>
     */
    private function scaledDeductions(TaxEstimation $personal, array $deductions, string $share): array
    {
        $scaled = [
            'net_income' => TaxResult::scaleAmount((float) $personal->net_income, $share),
            'ahv' => TaxResult::scaleAmount((float) ($deductions['ahv'] ?? $personal->ahv_deduction), $share),
            'pillar3a' => TaxResult::scaleAmount((float) ($deductions['pillar3a'] ?? 0), $share),
            'children' => TaxResult::scaleAmount((float) ($deductions['children'] ?? 0), $share),
            'other_income' => TaxResult::scaleAmount((float) ($deductions['other_income'] ?? 0), $share),
        ];

        $taxable = bcadd(
            bcsub(
                bcsub(bcsub($this->decimal($scaled['net_income']), $this->decimal($scaled['ahv']), 2), $this->decimal($scaled['pillar3a']), 2),
                $this->decimal($scaled['children']),
                2,
            ),
            $this->decimal($scaled['other_income']),
            2,
        );

        return [
            ...$deductions,
            ...$scaled,
            'taxable_income' => (float) $taxable,
            'share' => $share,
        ];
    }

    /**
     * Rebuild the calculator result from a persisted personal estimation.
     */
    private function personalResult(TaxEstimation $personal): TaxResult
    {
        $deductions = $personal->rates_snapshot['deductions'] ?? [];

        return new TaxResult(
            quellensteuerRegime: (bool) $personal->quellensteuer_regime,
            grossRevenue: (float) $personal->gross_revenue,
            totalExpenses: (float) $personal->total_expenses,
            netIncome: (float) $personal->net_income,
            ahvContribution: (float) $personal->ahv_contribution,
            ivContribution: (float) $personal->iv_contribution,
            eoContribution: (float) $personal->eo_contribution,
            totalSocialInsurance: (float) $personal->total_social_insurance,
            ahvDeduction: (float) $personal->ahv_deduction,
            taxableIncome: (float) $personal->taxable_income,
            federalTax: (float) $personal->federal_tax,
            cantonalTax: (float) $personal->cantonal_tax,
            communalTax: (float) $personal->communal_tax,
            churchTax: (float) $personal->church_tax,
            totalIncomeTax: (float) $personal->total_income_tax,
            totalTaxBurden: (float) $personal->total_tax_burden,
            monthlyReserve: (float) $personal->monthly_reserve,
            effectiveRate: (float) $personal->effective_rate,
            projectedAnnualRevenue: (float) $personal->projected_annual_revenue,
            projectedTotalTax: (float) $personal->projected_total_tax,
            projectedMonthlyReserve: (float) $personal->projected_monthly_reserve,
            lossYear: (bool) ($deductions['loss_year'] ?? ((float) $personal->net_income < 0)),
            ageExemptionApplied: (bool) ($deductions['age_exemption_applied'] ?? false),
            ratesSnapshot: $personal->rates_snapshot ?? [],
            pillar3aDeduction: (float) ($deductions['pillar3a'] ?? 0),
            childDeduction: (float) ($deductions['children'] ?? 0),
            minimumContributionApplied: (bool) ($deductions['minimum_contribution_applied'] ?? false),
        );
    }

    /**
     * Net revenue and deductible expenses per sole proprietorship of the user,
     * keyed by business id (two aggregate queries in total).
     *
     * @return Collection<string, array{name: string, net_revenue: float, deductible_expenses: float, net_income: float}>
     */
    private function businessFigures(User $user, int $fiscalYear): Collection
    {
        $businesses = $user->soleProprietorships()->orderBy('created_at')->get(['id', 'name']);
        $ids = $businesses->modelKeys();

        $revenue = Invoice::query()
            ->countsAsRevenue()
            ->whereIn('business_entity_id', $ids)
            ->whereYear('issue_date', $fiscalYear)
            ->groupBy('business_entity_id')
            ->selectRaw('business_entity_id, sum(subtotal) as aggregate')
            ->pluck('aggregate', 'business_entity_id');

        $expenses = Expense::query()
            ->whereIn('business_entity_id', $ids)
            ->where('status', ExpenseStatus::Reviewed->value)
            ->whereYear('expense_date', $fiscalYear)
            ->groupBy('business_entity_id')
            ->selectRaw('business_entity_id, sum(deductible_amount) as aggregate')
            ->pluck('aggregate', 'business_entity_id');

        return $businesses->mapWithKeys(function (BusinessEntity $business) use ($revenue, $expenses): array {
            $netRevenue = $this->decimal($revenue[$business->getKey()] ?? 0);
            $deductible = $this->decimal($expenses[$business->getKey()] ?? 0);

            return [$business->getKey() => [
                'name' => (string) $business->name,
                'net_revenue' => (float) $netRevenue,
                'deductible_expenses' => (float) $deductible,
                'net_income' => (float) bcsub($netRevenue, $deductible, 2),
            ]];
        });
    }

    /**
     * @return array{net_revenue: float, deductible_expenses: float, net_income: float}
     */
    private function figuresFor(BusinessEntity $entity, int $fiscalYear): array
    {
        $netRevenue = $this->decimal($entity->invoices()
            ->countsAsRevenue()
            ->whereYear('issue_date', $fiscalYear)
            ->sum('subtotal'));

        $deductible = $this->decimal($entity->expenses()
            ->where('status', ExpenseStatus::Reviewed->value)
            ->whereYear('expense_date', $fiscalYear)
            ->sum('deductible_amount'));

        return [
            'net_revenue' => (float) $netRevenue,
            'deductible_expenses' => (float) $deductible,
            'net_income' => (float) bcsub($netRevenue, $deductible, 2),
        ];
    }

    private function profileFor(User $user): ?TaxProfile
    {
        return $user->taxProfile()->with(['canton', 'commune'])->first();
    }

    private function cantonCodeFor(User $user, ?TaxProfile $profile): ?string
    {
        return $profile?->canton?->code
            ?? $user->canton?->code
            ?? $user->soleProprietorships()->with('canton')->oldest()->first()?->canton?->code;
    }

    private function cantonIdFor(User $user, ?TaxProfile $profile): ?string
    {
        return $profile?->canton_id
            ?? $user->canton_id
            ?? $user->soleProprietorships()->oldest()->value('canton_id');
    }

    /**
     * Persist the new alert level on the entity and, when it has escalated into
     * an actionable band, notify the owner (Filament DB notification) and
     * broadcast on the per-business channel. Guarded column written via
     * forceFill.
     *
     * @param  array<string, mixed>  $vat  a VatThresholdService evaluation
     */
    private function syncVatAlert(BusinessEntity $entity, array $vat): void
    {
        $newLevel = (string) $vat['level'];
        $previousLevel = $entity->vat_alert_level ?? 'none';

        if ($newLevel === $previousLevel) {
            return;
        }

        $entity->forceFill(['vat_alert_level' => $newLevel])->save();

        $newRank = VatAlertCopy::rank($newLevel);

        if ($newRank <= VatAlertCopy::rank($previousLevel) || ! VatAlertCopy::isActionable($newLevel)) {
            return;
        }

        $owner = $entity->owner;
        if ($owner === null) {
            return;
        }

        $notification = Notification::make()
            ->title(VatAlertCopy::title($newLevel))
            ->body(VatAlertCopy::body($vat))
            ->color(VatAlertCopy::color($newLevel));

        $taxUrl = $this->taxPageUrl($entity);
        if ($taxUrl !== null) {
            $notification->actions([
                Action::make('review')
                    ->label($newLevel === 'mandatory' ? 'Register for VAT' : 'Review VAT threshold')
                    ->url($taxUrl)
                    ->button(),
            ]);
        }

        $notification->sendToDatabase($owner);

        VatAlertRaised::dispatch($entity->getKey(), $newLevel, (float) $vat['progress_pct'], $vat['crossing_date'] ?? null);
    }

    /**
     * Resolve the tax overview URL for the notification CTA. Returns null when
     * the URL cannot be generated, in which case the notification is still
     * delivered without the deep link.
     */
    private function taxPageUrl(BusinessEntity $entity): ?string
    {
        try {
            return TaxOverview::getUrl(tenant: $entity, panel: 'workspace');
        } catch (Throwable) {
            return null;
        }
    }

    private function decimal(float|int|string|null $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * BCMath sum of one figure across the per-business rows.
     *
     * @param  Collection<string, array<string, mixed>>  $businesses
     */
    private function sumOf(Collection $businesses, string $key): float
    {
        return (float) $businesses->reduce(
            fn (string $carry, array $figures): string => bcadd($carry, $this->decimal($figures[$key] ?? 0), 2),
            '0.00',
        );
    }

    /**
     * $amount as a percentage of $base, to one decimal. Zero when there is no base.
     */
    private function percentOf(float $amount, float $base): float
    {
        if (bccomp($this->decimal($base), '0', 2) <= 0) {
            return 0.0;
        }

        return round((float) bcmul(bcdiv($this->decimal($amount), $this->decimal($base), 8), '100', 6), 1);
    }

    /**
     * Year-to-date figure projected to a full year from the days elapsed.
     */
    private function annualised(float $amount, int $daysElapsed): float
    {
        return round((float) bcdiv(bcmul($this->decimal($amount), '365', 6), (string) max(1, $daysElapsed), 6), 2);
    }

    /**
     * The day the figures start on: the Settlo start date of the account or the
     * business, pulled back to the earliest invoice or expense booked in the
     * fiscal year. Without that second half, importing a whole year of history
     * on the day you sign up would annualise it as one day's trading.
     *
     * @param  list<string>  $businessIds
     */
    private function activityStart(array $businessIds, int $fiscalYear, CarbonInterface|string|null $accountStart): ?string
    {
        $dates = array_filter([
            $accountStart === null ? null : Carbon::parse($accountStart)->toDateString(),
            $businessIds === [] ? null : Invoice::query()
                ->countsAsRevenue()
                ->whereIn('business_entity_id', $businessIds)
                ->whereYear('issue_date', $fiscalYear)
                ->min('issue_date'),
            $businessIds === [] ? null : Expense::query()
                ->whereIn('business_entity_id', $businessIds)
                ->where('status', ExpenseStatus::Reviewed->value)
                ->whereYear('expense_date', $fiscalYear)
                ->min('expense_date'),
        ]);

        return $dates === [] ? null : min(array_map(
            fn (string $date): string => Carbon::parse($date)->toDateString(),
            $dates,
        ));
    }

    /**
     * The largest single invoice that counts as revenue across the given
     * businesses — a single invoice at or above the threshold makes VAT
     * registration mandatory on its own.
     *
     * @param  list<string>  $businessIds
     */
    private function largestInvoiceOf(array $businessIds, int $fiscalYear): ?float
    {
        if ($businessIds === []) {
            return null;
        }

        $largest = (float) Invoice::query()
            ->countsAsRevenue()
            ->whereIn('business_entity_id', $businessIds)
            ->whereYear('issue_date', $fiscalYear)
            ->max('subtotal');

        return $largest > 0 ? $largest : null;
    }

    /**
     * The owner-level VAT evaluation stored on a personal estimate.
     *
     * @return array<string, mixed>|null
     */
    private function ownerVatOf(?TaxEstimation $personal): ?array
    {
        $vat = $personal?->inputs['vat'] ?? null;

        return is_array($vat) ? $vat : null;
    }

    private function currentFiscalYear(): int
    {
        return (int) config('settlo.current_fiscal_year', (int) date('Y'));
    }

    /**
     * Days of the fiscal year the figures cover, for annualisation. Counting
     * starts on 1 January, or later when the account or the business only began
     * during the year — annualising a half-year business from 1 January would
     * halve its projection (Settlo Tax Engine Algorithms v2.0, step 10).
     */
    private function daysElapsed(int $fiscalYear, CarbonInterface|string|null $startedAt = null): int
    {
        $start = Carbon::create($fiscalYear, 1, 1)->startOfDay();
        $end = Carbon::create($fiscalYear, 12, 31)->startOfDay();

        if ($startedAt !== null) {
            $started = Carbon::parse($startedAt)->startOfDay();

            if ($started->greaterThan($start) && $started->lessThanOrEqualTo($end)) {
                $start = $started;
            }
        }

        $today = Carbon::now()->startOfDay();
        $last = $today->greaterThan($end) ? $end : $today;

        if ($last->lessThan($start)) {
            return 1;
        }

        return max(1, (int) $start->diffInDays($last) + 1);
    }
}
