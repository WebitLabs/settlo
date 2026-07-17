<?php

use App\Enums\AiEscalationStatus;
use App\Filament\Firm\Pages\FirmSettings;
use App\Filament\Firm\Widgets\FirmOverview;
use App\Filament\Firm\Widgets\RecentEscalations;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

function dashboardFirmMember(AccountingFirm $firm, User $accountant, bool $owner = true): void
{
    AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $accountant->getKey(),
        'is_owner' => $owner,
        'joined_at' => now(),
    ]);
}

function dashboardAssign(AccountingFirm $firm, BusinessEntity $entity, User $accountant): AccountantAssignment
{
    return AccountantAssignment::create([
        'accounting_firm_id' => $firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => $accountant->getKey(),
        'assigned_at' => now()->subDay(),
        'revoked_at' => null,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function dashboardEscalation(BusinessEntity $entity, User $owner, array $attributes = []): AiEscalation
{
    $conversation = AiConversation::create([
        'user_id' => $owner->getKey(),
        'business_entity_id' => $entity->getKey(),
        'title' => 'Question',
    ]);

    $message = new AiMessage;
    $message->forceFill([
        'conversation_id' => $conversation->getKey(),
        'role' => 'assistant',
        'content' => 'Answer.',
    ])->save();

    $escalation = new AiEscalation;
    $escalation->forceFill(array_merge([
        'conversation_id' => $conversation->getKey(),
        'message_id' => $message->getKey(),
        'user_id' => $owner->getKey(),
        'status' => AiEscalationStatus::Pending->value,
        'user_question' => 'Do I need to register for VAT?',
        'ai_answer' => 'Not yet.',
        'sla_deadline' => now()->addDay(),
    ], $attributes))->save();

    return $escalation;
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->firm = AccountingFirm::factory()->create();
    $this->owner = User::factory()->accountant()->create();
    dashboardFirmMember($this->firm, $this->owner, owner: true);

    $this->client = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->client, 'owner')->create();
    dashboardAssign($this->firm, $this->entity, $this->owner);
});

function actAsFirmDashboard(User $user, AccountingFirm $firm): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant($firm);
}

it('computes overview stats for the current firm tenant only', function () {
    dashboardEscalation($this->entity, $this->client);
    dashboardEscalation($this->entity, $this->client, [
        'status' => AiEscalationStatus::Pending->value,
        'sla_deadline' => now()->addHour(),
    ]);
    dashboardEscalation($this->entity, $this->client, [
        'status' => AiEscalationStatus::Answered->value,
        'accountant_id' => $this->owner->getKey(),
        'accounting_firm_id' => $this->firm->getKey(),
        'answered_at' => now(),
    ]);

    $otherFirm = AccountingFirm::factory()->create();
    $otherAccountant = User::factory()->accountant()->create();
    $otherClient = User::factory()->owner()->create();
    $otherEntity = BusinessEntity::factory()->forCanton('BE')->for($otherClient, 'owner')->create();
    dashboardAssign($otherFirm, $otherEntity, $otherAccountant);
    dashboardEscalation($otherEntity, $otherClient);

    actAsFirmDashboard($this->owner, $this->firm);

    $method = new ReflectionMethod(FirmOverview::class, 'getStats');
    $method->setAccessible(true);
    $stats = $method->invoke(new FirmOverview);

    expect($stats[0]->getValue())->toBe('2')
        ->and($stats[1]->getValue())->toBe('1')
        ->and($stats[2]->getValue())->toBe('1')
        ->and($stats[3]->getValue())->toBe('1');
});

it('renders the overview widget scoped to the firm', function () {
    dashboardEscalation($this->entity, $this->client);
    dashboardEscalation($this->entity, $this->client, [
        'sla_deadline' => now()->addHour(),
    ]);
    dashboardEscalation($this->entity, $this->client, [
        'status' => AiEscalationStatus::Answered->value,
        'accountant_id' => $this->owner->getKey(),
        'accounting_firm_id' => $this->firm->getKey(),
        'answered_at' => now(),
    ]);

    actAsFirmDashboard($this->owner, $this->firm);

    Livewire::test(FirmOverview::class)
        ->assertOk()
        ->assertSee('Pending escalations')
        ->assertSee('Active clients');
});

it('lists only the firm\'s recent escalations in the table widget', function () {
    $mine = dashboardEscalation($this->entity, $this->client);

    $otherFirm = AccountingFirm::factory()->create();
    $otherAccountant = User::factory()->accountant()->create();
    $otherClient = User::factory()->owner()->create();
    $otherEntity = BusinessEntity::factory()->forCanton('BE')->for($otherClient, 'owner')->create();
    dashboardAssign($otherFirm, $otherEntity, $otherAccountant);
    $foreign = dashboardEscalation($otherEntity, $otherClient);

    actAsFirmDashboard($this->owner, $this->firm);

    Livewire::test(RecentEscalations::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('lets a firm owner save firm settings via forceFill on the tenant', function () {
    actAsFirmDashboard($this->owner, $this->firm);

    Livewire::test(FirmSettings::class)
        ->assertOk()
        ->fillForm([
            'name' => 'Updated Firm Name',
            'email' => 'contact@firm.test',
            'phone' => '+41 44 000 00 00',
            'city' => 'Zürich',
        ])
        ->call('save')
        ->assertNotified();

    $this->firm->refresh();

    expect($this->firm->name)->toBe('Updated Firm Name')
        ->and($this->firm->email)->toBe('contact@firm.test')
        ->and($this->firm->phone)->toBe('+41 44 000 00 00')
        ->and($this->firm->city)->toBe('Zürich');
});

it('blocks a non-owner member from firm settings', function () {
    $member = User::factory()->accountant()->create();
    dashboardFirmMember($this->firm, $member, owner: false);

    actAsFirmDashboard($member, $this->firm);

    expect(FirmSettings::canAccess())->toBeFalse();
});
