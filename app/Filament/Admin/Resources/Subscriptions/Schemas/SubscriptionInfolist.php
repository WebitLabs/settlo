<?php

namespace App\Filament\Admin\Resources\Subscriptions\Schemas;

use App\Models\Subscription;
use App\Support\SimulatedBilling;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Subscription')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('user.email')
                            ->label('User')
                            ->copyable(),
                        TextEntry::make('plan.name')
                            ->label('Plan')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('businessEntity.name')
                            ->label('Business')
                            ->placeholder('—'),
                        TextEntry::make('billing_interval')
                            ->label('Interval')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('discount_percent')
                            ->label('Discount')
                            ->suffix(' %'),
                        TextEntry::make('unit_price')
                            ->label('Unit price')
                            ->money('CHF')
                            ->placeholder('—'),
                        TextEntry::make('gateway')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => SimulatedBilling::isSimulatedPayment($state)
                                ? 'Simulated ('.($state ?: 'unknown').')'
                                : 'Stripe')
                            ->color(fn (?string $state): string => SimulatedBilling::isSimulatedPayment($state) ? 'warning' : 'success')
                            ->helperText(fn (?string $state): ?string => SimulatedBilling::isSimulatedPayment($state)
                                ? 'No card was charged for this subscription.'
                                : null),
                        TextEntry::make('stripe_subscription_type')
                            ->label('Stripe type')
                            ->placeholder('—'),
                    ]),
                Section::make('Periods')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('trial_starts_at')
                            ->label('Trial start')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('trial_ends_at')
                            ->label('Trial end')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('current_period_start')
                            ->label('Period start')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('current_period_end')
                            ->label('Period end')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('canceled_at')
                            ->label('Cancelled at')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                    ]),
                Section::make('Quota')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('human_answers')
                            ->label('Human answers used')
                            ->state(fn (Subscription $record): string => $record->human_answers_used.' / '.$record->human_answers_quota),
                        TextEntry::make('quota_reset_at')
                            ->label('Quota resets')
                            ->dateTime('d.m.Y')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
