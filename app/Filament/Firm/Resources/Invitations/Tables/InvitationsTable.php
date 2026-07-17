<?php

namespace App\Filament\Firm\Resources\Invitations\Tables;

use App\Models\FirmClientInvitation;
use App\Services\Firm\FirmInvitationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Client email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (FirmClientInvitation $record): string => self::statusLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Accepted' => 'success',
                        'Pending' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->recordActions([
                self::resendAction(),
                DeleteAction::make()
                    ->label('Revoke')
                    ->modalHeading('Revoke invitation')
                    ->visible(fn (FirmClientInvitation $record): bool => $record->isPending()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function statusLabel(FirmClientInvitation $record): string
    {
        if ($record->accepted_at !== null) {
            return 'Accepted';
        }

        return $record->expires_at->isPast() ? 'Expired' : 'Pending';
    }

    /**
     * Rotate the token and re-send. Only meaningful while an invitation is still
     * pending (unaccepted); an accepted invitation is done.
     */
    private static function resendAction(): Action
    {
        return Action::make('resend')
            ->icon('heroicon-m-arrow-path')
            ->requiresConfirmation()
            ->modalHeading('Resend invitation')
            ->modalDescription('A new link is generated and emailed; the previous link stops working.')
            ->visible(fn (FirmClientInvitation $record): bool => $record->accepted_at === null)
            ->action(function (FirmClientInvitation $record): void {
                app(FirmInvitationService::class)->resend($record);

                Notification::make()->title('Invitation re-sent')->success()->send();
            });
    }
}
