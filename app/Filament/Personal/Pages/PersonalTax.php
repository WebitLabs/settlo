<?php

namespace App\Filament\Personal\Pages;

use App\Enums\PlanFeature;
use App\Jobs\RecalculatePersonalTaxEstimation;
use App\Models\TaxEstimation;
use App\Models\User;
use App\Services\Reporting\BusinessMetrics;
use App\Services\Tax\IncomeShares;
use App\Services\Tax\TaxEngine;
use App\Services\Tax\TaxResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The owner's consolidated personal tax estimate across all sole
 * proprietorships: breakdown, per-business shares and a canton comparison.
 * Content is shown when any workspace includes the tax engine.
 */
class PersonalTax extends Page
{
    protected string $view = 'filament.personal.pages.personal-tax';

    protected static ?string $slug = 'tax';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static ?string $navigationLabel = 'Personal tax';

    protected static ?int $navigationSort = 3;

    /**
     * Cantons always included in the comparison (plus the owner's own).
     *
     * @var list<string>
     */
    private const array COMPARISON_CANTONS = ['ZG', 'ZH', 'LU', 'BE', 'GE', 'JU'];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->isOwner() ?? false;
    }

    public function getTitle(): string
    {
        return 'Personal tax';
    }

    public function getSubheading(): string
    {
        return 'Income tax and AHV are levied on you, so all of your sole proprietorships are added up.';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculate')
                ->label('Recalculate')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => $this->hasTaxEngine())
                ->action(function (): void {
                    $user = $this->user();
                    abort_unless($this->hasTaxEngine(), 403);

                    app(TaxEngine::class)->estimateForUser($user, $this->year());
                    RecalculatePersonalTaxEstimation::dispatch($user->getKey(), $this->year());

                    Notification::make()
                        ->title('Personal tax estimate updated')
                        ->body('Your businesses are being updated too.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function hasTaxEngine(): bool
    {
        return $this->user()->hasFeatureInAnyWorkspace(PlanFeature::TaxEngine);
    }

    public function getEstimation(): ?TaxEstimation
    {
        return $this->user()->personalTaxEstimation($this->year());
    }

    /**
     * The per-business rows of the personal estimate with each business' share
     * of the positive self-employment income (shares add up to 100 %).
     *
     * @return list<array{name: string, net_revenue: float, deductible_expenses: float, net_income: float, share: float}>
     */
    public function getBusinessRows(?TaxEstimation $estimation): array
    {
        $businesses = collect($estimation?->inputs['businesses'] ?? []);
        $shares = IncomeShares::percentages($businesses->map(fn (array $row): float => (float) $row['net_income'])->all());

        return $businesses
            ->map(fn (array $row, string|int $id): array => [
                'name' => (string) $row['name'],
                'net_revenue' => (float) $row['net_revenue'],
                'deductible_expenses' => (float) $row['deductible_expenses'],
                'net_income' => (float) $row['net_income'],
                'share' => $shares[$id] ?? 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * The owner-level VAT threshold evaluation stored on the personal estimate.
     * The threshold is levied on the person, so it is evaluated once on the
     * consolidated revenue of every sole proprietorship. Null for estimations
     * written before it was snapshotted.
     *
     * @return array<string, mixed>|null
     */
    public function getVatEvaluation(?TaxEstimation $estimation): ?array
    {
        $vat = $estimation?->inputs['vat'] ?? null;

        return is_array($vat) ? $vat : null;
    }

    /**
     * The consolidated tax burden in other cantons, cheapest first.
     *
     * @return array<string, TaxResult>
     */
    public function getComparison(): array
    {
        $codes = self::COMPARISON_CANTONS;
        $current = $this->currentCantonCode();

        if ($current !== null) {
            array_unshift($codes, $current);
        }

        $results = app(TaxEngine::class)->compareCantons($this->user(), $codes, $this->year());
        uasort($results, fn (TaxResult $a, TaxResult $b): int => $a->totalTaxBurden <=> $b->totalTaxBurden);

        return $results;
    }

    public function currentCantonCode(): ?string
    {
        $user = $this->user();

        return $user->taxProfile?->canton?->code ?? $user->canton?->code;
    }

    /**
     * Whether the communal tax uses an estimated multiplier: no commune is
     * chosen (canton default) or its real Steuerfuss is not imported yet.
     */
    public function isCommunalMultiplierEstimated(): bool
    {
        $commune = $this->user()->taxProfile?->commune;

        return $commune === null || $commune->multiplier_is_estimated;
    }

    public function getPendingExpensesCount(): int
    {
        return app(BusinessMetrics::class)->forUser($this->user(), $this->year())->pendingExpensesCount;
    }

    public function getBillingUrl(): string
    {
        return Billing::getUrl();
    }

    public function getTaxProfileUrl(): string
    {
        return EditTaxProfile::getUrl();
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function year(): int
    {
        return BusinessMetrics::currentYear();
    }
}
