<?php

use App\Enums\AiEscalationStatus;
use App\Enums\AiQuestionCategory;
use App\Filament\Admin\Resources\Escalations\EscalationResource;
use App\Filament\Admin\Resources\Escalations\Pages\ListEscalations;
use App\Filament\Admin\Resources\Escalations\Pages\ViewEscalation;
use App\Models\AccountingFirm;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->admin = User::factory()->superadmin()->create();
});

function actAsEscalationSuperadmin(): void
{
    test()->actingAs(test()->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeAdminEscalation(array $overrides = []): AiEscalation
{
    $owner = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->for($owner, 'owner')->forCanton('ZH')->create();
    $firm = AccountingFirm::factory()->create();

    $conversation = AiConversation::create([
        'user_id' => $owner->getKey(),
        'business_entity_id' => $entity->getKey(),
        'title' => 'VAT question',
    ]);

    $message = AiMessage::create([
        'conversation_id' => $conversation->getKey(),
        'role' => 'assistant',
        'content' => 'You may need to register.',
        'category' => AiQuestionCategory::VatQuestion->value,
    ]);

    $escalation = new AiEscalation;
    $escalation->forceFill(array_merge([
        'conversation_id' => $conversation->getKey(),
        'message_id' => $message->getKey(),
        'user_id' => $owner->getKey(),
        'accounting_firm_id' => $firm->getKey(),
        'category' => AiQuestionCategory::VatQuestion->value,
        'user_question' => 'Do I need to register for VAT?',
        'ai_answer' => 'You may need to register.',
        'status' => AiEscalationStatus::Pending->value,
        'sla_breached' => false,
    ], $overrides));
    $escalation->save();

    return $escalation;
}

it('blocks a non-superadmin from the escalations resource', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/admin/escalations')->assertForbidden();
});

it('lets a superadmin list escalations across firms', function () {
    $escalation = makeAdminEscalation();

    actAsEscalationSuperadmin();

    Livewire::test(ListEscalations::class)
        ->assertCanSeeTableRecords([$escalation]);
});

it('filters escalations by SLA breach', function () {
    $breached = makeAdminEscalation(['sla_breached' => true]);
    $withinSla = makeAdminEscalation(['sla_breached' => false]);

    actAsEscalationSuperadmin();

    Livewire::test(ListEscalations::class)
        ->filterTable('sla_breached', true)
        ->assertCanSeeTableRecords([$breached])
        ->assertCanNotSeeTableRecords([$withinSla]);
});

it('filters escalations by status', function () {
    $pending = makeAdminEscalation(['status' => AiEscalationStatus::Pending->value]);
    $answered = makeAdminEscalation(['status' => AiEscalationStatus::Answered->value]);

    actAsEscalationSuperadmin();

    Livewire::test(ListEscalations::class)
        ->filterTable('status', AiEscalationStatus::Answered->value)
        ->assertCanSeeTableRecords([$answered])
        ->assertCanNotSeeTableRecords([$pending]);
});

it('renders the escalation thread on the view page', function () {
    $escalation = makeAdminEscalation([
        'status' => AiEscalationStatus::Answered->value,
        'accountant_answer' => 'Yes, register now.',
        'accountant_notes' => 'Over threshold last year.',
        'answered_at' => now(),
    ]);

    actAsEscalationSuperadmin();

    Livewire::test(ViewEscalation::class, ['record' => $escalation->getKey()])
        ->assertSuccessful()
        ->assertSee('Do I need to register for VAT?')
        ->assertSee('Yes, register now.');
});

it('is entirely read-only', function () {
    $escalation = makeAdminEscalation();

    expect(EscalationResource::canCreate())->toBeFalse()
        ->and(EscalationResource::canEdit($escalation))->toBeFalse()
        ->and(EscalationResource::canDelete($escalation))->toBeFalse()
        ->and(array_keys(EscalationResource::getPages()))->toBe(['index', 'view']);
});
