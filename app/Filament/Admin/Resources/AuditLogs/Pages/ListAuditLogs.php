<?php

namespace App\Filament\Admin\Resources\AuditLogs\Pages;

use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /**
     * Append-only: the audit log is never created from a panel.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
