<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * Users are provisioned through onboarding, never created from the admin
     * panel.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
