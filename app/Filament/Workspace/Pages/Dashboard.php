<?php

namespace App\Filament\Workspace\Pages;

use App\Filament\Workspace\Resources\Clients\ClientResource;
use App\Filament\Workspace\Resources\Expenses\ExpenseResource;
use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Models\BusinessEntity;
use App\Support\Greeting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use UnitEnum;

/**
 * Workspace dashboard: the business name as heading, a time-aware greeting
 * (Europe/Zurich) and the business type as subheading, and the "Upload
 * receipt", "New client" and "New invoice" quick actions. Widgets are
 * auto-discovered by the panel.
 */
class Dashboard extends BaseDashboard
{
    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    public function getHeading(): string
    {
        return (string) (Filament::getTenant()?->name ?? 'Dashboard');
    }

    public function getSubheading(): string
    {
        $firstName = Filament::auth()->user()?->first_name;
        $tenant = Filament::getTenant();
        $greeting = trim(Greeting::now().($firstName ? ", {$firstName}" : ''));
        $type = $tenant instanceof BusinessEntity ? $tenant->type?->getLabel() : null;

        return $type !== null ? "{$greeting} · {$type}" : $greeting;
    }

    /**
     * @return int|array<string, int>
     */
    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
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
            Action::make('newClient')
                ->label('New client')
                ->icon('heroicon-m-user-plus')
                ->color('gray')
                ->outlined()
                ->url(ClientResource::getUrl('create', tenant: $tenant)),
            Action::make('newInvoice')
                ->label('New invoice')
                ->icon('heroicon-m-plus')
                ->url(InvoiceResource::getUrl('create', tenant: $tenant)),
        ];
    }
}
