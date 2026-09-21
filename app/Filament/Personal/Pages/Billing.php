<?php

namespace App\Filament\Personal\Pages;

use App\Billing\CheckoutUnavailableException;
use App\Billing\StripeGateway;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\WorkspacePricing;
use App\Support\SimulatedBilling;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Stripe\Exception\ApiErrorException;
use UnitEnum;

/**
 * The owner's billing overview: one subscription per business workspace, with
 * choose / change plan (Stripe Checkout, the dummy checkout, or a simulated
 * in-process payment), cancel, resume and the Stripe billing portal.
 * `?workspace={uuid}` opens "Choose plan" for that workspace; `?checkout=success`
 * confirms a completed checkout and, while Stripe's webhook is still on its way,
 * never re-opens "Choose plan" (a workspace that Stripe already bills can never
 * be checked out twice). A gateway that completes instantly has no hosted
 * checkout to return from, so that whole waiting machinery is skipped.
 */
class Billing extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'billing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $navigationLabel = 'Billing';

    protected static ?int $navigationSort = 1;

    /**
     * Statuses that need a (new) checkout before the workspace is paid.
     *
     * @var list<SubscriptionStatus>
     */
    private const array CHECKOUT_STATUSES = [
        SubscriptionStatus::Trialing,
        SubscriptionStatus::Incomplete,
        SubscriptionStatus::Expired,
        SubscriptionStatus::Cancelled,
    ];

    /**
     * Session key: until when a completed checkout is waiting for its webhook.
     */
    private const string CHECKOUT_PENDING_KEY = 'settlo.billing.checkout_pending_until';

    /** How long a completed checkout suppresses "Choose plan" (webhook delay). */
    private const int CHECKOUT_PENDING_MINUTES = 15;

    /** Most recent charges listed under "Payment history". */
    private const int PAYMENT_HISTORY_LIMIT = 24;

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->isOwner() ?? false;
    }

    public function getTitle(): string
    {
        return 'Billing';
    }

    /**
     * Whether the bound gateway settles the payment in-process, so there is no
     * hosted checkout to leave for and nothing to wait for afterwards.
     */
    public static function paysInstantly(): bool
    {
        return app(SubscriptionService::class)->gateway()->completesCheckoutInstantly();
    }

    /**
     * Only a gateway that really holds payment methods and invoices has a
     * self-service portal — the local and simulated ones have none.
     */
    private static function gatewayHasBillingPortal(): bool
    {
        $gateway = app(SubscriptionService::class)->gateway();

        return $gateway->name() !== 'dummy' && ! $gateway->completesCheckoutInstantly();
    }

    public function mount(): void
    {
        if (request()->query('checkout') === 'success' && ! self::paysInstantly()) {
            self::markCheckoutPending();

            Notification::make()
                ->title('Payment received')
                ->body('Activating your business… this takes a few seconds.')
                ->info()
                ->send();
        }

        $workspaceId = request()->query('workspace');

        if (is_string($workspaceId) && filled($workspaceId) && ! self::checkoutIsPending()) {
            $subscription = Subscription::query()
                ->where('user_id', Filament::auth()->id())
                ->where('business_entity_id', $workspaceId)
                ->first();

            if ($subscription !== null && self::needsCheckout($subscription)) {
                $this->defaultAction = 'choosePlan';
                $this->defaultActionContext = ['table' => true, 'recordKey' => $subscription->getKey()];
            }
        }
    }

    /**
     * Remember that the owner just came back from a completed checkout.
     */
    public static function markCheckoutPending(): void
    {
        session()->put(self::CHECKOUT_PENDING_KEY, now()->addMinutes(self::CHECKOUT_PENDING_MINUTES)->getTimestamp());
    }

    /**
     * Whether a checkout was completed recently and its webhook may still be
     * on its way.
     */
    public static function checkoutIsPending(): bool
    {
        if (self::paysInstantly()) {
            return false;
        }

        return (int) session()->get(self::CHECKOUT_PENDING_KEY, 0) > now()->getTimestamp();
    }

    /**
     * Whether the workspace still needs a checkout: its status asks for one
     * and Stripe does not already bill it.
     */
    public static function needsCheckout(Subscription $subscription): bool
    {
        return in_array($subscription->status, self::CHECKOUT_STATUSES, true)
            && ! StripeGateway::hasLiveSubscription($subscription);
    }

    /**
     * Whether the owner is waiting for a paid checkout to activate a business.
     */
    private function isActivating(): bool
    {
        return self::checkoutIsPending()
            && Subscription::query()
                ->where('user_id', Filament::auth()->id())
                ->where('status', SubscriptionStatus::Incomplete)
                ->exists();
    }

    public function content(Schema $schema): Schema
    {
        $discounts = collect(config('settlo.billing.workspace_discounts', []));

        return $schema->components([
            Callout::make('Payment received, activating…')
                ->description('Stripe is confirming your payment. Your business opens as soon as it is active — refresh this page in a few seconds.')
                ->info()
                ->visible(fn (): bool => $this->isActivating()),
            Text::make(sprintf(
                'Each business has its own plan. Your 2nd business gets %d %% off, your 3rd and later %d %%.',
                (int) $discounts->get(2, 0),
                (int) $discounts->last(),
            )),
            EmbeddedTable::make(),
            Section::make('Payment history')
                ->description(SimulatedBilling::isActive()
                    ? SimulatedBilling::notice()
                    : 'Every charge taken for your businesses.')
                ->collapsible()
                ->schema([
                    View::make('filament.personal.pages.billing-payments')
                        ->viewData(fn (): array => ['payments' => $this->payments()]),
                ]),
        ]);
    }

    /**
     * The owner's own charges, newest first, with the business they belong to.
     *
     * @return Collection<int, SubscriptionPayment>
     */
    public function payments(): Collection
    {
        return SubscriptionPayment::query()
            ->whereIn(
                'subscription_id',
                Subscription::query()->where('user_id', Filament::auth()->id())->select('id'),
            )
            ->with(['plan', 'subscription.businessEntity'])
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->limit(self::PAYMENT_HISTORY_LIMIT)
            ->get();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Subscription::query()
                ->where('user_id', Filament::auth()->id())
                ->with(['businessEntity', 'plan']))
            ->defaultSort('created_at')
            ->paginated(false)
            ->emptyStateHeading('No businesses yet')
            ->emptyStateDescription('Set up your first business to start the free trial.')
            ->columns([
                TextColumn::make('businessEntity.name')
                    ->label('Business')
                    ->weight('semibold'),
                TextColumn::make('plan.name')
                    ->label('Plan'),
                TextColumn::make('billing_interval')
                    ->label('Interval')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('unit_price')
                    ->label('Price')
                    ->money(fn (Subscription $record): string => $record->plan?->currency_code ?? 'CHF')
                    ->description(fn (Subscription $record): ?string => $record->discount_percent > 0
                        ? "{$record->discount_percent} % multi-business discount"
                        : null),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('renewal')
                    ->label('Renews / ends')
                    ->state(fn (Subscription $record): ?string => self::renewalLabel($record))
                    ->placeholder('—'),
            ])
            ->recordActions([
                $this->makeChoosePlanAction(),
                $this->makeChangePlanAction(),
                $this->makeCancelAction(),
                $this->makeResumeAction(),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('billingPortal')
                ->label('Payment methods & invoices')
                ->icon(Heroicon::OutlinedCreditCard)
                ->color('gray')
                ->visible(fn (): bool => self::gatewayHasBillingPortal())
                ->action(function (): void {
                    /** @var User $user */
                    $user = Filament::auth()->user();
                    $url = app(SubscriptionService::class)->gateway()->billingPortalUrl($user, self::getUrl(panel: 'app'));

                    if ($url !== null) {
                        $this->redirect($url);
                    }
                }),
        ];
    }

    private function makeChoosePlanAction(): Action
    {
        return Action::make('choosePlan')
            ->label('Choose plan')
            ->icon(Heroicon::OutlinedSparkles)
            ->button()
            ->visible(fn (Subscription $record): bool => self::needsCheckout($record))
            ->modalHeading(fn (Subscription $record): string => "Choose a plan for {$record->businessEntity?->name}")
            ->modalDescription(fn (Subscription $record): ?string => self::simulatedPaymentNotice($record))
            ->modalSubmitActionLabel(fn (): string => self::paysInstantly() ? 'Pay now (simulated)' : 'Continue to payment')
            ->fillForm(fn (Subscription $record): array => [
                'plan_id' => $record->plan_id,
                'billing_interval' => ($record->billing_interval ?? BillingInterval::Month)->value,
            ])
            ->schema(fn (Subscription $record): array => self::planFields(
                app(WorkspacePricing::class)->discountFor($record->user, except: $record->businessEntity),
            ))
            ->action(function (Subscription $record, array $data): void {
                $this->authorizeRecord($record);

                $service = app(SubscriptionService::class);
                $businessName = $record->businessEntity?->name ?? 'Your business';
                $service->choosePlan(
                    $record,
                    Plan::where('is_active', true)->findOrFail($data['plan_id']),
                    self::intervalFrom($data['billing_interval']),
                );

                if ($service->gateway()->completesCheckoutInstantly()) {
                    $service->payNow($record);

                    Notification::make()
                        ->title('Payment simulated — no card was charged')
                        ->body("{$businessName} is active on the {$record->plan?->name} plan.")
                        ->success()
                        ->send();

                    return;
                }

                try {
                    $url = $service->checkoutUrl(
                        $record,
                        successUrl: self::getUrl(['checkout' => 'success'], panel: 'app'),
                        cancelUrl: self::getUrl(panel: 'app'),
                    );
                } catch (CheckoutUnavailableException|ApiErrorException $exception) {
                    self::notifyCheckoutFailed($exception);

                    return;
                }

                $this->redirect($url);
            });
    }

    /**
     * The modal copy that tells the owner a simulated payment is about to be
     * made (and, for a trial paying early, that the trial ends now). Null for
     * gateways that really charge.
     */
    public static function simulatedPaymentNotice(Subscription $record): ?string
    {
        if (! self::paysInstantly()) {
            return null;
        }

        $notice = 'This payment is simulated: no card is charged and no real payment is taken. The business is marked as paid and opens right away.';

        if ($record->status === SubscriptionStatus::Trialing) {
            $notice .= ' Your free trial ends now and the paid period starts today.';
        }

        return $notice;
    }

    private function makeChangePlanAction(): Action
    {
        return Action::make('changePlan')
            ->label('Change plan')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (Subscription $record): bool => in_array($record->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
                || ($record->status === SubscriptionStatus::Trialing && StripeGateway::hasLiveSubscription($record)))
            ->modalHeading(fn (Subscription $record): string => "Change the plan of {$record->businessEntity?->name}")
            ->modalSubmitActionLabel('Change plan')
            ->fillForm(fn (Subscription $record): array => [
                'plan_id' => $record->plan_id,
                'billing_interval' => ($record->billing_interval ?? BillingInterval::Month)->value,
            ])
            ->schema(fn (Subscription $record): array => self::planFields((int) $record->discount_percent))
            ->action(function (Subscription $record, array $data): void {
                $this->authorizeRecord($record);

                app(SubscriptionService::class)->changePlan(
                    $record,
                    Plan::where('is_active', true)->findOrFail($data['plan_id']),
                    self::intervalFrom($data['billing_interval']),
                );

                Notification::make()
                    ->title('Plan updated')
                    ->success()
                    ->send();
            });
    }

    private function makeCancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Subscription $record): bool => in_array($record->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
                && ! $record->cancel_at_period_end)
            ->requiresConfirmation()
            ->modalHeading('Cancel subscription')
            ->modalDescription('The business stays active until the end of the current period and becomes read-only afterwards.')
            ->action(function (Subscription $record): void {
                $this->authorizeRecord($record);

                app(SubscriptionService::class)->cancel($record);

                Notification::make()
                    ->title('Subscription cancelled')
                    ->success()
                    ->send();
            });
    }

    private function makeResumeAction(): Action
    {
        return Action::make('resume')
            ->label('Resume')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (Subscription $record): bool => $record->cancel_at_period_end && $record->grantsAccess())
            ->action(function (Subscription $record): void {
                $this->authorizeRecord($record);

                app(SubscriptionService::class)->resume($record);

                Notification::make()
                    ->title('Subscription resumed')
                    ->success()
                    ->send();
            });
    }

    /**
     * Tell the owner that the checkout could not start; misconfigurations and
     * Stripe API errors are reported.
     */
    public static function notifyCheckoutFailed(CheckoutUnavailableException|ApiErrorException $exception): void
    {
        $isOwnerError = $exception instanceof CheckoutUnavailableException && ! $exception->isMisconfiguration;

        if (! $isOwnerError) {
            report($exception);
        }

        Notification::make()
            ->title('The payment could not be started')
            ->body($exception instanceof CheckoutUnavailableException
                ? $exception->ownerMessage
                : 'Stripe could not be reached. Please try again in a moment.')
            ->danger()
            ->send();
    }

    /**
     * The interval toggle and the plan radio with discounted prices.
     *
     * @return array<ToggleButtons|Radio>
     */
    public static function planFields(int $discount): array
    {
        return [
            ToggleButtons::make('billing_interval')
                ->label('Billing')
                ->options(BillingInterval::class)
                ->inline()
                ->grouped()
                ->default(BillingInterval::Month->value)
                ->required()
                ->live(),
            Radio::make('plan_id')
                ->label('Plan')
                ->options(fn (): array => self::activePlans()->pluck('name', 'id')->all())
                ->descriptions(fn (Get $get): array => self::activePlans()
                    ->mapWithKeys(fn (Plan $plan): array => [
                        $plan->getKey() => implode(' · ', array_filter([
                            app(WorkspacePricing::class)->describe(
                                $plan,
                                self::intervalFrom($get('billing_interval')),
                                $discount,
                            ),
                            implode(' · ', $plan->marketing_features ?? []),
                        ])),
                    ])
                    ->all())
                ->in(fn (): array => self::activePlans()->pluck('id')->all())
                ->required(),
        ];
    }

    /**
     * @return Collection<int, Plan>
     */
    private static function activePlans(): Collection
    {
        return Plan::where('is_active', true)->orderBy('sort_order')->get();
    }

    /**
     * The billing interval of a form state value (enum or string).
     */
    public static function intervalFrom(mixed $state): BillingInterval
    {
        if ($state instanceof BillingInterval) {
            return $state;
        }

        return BillingInterval::tryFrom((string) $state) ?? BillingInterval::Month;
    }

    private static function renewalLabel(Subscription $record): ?string
    {
        if ($record->status === SubscriptionStatus::Trialing && $record->trial_ends_at !== null) {
            return 'Trial ends '.$record->trial_ends_at->toFormattedDateString();
        }

        if ($record->current_period_end === null) {
            return null;
        }

        $ends = $record->cancel_at_period_end || ! $record->grantsAccess();

        return ($ends ? 'Ends ' : 'Renews ').$record->current_period_end->toFormattedDateString();
    }

    private function authorizeRecord(Subscription $record): void
    {
        abort_unless($record->user_id === Filament::auth()->id(), 403);
    }
}
