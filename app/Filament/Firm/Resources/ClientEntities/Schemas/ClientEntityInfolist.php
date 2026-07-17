<?php

namespace App\Filament\Firm\Resources\ClientEntities\Schemas;

use App\Models\BusinessEntity;
use App\Models\TaxEstimation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientEntityInfolist
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
                            ->state(fn (BusinessEntity $record): ?string => $record->owner?->getFilamentName()),
                        TextEntry::make('canton.code')
                            ->label('Canton')
                            ->badge(),
                        TextEntry::make('address')
                            ->label('Address')
                            ->state(fn (BusinessEntity $record): string => trim(sprintf(
                                '%s %s, %s %s',
                                (string) $record->street,
                                (string) $record->street_number,
                                (string) $record->postal_code,
                                (string) $record->city,
                            ), ' ,'))
                            ->placeholder('—'),
                        TextEntry::make('mwst_number')
                            ->label('VAT status')
                            ->badge()
                            ->state(fn (BusinessEntity $record): string => filled($record->mwst_number) ? 'Registered' : 'Not registered')
                            ->color(fn (string $state): string => $state === 'Registered' ? 'success' : 'gray'),
                    ]),
                Section::make('Tax estimate')
                    ->description('Latest engine snapshot for the current fiscal year.')
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
                    ])
                    ->visible(fn (BusinessEntity $record): bool => self::estimation($record) !== null),
            ]);
    }

    private static function estimation(BusinessEntity $record): ?TaxEstimation
    {
        return $record->latestTaxEstimation((int) config('settlo.current_fiscal_year', now()->year));
    }
}
