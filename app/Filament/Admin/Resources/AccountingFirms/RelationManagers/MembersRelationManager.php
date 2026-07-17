<?php

namespace App\Filament\Admin\Resources\AccountingFirms\RelationManagers;

use App\Models\AccountingFirmMember;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only roster of the firm's team members.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Team';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Name')
                    ->state(fn (AccountingFirmMember $record): ?string => $record->user?->getFilamentName()),
                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),
                IconColumn::make('is_owner')
                    ->label('Owner')
                    ->boolean(),
                TextColumn::make('joined_at')
                    ->label('Joined')
                    ->dateTime('d.m.Y')
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
