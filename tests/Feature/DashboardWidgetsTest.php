<?php

use App\Enums\AiEscalationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Events\VatAlertRaised;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Widgets\TaxBreakdownWidget;
use App\Filament\App\Widgets\ToDoWidget;
use App\Filament\App\Widgets\VatThresholdWidget;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Tax\TaxEngine;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Give the owner an active subscription on the given plan code.
 */
function subscribeOwner(User $owner, string $code): void
{
    $plan = Plan::where('code', $code)->firstOrFail();
    Subscription::factory()->for($owner, 'user')->create([
        'plan_id' => $plan->getKey(),
        'status' => SubscriptionStatus::Active,
    ]);
}

/**
 * Create an answered-but-unresolved accountant escalation for the tenant.
 */
function answeredEscalation(BusinessEntity $entity, User $owner): AiEscalation
{
    $conversation = AiConversation::create([
        'user_id' => $owner->getKey(),
        'business_entity_id' => $entity->getKey(),
        'title' => 'VAT question',
    ]);

    $message = AiMessage::create([
        'conversation_id' => $conversation->getKey(),
        'role' => 'assistant',
        'content' => 'Answer body',
    ]);

    $escalation = AiEscalation::create([
        'conversation_id' => $conversation->getKey(),
        'message_id' => $message->getKey(),
        'user_id' => $owner->getKey(),
        'user_question' => 'Do I need to register for VAT?',
        'ai_answer' => 'It depends on your revenue.',
    ]);

    $escalation->forceFill([
        'status' => AiEscalationStatus::Answered,
        'answered_at' => now(),
        'resolved_at' => null,
    ])->save();

    return $escalation;
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->entity);
});

it('renders the dashboard widgets with no data', function () {
    subscribeOwner($this->owner, 'pro');

    Livewire::test(TaxBreakdownWidget::class)
        ->assertOk()
        ->assertSee('Complete your tax profile');

    Livewire::test(VatThresholdWidget::class)
        ->assertOk()
        ->assertSee('VAT threshold');

    Livewire::test(ToDoWidget::class)
        ->assertOk()
        ->assertSee('Complete your tax profile');
});

it('renders the tax breakdown widget with an estimation', function () {
    subscribeOwner($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'total' => 40000,
        'issue_date' => now(),
    ]);
    app(TaxEngine::class)->estimateFor($this->entity);

    Livewire::test(TaxBreakdownWidget::class)
        ->assertOk()
        ->assertSee('Total tax burden')
        ->assertSee('Monthly reserve');
});

it('shows a filled VAT progress bar once revenue exists', function () {
    subscribeOwner($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'total' => 50000,
        'issue_date' => now(),
    ]);
    app(TaxEngine::class)->estimateFor($this->entity);

    Livewire::test(VatThresholdWidget::class)
        ->assertOk()
        ->assertSee('%');
});

it('surfaces a draft invoice and an answered escalation in the to-do list', function () {
    subscribeOwner($this->owner, 'pro');

    $draft = Invoice::factory()->for($this->entity, 'businessEntity')->draft()->create();
    answeredEscalation($this->entity, $this->owner);

    Livewire::test(ToDoWidget::class)
        ->assertOk()
        ->assertSee("Send invoice {$draft->invoice_number}")
        ->assertSee('Your accountant answered a question');
});

it('surfaces the VAT alert in the to-do list once the entity is flagged', function () {
    subscribeOwner($this->owner, 'pro');
    $this->entity->forceFill(['vat_alert_level' => 'warning'])->save();

    Livewire::test(ToDoWidget::class)
        ->assertOk()
        ->assertSee('Consider VAT registration');
});

it('fires the VAT notification once when crossing into the warning band', function () {
    subscribeOwner($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'total' => 80000,
        'issue_date' => now(),
    ]);

    app(TaxEngine::class)->estimateFor($this->entity);

    expect($this->entity->fresh()->vat_alert_level)->toBe('warning');
    expect($this->owner->fresh()->notifications()->count())->toBe(1);

    // Re-running with the same figures must not re-notify.
    app(TaxEngine::class)->estimateFor($this->entity->fresh());

    expect($this->owner->fresh()->notifications()->count())->toBe(1);
});

it('resets the stored level on downgrade and re-arms the notification', function () {
    subscribeOwner($this->owner, 'pro');
    $invoice = Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'total' => 80000,
        'issue_date' => now(),
    ]);

    app(TaxEngine::class)->estimateFor($this->entity);
    expect($this->owner->fresh()->notifications()->count())->toBe(1);

    // Revenue drops below the band — level resets, no new notification.
    $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();
    app(TaxEngine::class)->estimateFor($this->entity->fresh());
    expect($this->entity->fresh()->vat_alert_level)->not->toBe('warning');
    expect($this->owner->fresh()->notifications()->count())->toBe(1);

    // Revenue climbs back — the notification fires again.
    $invoice->forceFill(['status' => InvoiceStatus::Sent])->save();
    app(TaxEngine::class)->estimateFor($this->entity->fresh());
    expect($this->owner->fresh()->notifications()->count())->toBe(2);
});

it('broadcasts a VAT alert when the level rises', function () {
    subscribeOwner($this->owner, 'pro');
    Event::fake([VatAlertRaised::class]);

    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'total' => 80000,
        'issue_date' => now(),
    ]);
    app(TaxEngine::class)->estimateFor($this->entity);

    Event::assertDispatched(VatAlertRaised::class, fn (VatAlertRaised $event): bool => $event->businessEntityId === $this->entity->getKey() && $event->level === 'warning');
});

it('renders the dashboard page with the greeting header', function () {
    subscribeOwner($this->owner, 'pro');

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee($this->owner->first_name);
});
