<?php

namespace App\Filament\Admin\Resources\AuditLogs\Pages;

use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;

    /**
     * Read-only: a recorded audit entry can never be edited.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
