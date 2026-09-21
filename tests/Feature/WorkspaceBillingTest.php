<?php

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Filament\Personal\Pages\Billing;
use App\Filament\Workspace\Pages\AskSettlo;
use App\Filament\Workspace\Pages\Dashboard;
use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\StripeSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\AskSettloService;
use App\Services\Ai\EscalationService;
use App\Services\Billing\SubscriptionService;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    config(['services.gemini.key' => null]);
});

/**
 * An owner with one workspace in the given subscription state.
 *
 * @return array{0: User, 1: BusinessEntity, 2: Subscription}
 */
function ownerWithWorkspace(string $state = 'active'): array
{
    $owner = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();

    $factory = Subscription::factory()->forEntity($entity)->onPlan('pro', 1);
    $factory = match ($state) {
        'incomplete' => $factory->incomplete(),
        'expired' => $factory->expired(),
        'trialing' => $factory,
        default => $factory->active(),
    };

    return [$owner, $entity, $factory->create()];
}

function actAsBillingOwner(User $owner): void
{
    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
}

describe('workspace access', function () {
    it('redirects every page of an incomplete workspace to billing', function () {
        [$owner, $entity] = ownerWithWorkspace('incomplete');
        $billingUrl = Billing::getUrl(['workspace' => $entity->getKey()], panel: 'app');

        foreach ([
            Dashboard::getUrl(tenant: $entity, panel: 'workspace'),
            InvoiceResource::getUrl('index', tenant: $entity, panel: 'workspace'),
            AskSettlo::getUrl(tenant: $entity, panel: 'workspace'),
        ] as $url) {
            $this->actingAs($owner)->get($url)->assertRedirect($billingUrl);
        }
    });

    it('redirects a workspace without a subscription to billing', function () {
        $owner = User::factory()->owner()->create();
        $entity = BusinessEntity::factory()->for($owner, 'owner')->create();

        $this->actingAs($owner)
            ->get(Dashboard::getUrl(tenant: $entity, panel: 'workspace'))
            ->assertRedirect(Billing::getUrl(['workspace' => $entity->getKey()], panel: 'app'));
    });

    it('sends the tenant billing route to the personal billing page', function () {
        [$owner, $entity] = ownerWithWorkspace();

        $this->actingAs($owner)
            ->get(Filament::getPanel('workspace')->getTenantBillingUrl($entity))
            ->assertRedirect(Billing::getUrl(['workspace' => $entity->getKey()], panel: 'app'));
    });

    it('shows a single "Billing" entry in the tenant menu (L16)', function () {
        [$owner, $entity] = ownerWithWorkspace();

        actAsWorkspace($owner, $entity);

        $items = collect(Filament::getPanel('workspace')->getTenantMenuItems());
        $billingItems = $items->filter(fn ($item): bool => in_array($item->getLabel(), ['Billing', 'Manage subscription'], true));

        expect($billingItems)->toHaveCount(1)
            ->and($billingItems->first()->getLabel())->toBe('Billing')
            ->and($billingItems->first()->getUrl())->toBe(Billing::getUrl(['workspace' => $entity->getKey()], panel: 'app'));
    });

    it('keeps an expired workspace readable with a banner but blocks writes', function () {
        [$owner, $entity] = ownerWithWorkspace('expired');
        $invoice = Invoice::factory()->draft()->for($entity, 'businessEntity')->create();

        $this->actingAs($owner)
            ->get(Dashboard::getUrl(tenant: $entity, panel: 'workspace'))
            ->assertOk()
            ->assertSee('has ended. Your data is read-only.')
            ->assertSee('Choose a plan');

        $this->actingAs($owner)
            ->get(InvoiceResource::getUrl('index', tenant: $entity, panel: 'workspace'))
            ->assertOk();

        $this->actingAs($owner)
            ->get(InvoiceResource::getUrl('create', tenant: $entity, panel: 'workspace'))
            ->assertForbidden();

        actAsWorkspace($owner, $entity);

        expect($owner->can('view', $invoice))->toBeTrue()
            ->and($owner->can('create', Invoice::class))->toBeFalse()
            ->and($owner->can('update', $invoice))->toBeFalse();
    });

    it('does not show the banner on an active workspace', function () {
        [$owner, $entity] = ownerWithWorkspace();

        $this->actingAs($owner)
            ->get(Dashboard::getUrl(tenant: $entity, panel: 'workspace'))
            ->assertOk()
            ->assertDontSee('Your data is read-only.');
    });

    it('scopes write access to each workspace of the same owner', function () {
        [$owner, $expired] = ownerWithWorkspace('expired');
        $active = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();
        Subscription::factory()->forEntity($active)->active()->create();

        $expiredInvoice = Invoice::factory()->draft()->for($expired, 'businessEntity')->create();
        $activeInvoice = Invoice::factory()->draft()->for($active, 'businessEntity')->create();

        actAsWorkspace($owner, $active);

        expect($owner->can('create', Invoice::class))->toBeTrue()
            ->and($owner->can('update', $activeInvoice))->toBeTrue()
            ->and($owner->can('update', $expiredInvoice))->toBeFalse()
            ->and($owner->can('view', $expiredInvoice))->toBeTrue();
    });
});

describe('dummy checkout', function () {
    it('activates the subscription through the signed URL and redirects back', function () {
        [$owner, $entity, $subscription] = ownerWithWorkspace('incomplete');
        $return = Dashboard::getUrl(tenant: $entity, panel: 'workspace');
        $url = app(SubscriptionService::class)->checkoutUrl($subscription, $return, $return);

        $this->actingAs($owner)->get($url)->assertRedirect($return);

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->payments()->count())->toBe(1);

        $this->actingAs($owner)->get($return)->assertOk();
    });

    it('rejects an unsigned checkout URL', function () {
        [$owner, , $subscription] = ownerWithWorkspace('incomplete');

        $this->actingAs($owner)
            ->get(route('billing.dummy-checkout', ['subscription' => $subscription->getKey()]))
            ->assertForbidden();

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Incomplete);
    });

    it('rejects a checkout for another owner\'s subscription', function () {
        [, , $subscription] = ownerWithWorkspace('incomplete');
        $intruder = User::factory()->owner()->create();
        $url = URL::signedRoute('billing.dummy-checkout', ['subscription' => $subscription->getKey(), 'return' => url('/app')]);

        $this->actingAs($intruder)->get($url)->assertForbidden();

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Incomplete);
    });

    it('is unavailable in production even when the route is hit directly (H1)', function () {
        [$owner, , $subscription] = ownerWithWorkspace('incomplete');
        $url = URL::signedRoute('billing.dummy-checkout', ['subscription' => $subscription->getKey(), 'return' => url('/app')]);
        app()->detectEnvironment(fn (): string => 'production');

        $this->actingAs($owner)->get($url)->assertNotFound();

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Incomplete)
            ->and($subscription->payments()->count())->toBe(0);
    });

    it('never redirects outside the application', function () {
        [$owner, , $subscription] = ownerWithWorkspace('incomplete');
        $url = URL::signedRoute('billing.dummy-checkout', ['subscription' => $subscription->getKey(), 'return' => 'https://evil.example/']);

        $this->actingAs($owner)->get($url)->assertRedirect(url('/app'));
    });
});

describe('billing page', function () {
    it('lists only the owner\'s workspace subscriptions', function () {
        [$owner, , $mine] = ownerWithWorkspace();
        [, , $foreign] = ownerWithWorkspace();

        actAsBillingOwner($owner);

        Livewire::test(Billing::class)
            ->assertOk()
            ->assertSee('Your 2nd business gets 20 % off, your 3rd and later 30 %.')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertActionHidden('billingPortal');
    });

    it('is reachable at /app/billing', function () {
        [$owner, $entity] = ownerWithWorkspace();

        $this->actingAs($owner)->get('/app/billing')->assertOk()->assertSee($entity->name);
    });

    it('opens "Choose plan" for the requested workspace', function () {
        [$owner, $entity, $subscription] = ownerWithWorkspace('incomplete');

        actAsBillingOwner($owner);

        Livewire::withQueryParams(['workspace' => $entity->getKey()])
            ->test(Billing::class)
            ->assertSet('defaultAction', 'choosePlan')
            ->assertSet('defaultActionContext', ['table' => true, 'recordKey' => $subscription->getKey()]);
    });

    it('confirms a completed checkout', function () {
        [$owner] = ownerWithWorkspace();

        actAsBillingOwner($owner);

        Livewire::withQueryParams(['checkout' => 'success'])
            ->test(Billing::class)
            ->assertNotified('Payment received')
            ->assertDontSee('Payment received, activating…');
    });

    it('shows the activation notice and never re-opens "Choose plan" while the webhook is on its way (H2)', function () {
        [$owner, $entity] = ownerWithWorkspace('incomplete');

        actAsBillingOwner($owner);

        Livewire::withQueryParams(['checkout' => 'success'])
            ->test(Billing::class)
            ->assertNotified('Payment received')
            ->assertSee('Payment received, activating…')
            ->assertSet('defaultAction', null);

        expect(Billing::checkoutIsPending())->toBeTrue();

        // The workspace is still incomplete: the middleware sends the owner back
        // to Billing, which must not ask for a plan again.
        $this->actingAs($owner)
            ->get(Dashboard::getUrl(tenant: $entity, panel: 'workspace'))
            ->assertRedirect(Billing::getUrl(['workspace' => $entity->getKey()], panel: 'app'));

        Livewire::withQueryParams(['workspace' => $entity->getKey()])
            ->test(Billing::class)
            ->assertSet('defaultAction', null)
            ->assertSee('Payment received, activating…');

        $this->travel(16)->minutes();

        Livewire::withQueryParams(['workspace' => $entity->getKey()])
            ->test(Billing::class)
            ->assertSet('defaultAction', 'choosePlan');
    });

    it('hides "Choose plan" for a workspace that Stripe already bills (H2)', function (string $state) {
        [$owner, $entity, $subscription] = ownerWithWorkspace($state);
        $subscription->forceFill(['gateway' => 'stripe', 'stripe_subscription_type' => 'workspace:'.$entity->getKey()])->save();
        StripeSubscription::create([
            'user_id' => $owner->getKey(),
            'type' => $subscription->stripe_subscription_type,
            'stripe_id' => 'sub_live',
            'stripe_status' => 'trialing',
        ]);

        actAsBillingOwner($owner);

        Livewire::withQueryParams(['workspace' => $entity->getKey()])
            ->test(Billing::class)
            ->assertSet('defaultAction', null)
            ->assertActionHidden(TestAction::make('choosePlan')->table($subscription));
    })->with(['trialing', 'incomplete']);

    it('lets a trial that Stripe already bills change its plan instead', function () {
        [$owner, $entity, $subscription] = ownerWithWorkspace('trialing');
        $subscription->forceFill(['gateway' => 'stripe', 'stripe_subscription_type' => 'workspace:'.$entity->getKey()])->save();
        StripeSubscription::create([
            'user_id' => $owner->getKey(),
            'type' => $subscription->stripe_subscription_type,
            'stripe_id' => 'sub_live',
            'stripe_status' => 'trialing',
        ]);

        actAsBillingOwner($owner);

        Livewire::test(Billing::class)
            ->assertActionVisible(TestAction::make('changePlan')->table($subscription));
    });

    it('returns from checkout to Billing with the success flag', function () {
        [$owner, , $subscription] = ownerWithWorkspace('incomplete');

        actAsBillingOwner($owner);

        $redirect = Livewire::test(Billing::class)
            ->callAction(TestAction::make('choosePlan')->table($subscription), data: [
                'plan_id' => $subscription->plan_id,
                'billing_interval' => BillingInterval::Month->value,
            ])
            ->effects['redirect'];

        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        expect($query['return'])->toBe(Billing::getUrl(['checkout' => 'success'], panel: 'app'));
    });

    it('tells the owner when the checkout cannot start instead of failing (M7)', function () {
        [$owner, , $subscription] = ownerWithWorkspace('incomplete');
        config(['settlo.payment_gateway' => 'stripe', 'cashier.secret' => 'sk_test_fake']);

        actAsBillingOwner($owner);

        Livewire::test(Billing::class)
            ->callAction(TestAction::make('choosePlan')->table($subscription), data: [
                'plan_id' => $subscription->plan_id,
                'billing_interval' => BillingInterval::Month->value,
            ])
            ->assertNotified('The payment could not be started')
            ->assertNoRedirect();

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Incomplete);
    });

    it('chooses a plan and continues to checkout', function () {
        [$owner, $entity, $subscription] = ownerWithWorkspace('incomplete');
        $confidence = Plan::where('code', 'confidence')->firstOrFail();

        actAsBillingOwner($owner);

        Livewire::test(Billing::class)
            ->assertActionVisible(TestAction::make('choosePlan')->table($subscription))
            ->assertActionHidden(TestAction::make('changePlan')->table($subscription))
            ->callAction(TestAction::make('choosePlan')->table($subscription), data: [
                'plan_id' => $confidence->getKey(),
                'billing_interval' => BillingInterval::Year->value,
            ])
            ->assertHasNoActionErrors()
            ->assertRedirectContains('/billing/dummy-checkout/'.$subscription->getKey());

        $subscription->refresh();

        expect($subscription->plan_id)->toBe($confidence->getKey())
            ->and($subscription->billing_interval)->toBe(BillingInterval::Year)
            ->and($subscription->unit_price)->toBe('990.00')
            ->and($subscription->status)->toBe(SubscriptionStatus::Incomplete);
    });

    it('changes the plan of an active workspace', function () {
        [$owner, , $subscription] = ownerWithWorkspace();
        $confidence = Plan::where('code', 'confidence')->firstOrFail();

        actAsBillingOwner($owner);

        Livewire::test(Billing::class)
            ->assertActionHidden(TestAction::make('choosePlan')->table($subscription))
            ->callAction(TestAction::make('changePlan')->table($subscription), data: [
                'plan_id' => $confidence->getKey(),
                'billing_interval' => BillingInterval::Month->value,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Plan updated');

        expect($subscription->fresh()->plan_id)->toBe($confidence->getKey())
            ->and($subscription->fresh()->human_answers_quota)->toBe(3);
    });

    it('rejects an inactive plan', function () {
        [$owner, , $subscription] = ownerWithWorkspace();
        $retired = Plan::factory()->create(['is_active' => false]);

        actAsBillingOwner($owner);

        Livewire::test(Billing::class)
            ->callAction(TestAction::make('changePlan')->table($subscription), data: [
                'plan_id' => $retired->getKey(),
                'billing_interval' => BillingInterval::Month->value,
            ])
            ->assertHasActionErrors(['plan_id']);

        expect($subscription->fresh()->plan_id)->not->toBe($retired->getKey());
    });

    it('cancels and resumes an active workspace', function () {
        [$owner, , $subscription] = ownerWithWorkspace();

        actAsBillingOwner($owner);

        Livewire::test(Billing::class)
            ->assertActionHidden(TestAction::make('resume')->table($subscription))
            ->callAction(TestAction::make('cancel')->table($subscription))
            ->assertNotified('Subscription cancelled');

        expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();

        Livewire::test(Billing::class)
            ->assertActionHidden(TestAction::make('cancel')->table($subscription))
            ->callAction(TestAction::make('resume')->table($subscription))
            ->assertNotified('Subscription resumed');

        expect($subscription->fresh()->cancel_at_period_end)->toBeFalse();
    });

    it('is only available to owners', function () {
        $accountant = User::factory()->accountant()->create();

        $this->actingAs($accountant)->get('/app/billing')->assertForbidden();
    });
});

describe('ask settlo', function () {
    it('meters the human-answer quota per workspace', function () {
        Queue::fake();
        [$owner, $first, $firstSubscription] = ownerWithWorkspace();
        $second = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();
        $secondSubscription = Subscription::factory()->forEntity($second)->onPlan('pro', 1)->active()->create();

        $service = app(AskSettloService::class);
        $conversation = $service->startConversation($owner, $first);
        $service->sendMessage($conversation, 'Do I need to register for VAT?');
        $answer = $conversation->messages()->where('role', 'assistant')->firstOrFail();

        app(EscalationService::class)->escalate($answer, $owner);

        expect($firstSubscription->fresh()->human_answers_used)->toBe(1)
            ->and($secondSubscription->fresh()->human_answers_used)->toBe(0);

        $this->actingAs($owner)
            ->getJson(route('ask-settlo.bootstrap', $second))
            ->assertOk()
            ->assertJsonPath('quota.remaining', 1)
            ->assertJsonPath('quota.billingUrl', Billing::getUrl(['workspace' => $second->getKey()], panel: 'app'));
    });

    it('forbids the chat of an incomplete workspace', function () {
        [$owner, $entity] = ownerWithWorkspace('incomplete');

        $this->actingAs($owner)
            ->getJson(route('ask-settlo.bootstrap', $entity))
            ->assertForbidden();
    });
});

describe('simulated payments on the billing page', function () {
    beforeEach(function () {
        config(['settlo.payment_gateway' => 'simulated']);
    });

    it('says the payment is simulated before it is made', function () {
        [$owner, , $subscription] = ownerWithWorkspace('incomplete');

        actAsBillingOwner($owner);

        $action = Livewire::test(Billing::class)
            ->mountAction(TestAction::make('choosePlan')->table($subscription))
            ->instance()
            ->getMountedAction();

        expect($action->getModalSubmitActionLabel())->toBe('Pay now (simulated)')
            ->and((string) $action->getModalDescription())
            ->toBe('This payment is simulated: no card is charged and no real payment is taken. The business is marked as paid and opens right away.');
    });

    it('warns a trialing workspace that its trial ends now', function () {
        [$owner, , $subscription] = ownerWithWorkspace('trialing');

        actAsBillingOwner($owner);

        $action = Livewire::test(Billing::class)
            ->mountAction(TestAction::make('choosePlan')->table($subscription))
            ->instance()
            ->getMountedAction();

        expect((string) $action->getModalDescription())
            ->toContain('Your free trial ends now and the paid period starts today.');
    });

    it('keeps the real Stripe copy when payments are not simulated', function () {
        [$owner, , $subscription] = ownerWithWorkspace('incomplete');
        config(['settlo.payment_gateway' => 'dummy']);

        actAsBillingOwner($owner);

        $action = Livewire::test(Billing::class)
            ->mountAction(TestAction::make('choosePlan')->table($subscription))
            ->instance()
            ->getMountedAction();

        expect($action->getModalSubmitActionLabel())->toBe('Continue to payment')
            ->and($action->getModalDescription())->toBeNull();
    });

    it('activates the workspace in-process, writes one payment and never redirects', function () {
        [$owner, , $subscription] = ownerWithWorkspace('incomplete');
        $confidence = Plan::where('code', 'confidence')->firstOrFail();

        actAsBillingOwner($owner);

        Livewire::test(Billing::class)
            ->callAction(TestAction::make('choosePlan')->table($subscription), data: [
                'plan_id' => $confidence->getKey(),
                'billing_interval' => BillingInterval::Month->value,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Payment simulated — no card was charged')
            ->assertNoRedirect()
            ->assertActionHidden(TestAction::make('choosePlan')->table($subscription));

        $subscription->refresh();

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->gateway)->toBe('simulated')
            ->and($subscription->plan_id)->toBe($confidence->getKey())
            ->and($subscription->payments()->count())->toBe(1);
    });

    it('hides the pending "activating…" machinery that only Stripe needs', function () {
        [$owner, $entity] = ownerWithWorkspace('incomplete');

        actAsBillingOwner($owner);

        Livewire::withQueryParams(['checkout' => 'success'])
            ->test(Billing::class)
            ->assertNotNotified('Payment received')
            ->assertDontSee('Payment received, activating…');

        expect(Billing::checkoutIsPending())->toBeFalse();

        Livewire::withQueryParams(['workspace' => $entity->getKey()])
            ->test(Billing::class)
            ->assertSet('defaultAction', 'choosePlan');
    });

    it('hides the payment methods portal, which a simulated gateway does not have', function () {
        [$owner] = ownerWithWorkspace();

        actAsBillingOwner($owner);

        Livewire::test(Billing::class)->assertActionHidden('billingPortal');
    });
});
