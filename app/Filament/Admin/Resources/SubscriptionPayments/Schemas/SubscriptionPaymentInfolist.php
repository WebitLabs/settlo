<?php

namespace App\Filament\Admin\Resources\SubscriptionPayments\Schemas;

use App\Models\SubscriptionPayment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriptionPaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('subscription.user.email')
                            ->label('User')
                            ->copyable(),
                        TextEntry::make('plan.name')
                            ->label('Plan')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('amount')
                            ->money('chf'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (SubscriptionPayment $record): string => $record->status === 'paid' ? 'success' : 'warning'),
                        TextEntry::make('gateway'),
                        TextEntry::make('gateway_reference')
                            ->label('Reference')
                            ->copyable(),
                        TextEntry::make('paid_at')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                    ]),
                Section::make('Billing period')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('period_start')
                            ->dateTime('d.m.Y')
                            ->placeholder('—'),
                        TextEntry::make('period_end')
                            ->dateTime('d.m.Y')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
