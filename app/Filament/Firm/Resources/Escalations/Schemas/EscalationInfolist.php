<?php

namespace App\Filament\Firm\Resources\Escalations\Schemas;

use App\Models\AiEscalation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EscalationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Question')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('client')
                            ->label('Client')
                            ->state(fn (AiEscalation $record): ?string => $record->conversation?->businessEntity?->name),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('user_question')
                            ->label('Client question')
                            ->columnSpanFull(),
                        TextEntry::make('ai_answer')
                            ->label('Ask Settlo answer')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),
                Section::make('Accountant response')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('accountant.name')
                            ->label('Handled by')
                            ->state(fn (AiEscalation $record): ?string => $record->accountant?->getFilamentName())
                            ->placeholder('Unclaimed'),
                        TextEntry::make('answered_at')
                            ->label('Answered')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('accountant_answer')
                            ->label('Answer to the client')
                            ->columnSpanFull()
                            ->placeholder('Not answered yet'),
                        TextEntry::make('accountant_notes')
                            ->label('Internal notes')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),
                Section::make('SLA')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('sla_deadline')
                            ->label('Deadline')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('sla_breached')
                            ->label('Breached')
                            ->badge()
                            ->state(fn (AiEscalation $record): string => $record->sla_breached ? 'Yes' : 'No')
                            ->color(fn (string $state): string => $state === 'Yes' ? 'danger' : 'gray'),
                        TextEntry::make('resolved_at')
                            ->label('Resolved by client')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('Not resolved'),
                    ]),
            ]);
    }
}
