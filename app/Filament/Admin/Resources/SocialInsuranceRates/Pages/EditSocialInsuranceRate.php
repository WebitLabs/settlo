<?php

namespace App\Filament\Admin\Resources\SocialInsuranceRates\Pages;

use App\Filament\Admin\Resources\Concerns\AuditsTaxConfigEdit;
use App\Filament\Admin\Resources\SocialInsuranceRates\SocialInsuranceRateResource;
use Filament\Resources\Pages\EditRecord;

class EditSocialInsuranceRate extends EditRecord
{
    use AuditsTaxConfigEdit;

    protected static string $resource = SocialInsuranceRateResource::class;

    protected function taxConfigTable(): string
    {
        return 'social_insurance_rates';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
