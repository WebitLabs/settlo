<?php

namespace App\Filament\Personal\Widgets;

use App\Enums\PlanFeature;
use App\Filament\Personal\Pages\Billing;
use App\Filament\Personal\Pages\EditTaxProfile;
use App\Filament\Personal\Pages\PersonalTax;
use App\Models\User;
use App\Services\Reporting\BusinessMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * The consolidated personal tax estimate: total burden, monthly reserve,
 * effective rate and AHV/IV/EO. Available when any workspace includes the tax
 * engine; otherwise an upsell line.
 */
class PersonalTaxSummary extends Widget
{
    protected string $view = 'filament.personal.widgets.personal-tax-summary';

    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return Filament::auth()->user()?->isOwner() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $year = BusinessMetrics::currentYear();
        $hasTaxEngine = $user->hasFeatureInAnyWorkspace(PlanFeature::TaxEngine);
        $estimation = $hasTaxEngine ? $user->personalTaxEstimation($year) : null;
        $pending = $hasTaxEngine ? app(BusinessMetrics::class)->forUser($user, $year) : null;

        return [
            'hasTaxEngine' => $hasTaxEngine,
            'estimation' => $estimation,
            'businessCount' => count($estimation?->inputs['businesses'] ?? []),
            'pendingCount' => $pending?->pendingExpensesCount ?? 0,
            'personalTaxUrl' => PersonalTax::getUrl(panel: 'app'),
            'taxProfileUrl' => EditTaxProfile::getUrl(panel: 'app'),
            'billingUrl' => Billing::getUrl(panel: 'app'),
        ];
    }
}
