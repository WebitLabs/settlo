<?php

namespace App\Filament\Personal\Pages;

use App\Support\Greeting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The owner's home at /app: onboarding checklist, business workspaces,
 * consolidated financials and the personal tax summary.
 */
class PersonalDashboard extends Dashboard
{
    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->isOwner() ?? false;
    }

    public function getHeading(): string
    {
        $firstName = Filament::auth()->user()?->first_name;

        return trim(Greeting::now().($firstName ? ", {$firstName}" : ''));
    }

    public function getSubheading(): string
    {
        return 'Here\'s everything across your businesses.';
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
        return [
            Action::make('setUpBusiness')
                ->label('Set up a business')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->url(SetUpBusiness::getUrl()),
            Action::make('taxProfile')
                ->label('Tax profile')
                ->icon(Heroicon::OutlinedCalculator)
                ->color('gray')
                ->url(EditTaxProfile::getUrl()),
        ];
    }
}
