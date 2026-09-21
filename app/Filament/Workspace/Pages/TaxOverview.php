<?php

namespace App\Filament\Workspace\Pages;

use App\Enums\PlanFeature;
use App\Filament\Personal\Pages\PersonalTax;
use App\Filament\Support\PendingExpensesNotice;
use App\Models\TaxEstimation;
use App\Services\Tax\TaxEngine;
use App\Services\Tax\VatAlertCopy;
use App\Support\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The workspace's tax estimate: this business' share of the owner's personal
 * tax (see the personal "Personal tax" page for the consolidated figures and
 * the canton comparison).
 */
class TaxOverview extends Page
{
    protected string $view = 'filament.workspace.pages.tax-overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'Tax estimate';

    protected static ?int $navigationSort = 3;

    /**
     * The tax engine is a gated feature (not available on the Solo plan). The
     * gate is enforced here, not merely hidden in navigation.
     */
    public static function canAccess(): bool
    {
        return CurrentWorkspace::entity()?->hasFeature(PlanFeature::TaxEngine) ?? false;
    }

    public function getTitle(): string
    {
        return 'Tax estimate';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculate')
                ->label('Recalculate')
                ->icon('heroicon-m-arrow-path')
                ->action(function (): void {
                    app(TaxEngine::class)->estimateFor(Filament::getTenant());
                    Notification::make()->title('Tax estimate updated')->success()->send();
                }),
        ];
    }

    public function getEstimation(): ?TaxEstimation
    {
        return Filament::getTenant()?->latestTaxEstimation($this->year());
    }

    /**
     * This business' share of the owner's self-employment income, in percent
     * (one decimal; the shares of all businesses add up to 100 %).
     */
    public function getSharePercent(): string
    {
        return self::formatSharePercent($this->getEstimation());
    }

    /**
     * The stored share percent without a trailing ".0" ("25", "33.4"). Falls
     * back to the fraction for estimations stored before share_percent existed.
     */
    public static function formatSharePercent(?TaxEstimation $estimation): string
    {
        $percent = (float) ($estimation?->inputs['share_percent'] ?? round((float) ($estimation?->inputs['share'] ?? 0) * 100, 1));

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');
    }

    /**
     * The VAT threshold evaluation behind this workspace's figures. For a sole
     * proprietorship it is the owner's consolidated one (the threshold is per
     * person) and carries this workspace's own contribution alongside.
     *
     * @return array<string, mixed>
     */
    public function getVatEvaluation(): array
    {
        $estimation = $this->getEstimation();
        $vat = $estimation?->inputs['vat'] ?? null;

        if (is_array($vat)) {
            return $vat;
        }

        return [
            'level' => $estimation?->vat_alert_level ?? 'none',
            'progress_pct' => (float) ($estimation?->vat_threshold_pct ?? 0),
            'crossing_date' => $estimation?->vat_crossing_date?->toDateString(),
            'threshold' => 100000,
        ];
    }

    /**
     * The escalating VAT message for the current band, or null below the first
     * actionable band.
     */
    public function getVatMessage(): ?string
    {
        $vat = $this->getVatEvaluation();

        return VatAlertCopy::rank($vat['level'] ?? 'none') >= VatAlertCopy::RANK['info']
            ? VatAlertCopy::body($vat)
            : null;
    }

    public function getPersonalTaxUrl(): string
    {
        return PersonalTax::getUrl(panel: 'app');
    }

    /**
     * Whether the communal tax uses an estimated multiplier: no commune is chosen
     * (canton default) or the commune's real Steuerfuss is not imported yet.
     */
    public function isCommunalMultiplierEstimated(): bool
    {
        $commune = Filament::getTenant()?->ownerTaxProfile()?->commune;

        return $commune === null || $commune->multiplier_is_estimated;
    }

    private function year(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }

    /**
     * Expenses still awaiting confirmation are excluded here; the page says so.
     *
     * @return array{count: int, gross: string, url: string}|null
     */
    public function getPendingExpensesNotice(): ?array
    {
        return PendingExpensesNotice::forCurrentTenant($this->year());
    }
}
