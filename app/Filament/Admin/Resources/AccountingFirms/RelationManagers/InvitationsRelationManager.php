<?php

namespace App\Filament\Admin\Resources\AccountingFirms\RelationManagers;

use App\Models\FirmClientInvitation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only list of the firm's client invitations.
 */
class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    protected static ?string $title = 'Client invitations';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('invitedBy.email')
                    ->label('Invited by')
                    ->placeholder('—'),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->state(fn (FirmClientInvitation $record): string => match (true) {
                        $record->accepted_at !== null => 'Accepted',
                        $record->isExpired() => 'Expired',
                        default => 'Pending',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Accepted' => 'success',
                        'Expired' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('d.m.Y')
                    ->placeholder('—'),
                TextColumn::make('accepted_at')
                    ->label('Accepted')
                    ->dateTime('d.m.Y')
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
