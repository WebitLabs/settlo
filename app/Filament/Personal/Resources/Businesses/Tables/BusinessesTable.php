<?php

namespace App\Filament\Personal\Resources\Businesses\Tables;

use App\Filament\Personal\Pages\Billing;
use App\Filament\Personal\Pages\SetUpBusiness;
use App\Filament\Workspace\Pages\BusinessSettings;
use App\Filament\Workspace\Pages\Dashboard;
use App\Models\BusinessEntity;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BusinessesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Business')
                    ->weight('medium')
                    ->searchable()
                    ->sortable()
                    ->description(fn (BusinessEntity $record): ?string => $record->type?->getLabel()),
                TextColumn::make('subscription.plan.name')
                    ->label('Plan')
                    ->placeholder('—'),
                TextColumn::make('subscription.status')
                    ->label('Subscription')
                    ->badge(),
                TextColumn::make('subscription.trial_ends_at')
                    ->label('Trial ends')
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('revenue_ytd')
                    ->label('Revenue YTD (excl. VAT)')
                    ->money('CHF')
                    ->default(0)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (BusinessEntity $record): string => self::workspaceUrl($record))
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowRightCircle)
                    ->url(fn (BusinessEntity $record): string => self::workspaceUrl($record)),
                Action::make('settings')
                    ->label('Settings')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->color('gray')
                    ->url(fn (BusinessEntity $record): string => BusinessSettings::getUrl(panel: 'workspace', tenant: $record)),
                Action::make('billing')
                    ->label('Billing')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->color('gray')
                    ->url(fn (BusinessEntity $record): string => Billing::getUrl(['workspace' => $record->getKey()], panel: 'app')),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2)
            ->emptyStateHeading('No businesses yet')
            ->emptyStateDescription('Create your first business workspace to start invoicing.')
            ->emptyStateActions([
                Action::make('setUpFirstBusiness')
                    ->label('Set up a business')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->url(fn (): string => SetUpBusiness::getUrl(panel: 'app')),
            ]);
    }

    private static function workspaceUrl(BusinessEntity $record): string
    {
        return Dashboard::getUrl(panel: 'workspace', tenant: $record);
    }
}
