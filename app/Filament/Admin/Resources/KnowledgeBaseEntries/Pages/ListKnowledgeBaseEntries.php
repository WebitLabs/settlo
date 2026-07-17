<?php

namespace App\Filament\Admin\Resources\KnowledgeBaseEntries\Pages;

use App\Filament\Admin\Resources\KnowledgeBaseEntries\KnowledgeBaseEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeBaseEntries extends ListRecords
{
    protected static string $resource = KnowledgeBaseEntryResource::class;

    /**
     * Entries originate from escalations or seeds, never a manual create.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
