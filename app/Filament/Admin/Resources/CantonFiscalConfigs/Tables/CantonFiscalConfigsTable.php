<?php

namespace App\Filament\Admin\Resources\CantonFiscalConfigs\Tables;

use App\Filament\Admin\Resources\CantonFiscalConfigs\Schemas\CantonFiscalConfigForm;
use App\Filament\Admin\Support\TaxConfigActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CantonFiscalConfigsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('canton.code')
                    ->label('Canton')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('year')
                    ->sortable(),
                TextColumn::make('cantonal_rate')
                    ->label('Cantonal %')
                    ->numeric(4)
                    ->alignEnd(),
                TextColumn::make('communal_multiplier_default')
                    ->label('Communal mult.')
                    ->numeric(2)
                    ->alignEnd(),
                TextColumn::make('church_rate')
                    ->label('Church %')
                    ->numeric(2)
                    ->alignEnd(),
                TextColumn::make('child_deduction')
                    ->label('Child ded.')
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
                SelectFilter::make('canton_id')
                    ->label('Canton')
                    ->relationship('canton', 'name_en'),
            ])
            ->recordActions([
                TaxConfigActions::newVersion(CantonFiscalConfigForm::components(), 'canton_fiscal_configs'),
                EditAction::make(),
            ])
            ->defaultSort('canton_id');
    }
}
