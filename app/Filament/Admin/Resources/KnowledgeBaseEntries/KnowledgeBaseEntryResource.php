<?php

namespace App\Filament\Admin\Resources\KnowledgeBaseEntries;

use App\Filament\Admin\Resources\KnowledgeBaseEntries\Pages\EditKnowledgeBaseEntry;
use App\Filament\Admin\Resources\KnowledgeBaseEntries\Pages\ListKnowledgeBaseEntries;
use App\Filament\Admin\Resources\KnowledgeBaseEntries\Schemas\KnowledgeBaseEntryForm;
use App\Filament\Admin\Resources\KnowledgeBaseEntries\Tables\KnowledgeBaseEntriesTable;
use App\Models\KnowledgeBaseEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Curation surface for the Ask-Settlo knowledge base. Entries are seeded from
 * resolved escalations (or seed data); superadmins approve/unapprove them,
 * correct the question/answer text, and delete drafts. Entries are never
 * created here, and an approved entry may not be deleted.
 */
class KnowledgeBaseEntryResource extends Resource
{
    protected static ?string $model = KnowledgeBaseEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Knowledge base';

    protected static ?string $modelLabel = 'Knowledge base entry';

    protected static ?string $pluralModelLabel = 'Knowledge base';

    protected static ?string $recordTitleAttribute = 'question';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return KnowledgeBaseEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeBaseEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeBaseEntries::route('/'),
            'edit' => EditKnowledgeBaseEntry::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Only unapproved drafts may be deleted; an approved entry is retained.
     */
    public static function canDelete(Model $record): bool
    {
        return $record->approved_at === null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['approvedBy']);
    }
}
