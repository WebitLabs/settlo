<?php

namespace App\Filament\Personal\Resources\Businesses\Pages;

use App\Filament\Personal\Pages\SetUpBusiness;
use App\Filament\Personal\Resources\Businesses\BusinessResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBusinesses extends ListRecords
{
    protected static string $resource = BusinessResource::class;

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
        ];
    }
}
