<?php

namespace App\Filament\Admin\Resources\BusinessEntities\Schemas;

use App\Models\BusinessEntity;
use App\Models\TaxEstimation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BusinessEntityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Business details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Business'),
                        TextEntry::make('legal_name')
                            ->label('Legal name')
                            ->placeholder('—'),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('uid')
                            ->label('UID')
                            ->placeholder('—'),
                        TextEntry::make('owner_name')
                            ->label('Owner')
                            ->state(fn (BusinessEntity $record): ?string => $record->owner?->getFilamentName())
                            ->placeholder('—'),
                        TextEntry::make('owner_email')
                            ->label('Owner email')
                            ->state(fn (BusinessEntity $record): ?string => $record->owner?->email)
                            ->placeholder('—'),
                        TextEntry::make('canton.code')
                            ->label('Canton')
                            ->badge(),
                        TextEntry::make('mwst_number')
                            ->label('VAT status')
                            ->badge()
                            ->state(fn (BusinessEntity $record): string => filled($record->mwst_number) ? 'Registered' : 'Not registered')
                            ->color(fn (string $state): string => $state === 'Registered' ? 'success' : 'gray'),
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime('d.m.Y H:i'),
                    ]),
                Section::make('Activity')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('invoices_count')
                                ->label('Invoices')
                                ->state(fn (BusinessEntity $record): int => $record->invoices()->count()),
                            TextEntry::make('expenses_count')
                                ->label('Expenses')
                                ->state(fn (BusinessEntity $record): int => $record->expenses()->count()),
                        ]),
                    ]),
                Section::make('Latest tax estimate')
                    ->description('Most recent engine snapshot for this entity.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('tax_total_burden')
                                ->label('Total tax burden')
                                ->money('chf')
                                ->state(fn (BusinessEntity $record): ?float => self::estimation($record)?->total_tax_burden),
                            TextEntry::make('tax_monthly_reserve')
                                ->label('Monthly reserve')
                                ->money('chf')
                                ->state(fn (BusinessEntity $record): ?float => self::estimation($record)?->monthly_reserve),
                            TextEntry::make('tax_vat_pct')
                                ->label('VAT threshold reached')
                                ->suffix('%')
                                ->state(fn (BusinessEntity $record): ?float => self::estimation($record)?->vat_threshold_pct),
                        ]),
                        TextEntry::make('tax_fiscal_year')
                            ->label('Fiscal year')
                            ->state(fn (BusinessEntity $record): ?int => self::estimation($record)?->fiscal_year),
                    ])
                    ->visible(fn (BusinessEntity $record): bool => self::estimation($record) !== null),
            ]);
    }

    private static function estimation(BusinessEntity $record): ?TaxEstimation
    {
        return $record->latestTaxEstimation();
    }
}
