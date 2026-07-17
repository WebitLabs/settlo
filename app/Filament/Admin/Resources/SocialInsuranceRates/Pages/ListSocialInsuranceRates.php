<?php

namespace App\Filament\Admin\Resources\SocialInsuranceRates\Pages;

use App\Filament\Admin\Resources\SocialInsuranceRates\SocialInsuranceRateResource;
use Filament\Resources\Pages\ListRecords;

class ListSocialInsuranceRates extends ListRecords
{
    protected static string $resource = SocialInsuranceRateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
