<?php

namespace App\Filament\Admin\Resources\Escalations\Schemas;

use App\Models\AiEscalation;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EscalationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Context')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('entity')
                            ->label('Business')
                            ->state(fn (AiEscalation $record): ?string => $record->conversation?->businessEntity?->name)
                            ->placeholder('—'),
                        TextEntry::make('accountingFirm.name')
                            ->label('Firm')
                            ->placeholder('—'),
                        TextEntry::make('user_name')
                            ->label('Asked by')
                            ->state(fn (AiEscalation $record): ?string => $record->user?->getFilamentName())
                            ->placeholder('—'),
                        TextEntry::make('category')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        IconEntry::make('sla_breached')
                            ->label('SLA breached')
                            ->boolean(),
                    ]),
                Section::make('Question')
                    ->schema([
                        TextEntry::make('user_question')
                            ->hiddenLabel()
                            ->prose(),
                    ]),
                Section::make('AI answer')
                    ->schema([
                        TextEntry::make('ai_answer')
                            ->hiddenLabel()
                            ->prose()
                            ->placeholder('—'),
                    ]),
                Section::make('Accountant response')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('accountant_name')
                            ->label('Answered by')
                            ->state(fn (AiEscalation $record): ?string => $record->accountant?->getFilamentName())
                            ->placeholder('—'),
                        TextEntry::make('answered_at')
                            ->label('Answered at')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('Not yet answered'),
                        TextEntry::make('accountant_answer')
                            ->label('Answer')
                            ->prose()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('accountant_notes')
                            ->label('Internal notes')
                            ->prose()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('SLA')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('sla_deadline')
                            ->label('Deadline')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('resolved_at')
                            ->label('Resolved at')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Raised at')
                            ->dateTime('d.m.Y H:i'),
                    ]),
            ]);
    }
}
