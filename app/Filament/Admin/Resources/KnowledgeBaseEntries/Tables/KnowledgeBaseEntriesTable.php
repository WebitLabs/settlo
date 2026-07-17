<?php

namespace App\Filament\Admin\Resources\KnowledgeBaseEntries\Tables;

use App\Enums\AiQuestionCategory;
use App\Models\KnowledgeBaseEntry;
use App\Services\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class KnowledgeBaseEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')
                    ->limit(60)
                    ->tooltip(fn (KnowledgeBaseEntry $record): string => $record->question)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color(fn (KnowledgeBaseEntry $record): string => $record->escalation_id !== null ? 'info' : 'gray')
                    ->state(fn (KnowledgeBaseEntry $record): string => $record->escalation_id !== null ? 'Escalation' : 'Manual'),
                IconColumn::make('approved')
                    ->label('Approved')
                    ->boolean()
                    ->state(fn (KnowledgeBaseEntry $record): bool => $record->approved_at !== null),
                TextColumn::make('approvedBy.name')
                    ->label('Approved by')
                    ->state(fn (KnowledgeBaseEntry $record): ?string => $record->approvedBy?->getFilamentName())
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(AiQuestionCategory::class),
                TernaryFilter::make('approved')
                    ->label('Approval')
                    ->placeholder('All entries')
                    ->trueLabel('Approved')
                    ->falseLabel('Drafts')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('approved_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('approved_at'),
                    ),
            ])
            ->recordActions([
                self::approveAction(),
                self::unapproveAction(),
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (KnowledgeBaseEntry $record): bool => $record->approved_at === null)
                    ->before(function (KnowledgeBaseEntry $record): void {
                        app(AuditLogger::class)->log('kb.deleted', $record, [
                            'question' => $record->question,
                        ]);
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Approve an entry so it becomes eligible for Ask-Settlo retrieval, stamping
     * the approving superadmin. The approval is recorded in the audit trail.
     */
    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve entry')
            ->modalDescription('The entry becomes active and may be surfaced to users.')
            ->visible(fn (KnowledgeBaseEntry $record): bool => $record->approved_at === null)
            ->action(function (KnowledgeBaseEntry $record): void {
                $record->forceFill([
                    'approved_by_id' => Auth::id(),
                    'approved_at' => now(),
                    'is_active' => true,
                ])->save();

                app(AuditLogger::class)->log('kb.approved', $record, [
                    'question' => $record->question,
                ]);

                Notification::make()->title('Entry approved')->success()->send();
            });
    }

    /**
     * Withdraw approval, deactivating the entry. Recorded in the audit trail.
     */
    private static function unapproveAction(): Action
    {
        return Action::make('unapprove')
            ->icon('heroicon-m-x-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Unapprove entry')
            ->modalDescription('The entry is deactivated and returns to draft state.')
            ->visible(fn (KnowledgeBaseEntry $record): bool => $record->approved_at !== null)
            ->action(function (KnowledgeBaseEntry $record): void {
                $record->forceFill([
                    'approved_by_id' => null,
                    'approved_at' => null,
                    'is_active' => false,
                ])->save();

                app(AuditLogger::class)->log('kb.unapproved', $record, [
                    'question' => $record->question,
                ]);

                Notification::make()->title('Entry unapproved')->success()->send();
            });
    }
}
