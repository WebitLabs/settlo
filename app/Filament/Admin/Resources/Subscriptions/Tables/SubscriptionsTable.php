<?php

namespace App\Filament\Admin\Resources\Subscriptions\Tables;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\SubscriptionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('trial_ends_at')
                    ->label('Trial ends')
                    ->dateTime('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('current_period_end')
                    ->label('Period ends')
                    ->dateTime('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('human_answers')
                    ->label('Answers used')
                    ->state(fn (Subscription $record): string => $record->human_answers_used.' / '.$record->human_answers_quota)
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SubscriptionStatus::class),
                SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->relationship('plan', 'name'),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::extendTrialAction(),
                    self::compAction(),
                    self::cancelAction(),
                    ViewAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Push out the trial deadline by a number of days from the later of the
     * current deadline or now, so extending a lapsed trial always lands in the
     * future. Audited as subscription.trial_extended.
     */
    private static function extendTrialAction(): Action
    {
        return Action::make('extendTrial')
            ->label('Extend trial')
            ->icon('heroicon-m-clock')
            ->color('info')
            ->schema([
                TextInput::make('days')
                    ->label('Additional days')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(90)
                    ->default(14)
                    ->required(),
            ])
            ->action(function (array $data, Subscription $record): void {
                $days = (int) $data['days'];
                $base = $record->trial_ends_at instanceof Carbon && $record->trial_ends_at->isFuture()
                    ? $record->trial_ends_at->copy()
                    : Carbon::now();
                $from = $record->trial_ends_at?->toIso8601String();
                $newEnd = $base->addDays($days);

                $record->forceFill([
                    'status' => SubscriptionStatus::Trialing->value,
                    'trial_ends_at' => $newEnd,
                ])->save();

                app(AuditLogger::class)->log('subscription.trial_extended', $record, [
                    'days' => $days,
                    'from' => $from,
                    'to' => $newEnd->toIso8601String(),
                ]);

                Notification::make()->title('Trial extended')->success()->send();
            });
    }

    /**
     * Comp a subscription: extend the paid period by whole months from the later
     * of the current period end or now and force it Active, no charge. Audited as
     * subscription.comped.
     */
    private static function compAction(): Action
    {
        return Action::make('comp')
            ->label('Comp subscription')
            ->icon('heroicon-m-gift')
            ->color('warning')
            ->schema([
                TextInput::make('months')
                    ->label('Complimentary months')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(12)
                    ->default(1)
                    ->required(),
            ])
            ->action(function (array $data, Subscription $record): void {
                $months = (int) $data['months'];
                $base = $record->current_period_end instanceof Carbon && $record->current_period_end->isFuture()
                    ? $record->current_period_end->copy()
                    : Carbon::now();
                $from = $record->current_period_end?->toIso8601String();
                $newEnd = $base->addMonthsNoOverflow($months);

                $record->forceFill([
                    'status' => SubscriptionStatus::Active->value,
                    'current_period_end' => $newEnd,
                ])->save();

                app(AuditLogger::class)->log('subscription.comped', $record, [
                    'months' => $months,
                    'from' => $from,
                    'to' => $newEnd->toIso8601String(),
                ]);

                Notification::make()->title('Subscription comped')->success()->send();
            });
    }

    /**
     * Cancel at period end through the billing service so the same lifecycle
     * rules apply as a self-service cancellation. Audited as
     * subscription.cancelled.
     */
    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancel subscription')
            ->modalDescription('The subscription will end at the close of the current period.')
            ->visible(fn (Subscription $record): bool => ! $record->cancel_at_period_end)
            ->action(function (Subscription $record): void {
                app(SubscriptionService::class)->cancel($record);

                app(AuditLogger::class)->log('subscription.cancelled', $record, [
                    'plan' => $record->plan?->code,
                ]);

                Notification::make()->title('Subscription cancelled')->success()->send();
            });
    }
}
