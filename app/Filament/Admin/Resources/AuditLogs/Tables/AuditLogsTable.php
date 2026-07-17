<?php

namespace App\Filament\Admin\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                TextColumn::make('actor_name')
                    ->label('Actor')
                    ->state(fn (AuditLog $record): ?string => $record->actor?->getFilamentName())
                    ->placeholder('System'),
                TextColumn::make('impersonator_name')
                    ->label('Impersonator')
                    ->state(fn (AuditLog $record): ?string => $record->impersonator?->getFilamentName())
                    ->placeholder('—'),
                TextColumn::make('action')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->state(fn (AuditLog $record): string => self::subjectLabel($record))
                    ->placeholder('—'),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => AuditLog::query()
                        ->select('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),
                SelectFilter::make('actor_id')
                    ->label('Actor')
                    ->relationship('actor', 'email')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')->label('From'),
                        DatePicker::make('created_until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * A compact "ShortClass #id" label for the polymorphic subject.
     */
    private static function subjectLabel(AuditLog $record): string
    {
        if ($record->subject_type === null) {
            return '—';
        }

        return class_basename($record->subject_type).' #'.$record->subject_id;
    }
}
