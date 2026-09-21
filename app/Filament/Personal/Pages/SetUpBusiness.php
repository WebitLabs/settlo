<?php

namespace App\Filament\Personal\Pages;

use App\Billing\CheckoutUnavailableException;
use App\Enums\BillingInterval;
use App\Enums\BusinessEntityType;
use App\Enums\SubscriptionStatus;
use App\Filament\Personal\Widgets\OnboardingChecklist;
use App\Filament\Shared\Schemas\BusinessProfileFields;
use App\Filament\Shared\Schemas\InvoicingDefaultsFields;
use App\Filament\Workspace\Pages\Dashboard;
use App\Http\Middleware\EnsurePhoneIsVerified;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\WorkspacePricing;
use App\Services\Workspaces\WorkspaceProvisioner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Stripe\Exception\ApiErrorException;
use UnitEnum;

/**
 * "Set up a business": a three-step stepper (business profile, invoicing &
 * banking, plan) that creates a new workspace via the WorkspaceProvisioner.
 * The current step lives on the server, so "Next" is disabled until the
 * visible step is valid and every step can be skipped. The owner's first
 * workspace starts the free trial; later ones continue to checkout (or are
 * paid for in-process when the gateway completes instantly), or — when the
 * plan is skipped — are created locked (Incomplete) and paid for later from
 * Billing. A checkout that cannot start never leaves a half-created business
 * behind: the gateway is checked before anything is saved.
 *
 * @property-read Schema $businessForm
 * @property-read Schema $invoicingForm
 * @property-read Schema $planForm
 */
class SetUpBusiness extends Page
{
    protected static ?string $slug = 'businesses/new';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Businesses';

    protected static ?string $navigationLabel = 'Set up a business';

    protected static ?int $navigationSort = 2;

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    /**
     * @var array<int, string>
     */
    public const array STEPS = [1 => 'Business profile', 2 => 'Invoicing & banking', 3 => 'Plan'];

    /**
     * @var array<int, string>
     */
    private const array SCHEMA_BY_STEP = [1 => 'businessForm', 2 => 'invoicingForm', 3 => 'planForm'];

    /** Debounce for live validation, so "Next" follows typing. */
    private const int VALIDATION_DEBOUNCE_MS = 400;

    public int $step = 1;

    public bool $invoicingSkipped = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $businessData = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $invoicingData = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $planData = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && Gate::forUser($user)->allows('create', BusinessEntity::class)
            && ! EnsurePhoneIsVerified::mustVerify($user);
    }

    public function getTitle(): string
    {
        return 'Set up your business';
    }

    public function getSubheading(): string
    {
        return "We'll use this information to configure invoicing, accounting and tax settings for your business. It only takes about a minute.";
    }

    public function mount(): void
    {
        $user = $this->user();

        $this->businessForm->fill([
            ...$user->only(['street', 'street_number', 'postal_code', 'city', 'canton_id']),
            'type' => BusinessEntityType::SoleProprietorship->value,
        ]);

        $this->invoicingForm->fill([
            ...app(WorkspaceProvisioner::class)->defaultInvoicing($user),
        ]);

        $this->planForm->fill([
            'plan_id' => Plan::where('is_active', true)->where('code', 'pro')->value('id'),
            'billing_interval' => BillingInterval::Month->value,
        ]);
    }

    public function businessForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('businessData')
            ->columns(['default' => 1, 'md' => 2])
            ->components(BusinessProfileFields::components(withUidLookup: true, debounceMs: self::VALIDATION_DEBOUNCE_MS));
    }

    public function invoicingForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('invoicingData')
            ->columns(['default' => 1, 'md' => 2])
            ->components(InvoicingDefaultsFields::components(withBankName: true, debounceMs: self::VALIDATION_DEBOUNCE_MS));
    }

    public function planForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('planData')
            ->components($this->planFields());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.personal.components.setup-stepper')
                ->viewData(fn (): array => ['steps' => self::STEPS, 'current' => $this->step]),
            Form::make([
                EmbeddedSchema::make('businessForm')->visible(fn (): bool => $this->step === 1),
                EmbeddedSchema::make('invoicingForm')->visible(fn (): bool => $this->step === 2),
                EmbeddedSchema::make('planForm')->visible(fn (): bool => $this->step === 3),
            ])
                ->id('set-up-business')
                ->livewireSubmitHandler('submitStep')
                ->footer([
                    Actions::make($this->stepActions())
                        ->alignment(Alignment::End)
                        ->key('set-up-business-actions'),
                ]),
        ]);
    }

    /**
     * @return array<Action>
     */
    private function stepActions(): array
    {
        return [
            Action::make('back')
                ->label('Back')
                ->color('gray')
                ->action('back')
                ->visible(fn (): bool => $this->step > 1),
            Action::make('skip')
                ->label(fn (): string => $this->step === 1 ? "I'll do it later" : 'Skip for now')
                ->link()
                ->color('gray')
                ->action('skip'),
            Action::make('next')
                ->label(fn (): string => match (true) {
                    $this->step < 3 => 'Next',
                    ! $this->user()->hasUsedTrial() => 'Start '.self::trialDays().'-day free trial',
                    Billing::paysInstantly() => 'Pay now (simulated)',
                    default => 'Continue to payment',
                })
                ->submit('submitStep')
                ->disabled(fn (): bool => ! $this->stepIsValid($this->step)),
        ];
    }

    /**
     * Whether the step's fields pass validation, checked silently (no errors
     * are shown) so the "Next" button can follow the user's input.
     */
    public function stepIsValid(int $step): bool
    {
        $schema = $this->getSchema(self::SCHEMA_BY_STEP[$step] ?? self::SCHEMA_BY_STEP[1]);

        return Validator::make(
            [
                'businessData' => $this->businessData,
                'invoicingData' => $this->invoicingData,
                'planData' => $this->planData,
            ],
            $schema->getValidationRules(),
        )->passes();
    }

    public function submitStep(): void
    {
        if ($this->step < 3) {
            $this->next();

            return;
        }

        $this->create();
    }

    public function next(): void
    {
        $this->getSchema(self::SCHEMA_BY_STEP[$this->step])->validate();

        $this->step = min(3, $this->step + 1);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function skip(): void
    {
        if ($this->step === 1) {
            Notification::make()
                ->title('You can set up your business any time from your dashboard.')
                ->info()
                ->send();

            $this->redirect(PersonalDashboard::getUrl(panel: 'app'));

            return;
        }

        if ($this->step === 2) {
            $this->businessForm->validate();
            $this->invoicingSkipped = true;
        }

        $this->create(planSkipped: true);
    }

    public function create(bool $planSkipped = false): void
    {
        abort_unless(static::canAccess(), 403);

        $user = $this->user();
        $provisioner = app(WorkspaceProvisioner::class);
        $subscriptions = app(SubscriptionService::class);

        $business = $this->businessForm->getState();
        $invoicing = $this->invoicingSkipped ? null : $this->invoicingForm->getState();
        $plan = $planSkipped ? null : $this->planForm->getState();

        if ($user->hasUsedTrial() && ! $planSkipped) {
            try {
                $subscriptions->gateway()->assertCanCheckout(
                    $provisioner->planFrom($plan),
                    Billing::intervalFrom($plan['billing_interval'] ?? null),
                    app(WorkspacePricing::class)->discountFor($user),
                );
            } catch (CheckoutUnavailableException $exception) {
                Billing::notifyCheckoutFailed($exception);

                return;
            }
        }

        $entity = $provisioner->create($user, $business, $invoicing, $plan);
        $subscription = $entity->subscription()->firstOrFail();

        $this->notifyAboutTaxProfile($user);

        if ($subscription->status === SubscriptionStatus::Incomplete && $planSkipped) {
            Notification::make()
                ->title("{$entity->name} is set up")
                ->body('Choose a plan in Billing whenever you are ready to open it.')
                ->info()
                ->send();

            $this->redirect(PersonalDashboard::getUrl(panel: 'app'));

            return;
        }

        if ($subscription->status === SubscriptionStatus::Incomplete && $subscriptions->gateway()->completesCheckoutInstantly()) {
            $subscriptions->payNow($subscription);

            Notification::make()
                ->title("Payment simulated — {$entity->name} is ready")
                ->body('No card was charged and no real payment was taken.')
                ->success()
                ->send();

            $this->redirect(Dashboard::getUrl(tenant: $entity, panel: 'workspace'));

            return;
        }

        if ($subscription->status === SubscriptionStatus::Incomplete) {
            try {
                $url = $subscriptions->checkoutUrl(
                    $subscription,
                    successUrl: Billing::getUrl(['checkout' => 'success'], panel: 'app'),
                    cancelUrl: Billing::getUrl(['workspace' => $entity->getKey()], panel: 'app'),
                );
            } catch (CheckoutUnavailableException|ApiErrorException $exception) {
                Billing::notifyCheckoutFailed($exception);

                $this->redirect(Billing::getUrl(panel: 'app'));

                return;
            }

            $this->redirect($url);

            return;
        }

        Notification::make()
            ->title("{$entity->name} is ready")
            ->body('Your '.self::trialDays().'-day free trial has started — no credit card needed.')
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl(tenant: $entity, panel: 'workspace'));
    }

    /**
     * A business alone produces no tax estimate: the engine needs the owner's
     * canton, marital status and Pillar 3a. Rather than adding a fourth
     * skippable step, the owner is told straight after the set-up — with the
     * link to fill it in — and the same item stays open on the dashboard
     * checklist until it is done.
     */
    private function notifyAboutTaxProfile(User $user): void
    {
        if (OnboardingChecklist::taxProfileIsComplete($user)) {
            return;
        }

        Notification::make()
            ->title('One more minute: your tax profile')
            ->body('Add your canton, marital status and Pillar 3a so Settlo can estimate your tax and what to set aside each month.')
            ->icon(Heroicon::OutlinedCalculator)
            ->warning()
            ->persistent()
            ->actions([
                Action::make('taxProfile')
                    ->label('Complete tax profile')
                    ->url(EditTaxProfile::getUrl(panel: 'app'))
                    ->button(),
            ])
            ->send();
    }

    /**
     * The interval toggle, the plan radio (Pro by default) and a note on the
     * trial or the multi-business discount.
     *
     * @return array<Component|Field>
     */
    private function planFields(): array
    {
        $discount = app(WorkspacePricing::class)->discountFor($this->user());

        return [
            ...collect(Billing::planFields($discount))
                ->map(fn (Field $field): Field => $field instanceof Radio
                    ? $field->columns(['default' => 1, 'lg' => 3])
                    : $field)
                ->all(),
            TextEntry::make('pricing_note')
                ->hiddenLabel()
                ->icon(Heroicon::OutlinedInformationCircle)
                ->state(fn (): string => match (true) {
                    ! $this->user()->hasUsedTrial() => 'No credit card required · Cancel anytime · '.self::trialDays().' days free.',
                    Billing::paysInstantly() => "Your first business already used the free trial. This payment is simulated: no card is charged and no real payment is taken. Multi-business discount: {$discount} %.",
                    default => "Your first business already used the free trial. You'll continue to secure payment with Stripe. Multi-business discount: {$discount} %.",
                }),
        ];
    }

    private static function trialDays(): int
    {
        return (int) config('settlo.billing.trial_days');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
