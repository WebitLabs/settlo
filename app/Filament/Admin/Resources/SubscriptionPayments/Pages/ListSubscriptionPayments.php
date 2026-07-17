<?php

namespace App\Filament\Admin\Resources\SubscriptionPayments\Pages;

use App\Filament\Admin\Resources\SubscriptionPayments\SubscriptionPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionPayments extends ListRecords
{
    protected static string $resource = SubscriptionPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
