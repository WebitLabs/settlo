<?php

namespace App\Filament\Admin\Resources\FederalTaxBrackets\Tables;

use App\Filament\Admin\Resources\FederalTaxBrackets\Schemas\FederalTaxBracketForm;
use App\Filament\Admin\Support\TaxConfigActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FederalTaxBracketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')
                    ->sortable(),
                TextColumn::make('tariff')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('bracket_from')
                    ->label('From')
                    ->money('chf')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('bracket_to')
                    ->label('To')
                    ->money('chf')
                    ->placeholder('Top')
                    ->alignEnd(),
                TextColumn::make('rate')
                    ->label('Rate %')
                    ->numeric(3)
                    ->alignEnd(),
                TextColumn::make('base_amount')
                    ->label('Base tax')
                    ->money('chf')
                    ->alignEnd(),
                TextColumn::make('effective_from')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('effective_to')
                    ->date('d.m.Y')
                    ->placeholder('In force')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('tariff')
                    ->options([
                        'A' => 'A — single',
                        'B' => 'B — married / single parent',
                    ]),
            ])
            ->recordActions([
                TaxConfigActions::newVersion(FederalTaxBracketForm::components(), 'federal_tax_brackets'),
                EditAction::make(),
            ])
            ->defaultSort('bracket_from');
    }
}
