<?php

namespace App\Filament\Workspace\Pages;

use App\Enums\PlanFeature;
use App\Models\BusinessEntity;
use App\Services\Reporting\BusinessMetrics;
use App\Services\Reporting\VatReturn;
use App\Services\Reporting\VatReturnPeriod;
use App\Services\Reporting\VatReturnResult;
use App\Services\Reporting\YearEndExportService;
use App\Support\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * The year-end books of the workspace: every invoice, every expense and the
 * totals that tie them together, downloadable as CSV for a Treuhänder or for
 * an accounting import.
 */
class YearEndExport extends Page
{
    protected string $view = 'filament.workspace.pages.year-end-export';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'Year-end export';

    protected static ?int $navigationSort = 6;

    /** The fiscal year being exported, kept in the URL so the view is shareable. */
    #[Url]
    public ?int $year = null;

    /**
     * A gated feature (Pro and above). The gate is enforced here, not merely
     * hidden in navigation, and again inside the download action.
     */
    public static function canAccess(): bool
    {
        return CurrentWorkspace::entity()?->hasFeature(PlanFeature::YearEndExport) ?? false;
    }

    public function mount(): void
    {
        $this->year ??= BusinessMetrics::currentYear();
    }

    public function getTitle(): string
    {
        return 'Year-end export';
    }

    public function getSubheading(): ?string
    {
        return 'Everything your accountant needs for the tax return, as CSV.';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_map(
            fn (string $dataset): Action => $this->downloadAction($dataset),
            YearEndExportService::DATASETS,
        );
    }

    /**
     * The three most recent fiscal years, newest first.
     *
     * @return array<int, string>
     */
    public function getYearOptions(): array
    {
        $current = BusinessMetrics::currentYear();

        return collect(range($current, $current - 2))
            ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
            ->all();
    }

    /**
     * The rows that will be exported, so the page can show what the download
     * contains before it is downloaded.
     *
     * @return array{invoices: int, expenses: int}
     */
    public function getCounts(): array
    {
        $entity = $this->entity();

        if ($entity === null) {
            return ['invoices' => 0, 'expenses' => 0];
        }

        $service = app(YearEndExportService::class);

        return [
            'invoices' => count($service->rows($entity, $this->fiscalYear(), YearEndExportService::INVOICES)),
            'expenses' => count($service->rows($entity, $this->fiscalYear(), YearEndExportService::EXPENSES)),
        ];
    }

    /**
     * The summary figures, exactly as the summary CSV writes them.
     *
     * @return list<list<string>>
     */
    public function getSummaryRows(): array
    {
        $entity = $this->entity();

        return $entity === null
            ? []
            : app(YearEndExportService::class)->rows($entity, $this->fiscalYear(), YearEndExportService::SUMMARY);
    }

    public function getVatReturn(): ?VatReturnResult
    {
        $entity = $this->entity();

        return $entity === null
            ? null
            : app(VatReturn::class)->forPeriod($entity, VatReturnPeriod::make($this->fiscalYear(), 'year'));
    }

    public function fiscalYear(): int
    {
        return (int) ($this->year ?: BusinessMetrics::currentYear());
    }

    /**
     * One download button per dataset. The plan gate is re-checked here: a
     * page action is a separate request, and access is never trusted to the
     * rendered UI.
     */
    private function downloadAction(string $dataset): Action
    {
        return Action::make("download_{$dataset}")
            ->label(ucfirst($dataset).' CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color($dataset === YearEndExportService::SUMMARY ? 'primary' : 'gray')
            ->action(fn (): StreamedResponse => $this->download($dataset));
    }

    private function download(string $dataset): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $entity = $this->entity();
        abort_if($entity === null, 403);

        return app(YearEndExportService::class)->download($entity, $this->fiscalYear(), $dataset);
    }

    private function entity(): ?BusinessEntity
    {
        $entity = Filament::getTenant();

        return $entity instanceof BusinessEntity ? $entity : null;
    }
}
