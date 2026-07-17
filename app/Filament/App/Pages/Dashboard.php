<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Expenses\ExpenseResource;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * App-panel dashboard with a time-aware greeting header (Europe/Zurich) and the
 * always-visible "Upload receipt" and "New invoice" quick actions from the
 * backlog. Widgets are auto-discovered by the panel; this class only customises
 * the heading and header actions.
 */
class Dashboard extends BaseDashboard
{
    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    public function getHeading(): string
    {
        $firstName = Filament::auth()->user()?->first_name;

        return trim($this->greeting().($firstName ? ", {$firstName}" : ''));
    }

    /**
     * Time-of-day greeting anchored to Swiss local time, independent of the
     * server timezone.
     */
    private function greeting(): string
    {
        $hour = (int) Carbon::now('Europe/Zurich')->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();

        return [
            Action::make('uploadReceipt')
                ->label('Upload receipt')
                ->icon('heroicon-m-arrow-up-tray')
                ->color('gray')
                ->outlined()
                ->url(ExpenseResource::getUrl('create', tenant: $tenant)),
            Action::make('newInvoice')
                ->label('New invoice')
                ->icon('heroicon-m-plus')
                ->url(InvoiceResource::getUrl('create', tenant: $tenant)),
        ];
    }
}
