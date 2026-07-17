<?php

namespace App\Filament\Admin\Resources\VatConfigs\Tables;

use App\Filament\Admin\Resources\VatConfigs\Schemas\VatConfigForm;
use App\Filament\Admin\Support\TaxConfigActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VatConfigsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')
                    ->sortable(),
                TextColumn::make('standard_rate')
                    ->label('Standard %')
                    ->numeric(3)
                    ->alignEnd(),
                TextColumn::make('reduced_rate')
                    ->label('Reduced %')
                    ->numeric(3)
                    ->alignEnd(),
                TextColumn::make('special_rate')
                    ->label('Special %')
                    ->numeric(3)
                    ->alignEnd(),
                TextColumn::make('registration_threshold')
                    ->label('Threshold')
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
            ->recordActions([
                TaxConfigActions::newVersion(VatConfigForm::components(), 'vat_configs'),
                EditAction::make(),
            ])
            ->defaultSort('effective_from', 'desc');
    }
}
