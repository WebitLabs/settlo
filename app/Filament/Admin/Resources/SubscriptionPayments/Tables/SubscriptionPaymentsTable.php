<?php

namespace App\Filament\Admin\Resources\SubscriptionPayments\Tables;

use App\Models\SubscriptionPayment;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionPaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('subscription.user.email')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('amount')
                    ->money('chf')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SubscriptionPayment $record): string => $record->status === 'paid' ? 'success' : 'warning')
                    ->sortable(),
                TextColumn::make('gateway')
                    ->toggleable(),
                TextColumn::make('gateway_reference')
                    ->label('Reference')
                    ->copyable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => SubscriptionPayment::query()
                        ->select('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all()),
                Filter::make('paid_at')
                    ->schema([
                        DatePicker::make('paid_from')->label('From'),
                        DatePicker::make('paid_until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['paid_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('paid_at', '>=', $date),
                            )
                            ->when(
                                $data['paid_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('paid_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('paid_at', 'desc');
    }
}
