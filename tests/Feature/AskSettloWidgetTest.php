<?php

use App\Enums\SubscriptionStatus;
use App\Filament\App\Widgets\AskSettloPreview;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    $plan = Plan::where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($this->owner, 'user')->create([
        'plan_id' => $plan->getKey(),
        'status' => SubscriptionStatus::Active,
    ]);

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->entity);
});

function seedPreviewConversation(User $owner, BusinessEntity $entity, string $question, string $answer): AiConversation
{
    $conversation = new AiConversation;
    $conversation->forceFill([
        'user_id' => $owner->getKey(),
        'business_entity_id' => $entity->getKey(),
        'title' => $question,
    ])->save();

    $user = new AiMessage;
    $user->forceFill([
        'conversation_id' => $conversation->getKey(),
        'role' => 'user',
        'content' => $question,
    ])->save();

    $assistant = new AiMessage;
    $assistant->forceFill([
        'conversation_id' => $conversation->getKey(),
        'role' => 'assistant',
        'content' => $answer,
        'confidence' => 0.90,
    ])->save();

    return $conversation;
}

it('renders the empty state when the owner has no conversations', function () {
    Livewire::test(AskSettloPreview::class)
        ->assertOk()
        ->assertSee('Ask Settlo')
        ->assertSee('Ask your first question')
        ->assertSee('Can I deduct this?');
});

it('shows the latest conversation question and answer', function () {
    seedPreviewConversation(
        $this->owner,
        $this->entity,
        'Do I need to register for VAT?',
        'Not yet — your revenue is under the CHF 100,000 threshold.',
    );

    Livewire::test(AskSettloPreview::class)
        ->assertOk()
        ->assertSee('Do I need to register for VAT?')
        ->assertSee('under the CHF 100,000 threshold')
        ->assertSee('How much to reserve?');
});

it('does not surface another owner conversation', function () {
    $otherOwner = User::factory()->owner()->create();
    $otherEntity = BusinessEntity::factory()->forCanton('ZH')->for($otherOwner, 'owner')->create();

    seedPreviewConversation($otherOwner, $otherEntity, 'Secret question from another tenant?', 'Secret answer.');

    Livewire::test(AskSettloPreview::class)
        ->assertOk()
        ->assertDontSee('Secret question from another tenant?')
        ->assertSee('Ask your first question');
});
