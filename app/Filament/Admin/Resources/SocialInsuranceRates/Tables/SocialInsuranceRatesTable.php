<?php

namespace App\Filament\Admin\Resources\SocialInsuranceRates\Tables;

use App\Filament\Admin\Resources\SocialInsuranceRates\Schemas\SocialInsuranceRateForm;
use App\Filament\Admin\Support\TaxConfigActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SocialInsuranceRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')
                    ->sortable(),
                TextColumn::make('ahv_rate')
                    ->label('AHV %')
                    ->numeric(3)
                    ->alignEnd(),
                TextColumn::make('iv_rate')
                    ->label('IV %')
                    ->numeric(3)
                    ->alignEnd(),
                TextColumn::make('eo_rate')
                    ->label('EO %')
                    ->numeric(3)
                    ->alignEnd(),
                TextColumn::make('pillar3a_max_se')
                    ->label('3a max (no P2)')
                    ->numeric()
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
                TaxConfigActions::newVersion(SocialInsuranceRateForm::components(), 'social_insurance_rates'),
                EditAction::make(),
            ])
            ->defaultSort('effective_from', 'desc');
    }
}
