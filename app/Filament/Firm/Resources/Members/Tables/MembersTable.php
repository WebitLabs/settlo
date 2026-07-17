<?php

namespace App\Filament\Firm\Resources\Members\Tables;

use App\Filament\Firm\Resources\Members\MemberResource;
use App\Models\AccountingFirmMember;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user_name')
                    ->label('Name')
                    ->state(fn (AccountingFirmMember $record): ?string => $record->user?->getFilamentName())
                    ->searchable(false),
                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable(),
                IconColumn::make('is_owner')
                    ->label('Owner')
                    ->boolean(),
                TextColumn::make('joined_at')
                    ->label('Joined')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->recordActions([
                self::toggleOwnerAction(),
                self::removeAction(),
            ])
            ->defaultSort('joined_at', 'asc');
    }

    /**
     * Promote a member to owner or demote them. Demotion is blocked when it
     * would leave the firm with no owners. Firm-owner gate is re-checked.
     */
    private static function toggleOwnerAction(): Action
    {
        return Action::make('toggleOwner')
            ->label(fn (AccountingFirmMember $record): string => $record->is_owner ? 'Revoke owner' : 'Make owner')
            ->icon('heroicon-m-key')
            ->requiresConfirmation()
            ->visible(fn (): bool => MemberResource::currentUserIsFirmOwner())
            ->action(function (AccountingFirmMember $record): void {
                if (! MemberResource::currentUserIsFirmOwner()) {
                    Notification::make()->title('Only firm owners can change roles')->danger()->send();

                    return;
                }

                if ($record->is_owner && MemberResource::ownerCount() <= 1) {
                    Notification::make()->title('The firm must keep at least one owner')->danger()->send();

                    return;
                }

                $record->forceFill(['is_owner' => ! $record->is_owner])->save();

                Notification::make()->title('Role updated')->success()->send();
            });
    }

    /**
     * Remove a member. A user cannot remove themselves, and the last owner
     * cannot be removed. Firm-owner gate is re-checked.
     */
    private static function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Remove team member')
            ->visible(fn (AccountingFirmMember $record): bool => MemberResource::currentUserIsFirmOwner()
                && $record->user_id !== Auth::id())
            ->action(function (AccountingFirmMember $record): void {
                if (! MemberResource::currentUserIsFirmOwner()) {
                    Notification::make()->title('Only firm owners can remove members')->danger()->send();

                    return;
                }

                if ($record->user_id === Auth::id()) {
                    Notification::make()->title('You cannot remove yourself')->danger()->send();

                    return;
                }

                if ($record->is_owner && MemberResource::ownerCount() <= 1) {
                    Notification::make()->title('The firm must keep at least one owner')->danger()->send();

                    return;
                }

                $record->delete();

                Notification::make()->title('Team member removed')->success()->send();
            });
    }
}
