<?php

namespace App\Filament\Workspace\Widgets;

use App\Enums\PlanFeature;
use App\Filament\Personal\Pages\EditTaxProfile;
use App\Filament\Personal\Pages\PersonalTax;
use App\Filament\Support\PendingExpensesNotice;
use App\Filament\Workspace\Pages\TaxOverview;
use App\Models\BusinessEntity;
use App\Models\TaxEstimation;
use App\Services\Tax\VatAlertCopy;
use App\Support\CurrentWorkspace;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Dashboard card summarising this business' share of the owner's personal tax
 * estimate: income tax, social insurance (AHV/IV/EO), VAT status, total burden
 * and the monthly reserve, with a link to the full personal tax.
 * Shows a "complete your tax profile" call to action when no estimation exists
 * yet. Strictly scoped to the active tenant.
 */
class TaxBreakdownWidget extends Widget
{
    protected string $view = 'filament.workspace.widgets.tax-breakdown';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 1;

    /**
     * Only surface the tax breakdown to plans that include the tax engine; the
     * gate mirrors TaxOverview so Solo users never see engine output.
     */
    public static function canView(): bool
    {
        $tenant = Filament::getTenant() ?? CurrentWorkspace::entity();

        return $tenant instanceof BusinessEntity && $tenant->hasFeature(PlanFeature::TaxEngine);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $entity = Filament::getTenant();
        $estimation = $entity instanceof BusinessEntity
            ? $entity->latestTaxEstimation($this->fiscalYear())
            : null;

        return [
            'estimation' => $estimation,
            'settingsUrl' => EditTaxProfile::getUrl(panel: 'app'),
            'personalTaxUrl' => PersonalTax::getUrl(panel: 'app'),
            'sharePercent' => TaxOverview::formatSharePercent($estimation),
            'vatLabel' => $estimation !== null ? VatAlertCopy::status($estimation->vat_alert_level) : null,
            'vatColor' => $estimation !== null ? VatAlertCopy::color($estimation->vat_alert_level) : 'gray',
            'vatMessage' => $estimation !== null ? $this->vatMessage($estimation) : null,
            'pendingNotice' => PendingExpensesNotice::forCurrentTenant($this->fiscalYear()),
        ];
    }

    private function fiscalYear(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }

    /**
     * The escalating VAT message for the stored band, shown only once the
     * threshold is actually worth acting on.
     */
    private function vatMessage(TaxEstimation $estimation): ?string
    {
        $vat = $estimation->inputs['vat'] ?? null;

        if (! is_array($vat) || VatAlertCopy::rank($estimation->vat_alert_level) < VatAlertCopy::RANK['info']) {
            return null;
        }

        return VatAlertCopy::body($vat);
    }
}
