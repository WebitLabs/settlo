<?php

namespace App\Filament\App\Pages;

use App\Enums\PlanFeature;
use App\Models\TaxEstimation;
use App\Services\Tax\TaxEngine;
use App\Services\Tax\TaxResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class TaxOverview extends Page
{
    protected string $view = 'filament.app.pages.tax-overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Bookkeeping';

    protected static ?string $navigationLabel = 'Tax estimate';

    protected static ?int $navigationSort = 3;

    /**
     * The tax engine is a gated feature (not available on the Solo plan). The
     * gate is enforced here, not merely hidden in navigation.
     */
    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasFeature(PlanFeature::TaxEngine) ?? false;
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
     * @return array<string, TaxResult>
     */
    public function getComparison(): array
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            return [];
        }

        $codes = ['ZG', 'ZH', 'LU', 'BE', 'GE', 'JU'];
        $current = $entity->taxProfile?->canton?->code ?? $entity->canton?->code;
        if ($current !== null) {
            array_unshift($codes, $current);
        }

        $results = app(TaxEngine::class)->compareCantons($entity, $codes, $this->year());
        uasort($results, fn (TaxResult $a, TaxResult $b) => $a->totalTaxBurden <=> $b->totalTaxBurden);

        return $results;
    }

    public function currentCantonCode(): ?string
    {
        $entity = Filament::getTenant();

        return $entity?->taxProfile?->canton?->code ?? $entity?->canton?->code;
    }

    private function year(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }
}
