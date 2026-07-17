<?php

namespace App\Filament\Admin\Resources\Escalations\Tables;

use App\Enums\AiEscalationStatus;
use App\Models\AiEscalation;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

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
                TextColumn::make('entity')
                    ->label('Business')
                    ->state(fn (AiEscalation $record): ?string => $record->conversation?->businessEntity?->name)
                    ->placeholder('—'),
                TextColumn::make('accountingFirm.name')
                    ->label('Firm')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                IconColumn::make('sla_breached')
                    ->label('SLA breached')
                    ->boolean()
                    ->trueIcon('heroicon-m-exclamation-triangle')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-m-check-circle')
                    ->falseColor('success')
                    ->sortable(),
                TextColumn::make('accountant_name')
                    ->label('Accountant')
                    ->state(fn (AiEscalation $record): ?string => $record->accountant?->getFilamentName())
                    ->placeholder('—'),
                TextColumn::make('answered_at')
                    ->label('Answered')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(AiEscalationStatus::class),
                TernaryFilter::make('sla_breached')
                    ->label('SLA')
                    ->placeholder('All')
                    ->trueLabel('Breached')
                    ->falseLabel('Within SLA'),
                SelectFilter::make('accounting_firm_id')
                    ->label('Firm')
                    ->relationship('accountingFirm', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
