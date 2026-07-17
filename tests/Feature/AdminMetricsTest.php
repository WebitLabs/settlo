<?php

use App\Enums\AiEscalationStatus;
use App\Enums\ExpenseProcessingStatus;
use App\Filament\Admin\Widgets\GrowthChart;
use App\Filament\Admin\Widgets\MrrOverview;
use App\Filament\Admin\Widgets\OpsOverview;
use App\Filament\Admin\Widgets\PlanMixChart;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->admin = User::factory()->superadmin()->create();
});

function actAsMetricsSuperadmin(): void
{
    test()->actingAs(test()->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

/**
 * @return array<string, Stat>
 */
function statsByLabel(object $widget): array
{
    $method = new ReflectionMethod($widget, 'getStats');
    $method->setAccessible(true);

    $keyed = [];
    foreach ($method->invoke($widget) as $stat) {
        $keyed[(string) $stat->getLabel()] = $stat;
    }

    return $keyed;
}

it('computes MRR, paying/trial counts and conversion from an active/trial mix', function () {
    // Two paying subscriptions: solo (19) + confidence (99) => MRR 118.
    Subscription::factory()->onPlan('solo', 0)->active()->create(['trial_starts_at' => now()->subMonth()]);
    Subscription::factory()->onPlan('confidence', 3)->active()->create(['trial_starts_at' => now()->subMonth()]);

    // Two trials still running (started a trial, not yet converted).
    Subscription::factory()->onPlan('pro', 1)->create();
    Subscription::factory()->onPlan('pro', 1)->create();

    actAsMetricsSuperadmin();

    $stats = statsByLabel(new MrrOverview);

    // 4 started a trial, 2 now active => 50% conversion.
    expect($stats['MRR']->getValue())->toBe('CHF 118')
        ->and($stats['Paying customers']->getValue())->toBe('2')
        ->and($stats['Active trials']->getValue())->toBe('2')
        ->and($stats['Trial conversion']->getValue())->toBe('50%');
});

it('renders MRR overview with a zero-state on an empty database', function () {
    actAsMetricsSuperadmin();

    $stats = statsByLabel(new MrrOverview);

    expect($stats['MRR']->getValue())->toBe('CHF 0')
        ->and($stats['Trial conversion']->getValue())->toBe('0%');

    Livewire::test(MrrOverview::class)->assertOk();
});

it('computes ops metrics for the current month with zero-safe ratios', function () {
    $owner = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();

    $conversation = AiConversation::create([
        'user_id' => $owner->getKey(),
        'business_entity_id' => $entity->getKey(),
        'title' => 'Question',
    ]);

    // Four assistant messages this month.
    foreach (range(1, 4) as $i) {
        $message = new AiMessage;
        $message->forceFill([
            'conversation_id' => $conversation->getKey(),
            'role' => 'assistant',
            'content' => 'Answer '.$i,
        ])->save();
    }

    $messages = AiMessage::query()->orderBy('created_at')->get();

    // One escalation raised this month, answered within SLA.
    $escalation = new AiEscalation;
    $escalation->forceFill([
        'conversation_id' => $conversation->getKey(),
        'message_id' => $messages[0]->getKey(),
        'user_id' => $owner->getKey(),
        'status' => AiEscalationStatus::Answered->value,
        'user_question' => 'VAT?',
        'ai_answer' => 'Not yet.',
        'sla_deadline' => now()->addDay(),
        'answered_at' => now(),
        'sla_breached' => false,
    ])->save();

    // One still-open escalation.
    $open = new AiEscalation;
    $open->forceFill([
        'conversation_id' => $conversation->getKey(),
        'message_id' => $messages[1]->getKey(),
        'user_id' => $owner->getKey(),
        'status' => AiEscalationStatus::Pending->value,
        'user_question' => 'Deductible?',
        'ai_answer' => 'Maybe.',
        'sla_deadline' => now()->addDay(),
    ])->save();

    // OCR: one failed, three extracted => 25% failure rate.
    Expense::factory()->for($entity)->create(['processing_status' => ExpenseProcessingStatus::Failed]);
    Expense::factory()->count(3)->for($entity)->create(['processing_status' => ExpenseProcessingStatus::Extracted]);

    actAsMetricsSuperadmin();

    $stats = statsByLabel(new OpsOverview);

    // 2 escalations raised this month / 4 assistant messages = 50%.
    expect($stats['AI escalation rate']->getValue())->toBe('50%')
        ->and($stats['SLA compliance']->getValue())->toBe('100%')
        ->and($stats['Open escalations']->getValue())->toBe('1')
        ->and($stats['OCR failure rate']->getValue())->toBe('25%');
});

it('renders ops overview zero-state on an empty database', function () {
    actAsMetricsSuperadmin();

    $stats = statsByLabel(new OpsOverview);

    expect($stats['AI escalation rate']->getValue())->toBe('0%')
        ->and($stats['SLA compliance']->getValue())->toBe('0%')
        ->and($stats['OCR failure rate']->getValue())->toBe('0%');

    Livewire::test(OpsOverview::class)->assertOk();
});

it('renders the growth and plan-mix charts', function () {
    Subscription::factory()->onPlan('solo', 0)->active()->create();
    Subscription::factory()->onPlan('confidence', 3)->active()->create();

    actAsMetricsSuperadmin();

    Livewire::test(GrowthChart::class)->assertOk();
    Livewire::test(PlanMixChart::class)->assertOk();

    $method = new ReflectionMethod(PlanMixChart::class, 'getData');
    $method->setAccessible(true);
    $data = $method->invoke(new PlanMixChart);

    // Two distinct active plans present in the doughnut.
    expect($data['labels'])->toHaveCount(2)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(2);

    $growth = new ReflectionMethod(GrowthChart::class, 'getData');
    $growth->setAccessible(true);
    $growthData = $growth->invoke(new GrowthChart);

    expect($growthData['labels'])->toHaveCount(12)
        ->and($growthData['datasets'][0]['data'])->toHaveCount(12);
});
