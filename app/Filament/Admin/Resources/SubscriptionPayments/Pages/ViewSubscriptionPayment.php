<?php

namespace App\Filament\Admin\Resources\SubscriptionPayments\Pages;

use App\Filament\Admin\Resources\SubscriptionPayments\SubscriptionPaymentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSubscriptionPayment extends ViewRecord
{
    protected static string $resource = SubscriptionPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
