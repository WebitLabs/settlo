<?php

namespace App\Filament\Workspace\Pages;

use App\Enums\PlanFeature;
use App\Filament\Support\PendingExpensesNotice;
use App\Models\BusinessEntity;
use App\Services\Reporting\VatReturn;
use App\Services\Reporting\VatReturnPeriod;
use App\Services\Reporting\VatReturnResult;
use App\Support\CurrentWorkspace;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The VAT declaration (Swiss Form 300) for one reporting period: turnover and
 * output VAT per rate, input VAT per rate, and the net amount payable to (or
 * refundable by) the AFC.
 *
 * It prepares the figures — the return itself is still filed on the AFC portal.
 */
class VatDeclaration extends Page
{
    protected string $view = 'filament.workspace.pages.vat-declaration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'VAT declaration';

    protected static ?int $navigationSort = 5;

    /** The selected reporting period, kept in the URL so the view is shareable. */
    #[Url]
    public string $period = '';

    /** The selected fiscal year. */
    #[Url]
    public ?int $year = null;

    /**
     * A gated feature. The gate is enforced here, not merely hidden in
     * navigation, exactly like the tax engine.
     */
    public static function canAccess(): bool
    {
        return CurrentWorkspace::entity()?->hasFeature(PlanFeature::VatForm300) ?? false;
    }

    public function mount(): void
    {
        $default = VatReturnPeriod::currentQuarter(Carbon::create($this->fiscalYear(), Carbon::today()->month, 1));

        if ($this->period === '' || ! array_key_exists($this->period, VatReturnPeriod::options())) {
            $this->period = $default->key;
        }

        $this->year ??= $this->fiscalYear();
    }

    public function getTitle(): string
    {
        return 'VAT declaration (Form 300)';
    }

    public function getSubheading(): ?string
    {
        return 'Prepare the figures for your MWST return — you still file it on the AFC portal.';
    }

    /**
     * @return array<string, string>
     */
    public function getPeriodOptions(): array
    {
        return VatReturnPeriod::options();
    }

    /**
     * The three most recent fiscal years, newest first.
     *
     * @return array<int, string>
     */
    public function getYearOptions(): array
    {
        $current = $this->fiscalYear();

        return collect(range($current, $current - 2))
            ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
            ->all();
    }

    public function isRegistered(): bool
    {
        return Filament::getTenant()?->isVatRegistered() ?? false;
    }

    public function getReturn(): ?VatReturnResult
    {
        $entity = Filament::getTenant();

        if (! $entity instanceof BusinessEntity) {
            return null;
        }

        return app(VatReturn::class)->forPeriod(
            $entity,
            VatReturnPeriod::make((int) ($this->year ?: $this->fiscalYear()), $this->period),
        );
    }

    public function fiscalYear(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }

    /**
     * Expenses still awaiting confirmation carry no input VAT here; the page
     * says so rather than silently understating the deduction.
     *
     * @return array{count: int, gross: string, url: string}|null
     */
    public function getPendingExpensesNotice(): ?array
    {
        return PendingExpensesNotice::forCurrentTenant((int) ($this->year ?: $this->fiscalYear()));
    }
}
