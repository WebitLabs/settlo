<?php

namespace App\Filament\Firm\Resources\Escalations\Tables;

use App\Enums\AiEscalationStatus;
use App\Enums\AiQuestionCategory;
use App\Models\AiEscalation;
use App\Models\KnowledgeBaseEntry;
use App\Services\Ai\EscalationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class EscalationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Raised')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('client')
                    ->label('Client')
                    ->state(fn (AiEscalation $record): ?string => $record->conversation?->businessEntity?->name)
                    ->searchable(false),
                TextColumn::make('user_question')
                    ->label('Question')
                    ->limit(60)
                    ->tooltip(fn (AiEscalation $record): ?string => $record->user_question)
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('sla_deadline')
                    ->label('SLA deadline')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->color(fn (AiEscalation $record): ?string => self::isBreached($record) ? 'danger' : null)
                    ->description(fn (AiEscalation $record): ?string => self::isBreached($record) ? 'Overdue' : null),
                TextColumn::make('accountant')
                    ->label('Claimed by')
                    ->state(fn (AiEscalation $record): ?string => $record->accountant?->getFilamentName())
                    ->placeholder('Unclaimed'),
            ])
            ->recordActions([
                self::claimAction(),
                self::answerAction(),
                ViewAction::make(),
            ]);
    }

    /**
     * An unanswered escalation whose SLA deadline has already passed.
     */
    private static function isBreached(AiEscalation $record): bool
    {
        return $record->sla_deadline !== null
            && $record->answered_at === null
            && $record->sla_deadline->isPast();
    }

    /**
     * Take ownership of a pending, unclaimed escalation. Claiming records the
     * accountant and firm on the escalation (guarded columns, forceFill) and
     * moves it into progress so answering becomes available.
     */
    private static function claimAction(): Action
    {
        return Action::make('claim')
            ->icon('heroicon-m-hand-raised')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Claim escalation')
            ->modalDescription('You will be recorded as the accountant handling this question.')
            ->visible(fn (AiEscalation $record): bool => $record->status === AiEscalationStatus::Pending
                && $record->accountant_id === null)
            ->action(function (AiEscalation $record): void {
                $record->forceFill([
                    'accountant_id' => Auth::id(),
                    'accounting_firm_id' => Filament::getTenant()->getKey(),
                    'status' => AiEscalationStatus::InProgress->value,
                ])->save();

                Notification::make()->title('Escalation claimed')->success()->send();
            });
    }

    /**
     * Answer a claimed escalation. Recording the reply runs through
     * EscalationService so the owner is notified and the change is broadcast;
     * optionally the verified Q&A is published to the knowledge base.
     */
    private static function answerAction(): Action
    {
        return Action::make('answer')
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->modalHeading('Answer escalation')
            ->visible(fn (AiEscalation $record): bool => $record->accountant_id === Auth::id()
                && ! in_array($record->status, [AiEscalationStatus::Answered, AiEscalationStatus::Closed], true))
            ->authorize(fn (AiEscalation $record): bool => Auth::user()->can('answer', $record))
            ->schema([
                Textarea::make('accountant_answer')
                    ->label('Answer to the client')
                    ->required()
                    ->rows(6),
                Textarea::make('accountant_notes')
                    ->label('Internal notes')
                    ->helperText('Not shown to the client.')
                    ->rows(3),
                Toggle::make('add_to_knowledge_base')
                    ->label('Add this answer to the knowledge base'),
            ])
            ->action(function (array $data, AiEscalation $record): void {
                app(EscalationService::class)->applyAnswer(
                    $record,
                    $data['accountant_answer'],
                    filled($data['accountant_notes'] ?? null) ? $data['accountant_notes'] : null,
                    Auth::user(),
                );

                if ($data['add_to_knowledge_base'] ?? false) {
                    self::publishToKnowledgeBase($record, $data['accountant_answer']);
                }

                Notification::make()->title('Answer sent to the client')->success()->send();
            });
    }

    /**
     * Persist the verified answer as an approved knowledge-base entry and flag
     * the escalation as published. All ids/flags are guarded (forceFill).
     */
    private static function publishToKnowledgeBase(AiEscalation $record, string $answer): void
    {
        $entry = new KnowledgeBaseEntry;
        $entry->forceFill([
            'escalation_id' => $record->getKey(),
            'category' => ($record->category ?? AiQuestionCategory::Other)->value,
            'question' => $record->user_question,
            'answer' => $answer,
            'approved_by_id' => Auth::id(),
            'approved_at' => Carbon::now(),
            'usage_count' => 0,
            'is_active' => true,
        ])->save();

        $record->forceFill([
            'add_to_knowledge_base' => true,
            'knowledge_base_approved_at' => Carbon::now(),
        ])->save();
    }
}
