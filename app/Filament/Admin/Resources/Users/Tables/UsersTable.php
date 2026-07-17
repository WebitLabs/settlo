<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\ImpersonationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->state(fn (User $record): string => $record->getFilamentName())
                    ->searchable(['first_name', 'last_name'])
                    ->weight('medium'),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('role')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('ownedEntities_count')
                    ->label('Entities')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('firmMemberships_count')
                    ->label('Firms')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(UserRole::class),
                SelectFilter::make('status')
                    ->options(UserStatus::class),
            ])
            ->recordActions([
                self::impersonateAction(),
                self::suspendAction(),
                self::reactivateAction(),
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Suspend a user, blocking their panel access. Guarded against suspending
     * the acting superadmin themselves. The status change is written with
     * forceFill (guarded column) and recorded in the audit trail.
     */
    private static function suspendAction(): Action
    {
        return Action::make('suspend')
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Suspend user')
            ->modalDescription('The user will immediately lose access to their panel.')
            ->visible(fn (User $record): bool => $record->status !== UserStatus::Suspended
                && $record->getKey() !== Auth::id())
            ->action(function (User $record): void {
                $previous = $record->status;

                $record->forceFill(['status' => UserStatus::Suspended->value])->save();

                app(AuditLogger::class)->log('user.suspended', $record, [
                    'from' => $previous->value,
                    'to' => UserStatus::Suspended->value,
                ]);

                Notification::make()->title('User suspended')->success()->send();
            });
    }

    /**
     * Restore a suspended user to active status. Recorded in the audit trail.
     */
    private static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Reactivate user')
            ->visible(fn (User $record): bool => $record->status === UserStatus::Suspended)
            ->action(function (User $record): void {
                $previous = $record->status;

                $record->forceFill(['status' => UserStatus::Active->value])->save();

                app(AuditLogger::class)->log('user.reactivated', $record, [
                    'from' => $previous->value,
                    'to' => UserStatus::Active->value,
                ]);

                Notification::make()->title('User reactivated')->success()->send();
            });
    }

    /**
     * Impersonate a non-superadmin user. Starting impersonation audits the
     * transition and switches the authenticated session; the admin is then sent
     * to the panel that matches the target's role.
     */
    private static function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->icon('heroicon-m-user-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Impersonate user')
            ->modalDescription('You will be signed in as this user until you stop from the banner.')
            ->visible(fn (User $record): bool => $record->role !== UserRole::Superadmin
                && $record->getKey() !== Auth::id())
            ->action(function (User $record) {
                app(ImpersonationService::class)->start($record);

                $panel = $record->isAccountant() ? 'firm' : 'app';

                return redirect('/'.$panel);
            });
    }
}
