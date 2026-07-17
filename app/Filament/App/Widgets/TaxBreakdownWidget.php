<?php

namespace App\Filament\App\Widgets;

use App\Enums\PlanFeature;
use App\Filament\App\Pages\BusinessSettings;
use App\Models\BusinessEntity;
use App\Models\TaxEstimation;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Dashboard card summarising the latest tax estimation: income tax, social
 * insurance (AHV/IV/EO), VAT status, total burden and the monthly reserve.
 * Shows a "complete your tax profile" call to action when no estimation exists
 * yet. Strictly scoped to the active tenant.
 */
class TaxBreakdownWidget extends Widget
{
    protected string $view = 'filament.app.widgets.tax-breakdown';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    /**
     * Only surface the tax breakdown to plans that include the tax engine; the
     * gate mirrors TaxOverview so Solo users never see engine output.
     */
    public static function canView(): bool
    {
        return Filament::auth()->user()?->hasFeature(PlanFeature::TaxEngine) ?? false;
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
            'settingsUrl' => $entity instanceof BusinessEntity
                ? BusinessSettings::getUrl(tenant: $entity)
                : null,
            'vatLabel' => $estimation !== null ? $this->vatLabel($estimation) : null,
            'vatColor' => $estimation !== null ? $this->vatColor($estimation->vat_alert_level) : 'gray',
        ];
    }

    private function fiscalYear(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }

    private function vatLabel(TaxEstimation $estimation): string
    {
        return match ($estimation->vat_alert_level) {
            'mandatory' => 'Registration required',
            'critical' => 'Almost at threshold',
            'warning' => 'Approaching threshold',
            'info' => 'On track',
            default => 'Not VAT registered',
        };
    }

    private function vatColor(?string $level): string
    {
        return match ($level) {
            'mandatory' => 'danger',
            'critical' => 'warning',
            'warning' => 'warning',
            'info' => 'success',
            default => 'gray',
        };
    }
}
