<?php

namespace App\Filament\Admin\Resources\AccountingFirms\RelationManagers;

use App\Models\AccountantAssignment;
use App\Services\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The firm's client assignments. Read-only except for a single guarded action:
 * revoking an accountant's access to a client. Revocation is a soft close
 * (revoked_at stamp) so the audit history stays intact, and every revoke writes
 * an assignment.revoked audit row.
 */
class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Client assignments';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('businessEntity.name')
                    ->label('Client entity')
                    ->searchable(),
                TextColumn::make('accountant.email')
                    ->label('Accountant')
                    ->searchable(),
                TextColumn::make('assigned_at')
                    ->label('Assigned')
                    ->dateTime('d.m.Y')
                    ->placeholder('—'),
                TextColumn::make('revoked_at')
                    ->label('Revoked')
                    ->dateTime('d.m.Y')
                    ->placeholder('—')
                    ->color('danger'),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label('Active only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('revoked_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('revoked_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->headerActions([])
            ->recordActions([
                self::revokeAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('assigned_at', 'desc');
    }

    /**
     * Revoke an active assignment, immediately cutting the accountant's access to
     * that client. Guarded column written with forceFill and audited.
     */
    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->icon('heroicon-m-user-minus')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Revoke assignment')
            ->modalDescription('The accountant will immediately lose access to this client.')
            ->visible(fn (AccountantAssignment $record): bool => $record->isActive())
            ->action(function (AccountantAssignment $record): void {
                $record->forceFill(['revoked_at' => Carbon::now()])->save();

                app(AuditLogger::class)->log('assignment.revoked', $record, [
                    'business_entity_id' => $record->business_entity_id,
                    'accountant_id' => $record->accountant_id,
                    'accounting_firm_id' => $record->accounting_firm_id,
                ]);

                Notification::make()->title('Assignment revoked')->success()->send();
            });
    }
}
