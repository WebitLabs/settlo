<?php

use App\Enums\AiQuestionCategory;
use App\Filament\Admin\Resources\KnowledgeBaseEntries\KnowledgeBaseEntryResource;
use App\Filament\Admin\Resources\KnowledgeBaseEntries\Pages\EditKnowledgeBaseEntry;
use App\Filament\Admin\Resources\KnowledgeBaseEntries\Pages\ListKnowledgeBaseEntries;
use App\Models\KnowledgeBaseEntry;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->admin = User::factory()->superadmin()->create();
});

function actAsKbSuperadmin(): void
{
    test()->actingAs(test()->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function draftEntry(array $overrides = []): KnowledgeBaseEntry
{
    $entry = new KnowledgeBaseEntry;
    $entry->forceFill(array_merge([
        'category' => AiQuestionCategory::VatQuestion,
        'question' => 'Do I need to register for VAT?',
        'answer' => 'Once turnover exceeds CHF 100k.',
        'is_active' => false,
    ], $overrides));
    $entry->save();

    return $entry;
}

it('blocks a non-superadmin from the knowledge base', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/admin/knowledge-base-entries')->assertForbidden();
});

it('lets a superadmin list entries', function () {
    $entry = draftEntry();

    actAsKbSuperadmin();

    Livewire::test(ListKnowledgeBaseEntries::class)
        ->assertCanSeeTableRecords([$entry]);
});

it('approves an entry and writes an audit row', function () {
    $entry = draftEntry();

    actAsKbSuperadmin();

    Livewire::test(ListKnowledgeBaseEntries::class)
        ->callAction(TestAction::make('approve')->table($entry));

    $fresh = $entry->fresh();

    expect($fresh->approved_at)->not->toBeNull()
        ->and($fresh->approved_by_id)->toBe($this->admin->getKey())
        ->and($fresh->is_active)->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'kb.approved',
        'subject_id' => $entry->getKey(),
    ]);
});

it('unapproves an entry and writes an audit row', function () {
    $entry = draftEntry([
        'approved_at' => now(),
        'approved_by_id' => $this->admin->getKey(),
        'is_active' => true,
    ]);

    actAsKbSuperadmin();

    Livewire::test(ListKnowledgeBaseEntries::class)
        ->callAction(TestAction::make('unapprove')->table($entry));

    $fresh = $entry->fresh();

    expect($fresh->approved_at)->toBeNull()
        ->and($fresh->is_active)->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'kb.unapproved',
        'subject_id' => $entry->getKey(),
    ]);
});

it('hides the approve action for an already approved entry', function () {
    $entry = draftEntry(['approved_at' => now()]);

    actAsKbSuperadmin();

    Livewire::test(ListKnowledgeBaseEntries::class)
        ->assertActionHidden(TestAction::make('approve')->table($entry))
        ->assertActionVisible(TestAction::make('unapprove')->table($entry));
});

it('allows deleting a draft but not an approved entry', function () {
    $draft = draftEntry();
    $approved = draftEntry(['approved_at' => now()]);

    expect(KnowledgeBaseEntryResource::canDelete($draft))->toBeTrue()
        ->and(KnowledgeBaseEntryResource::canDelete($approved))->toBeFalse();
});

it('edits an entry and audits the change', function () {
    $entry = draftEntry();

    actAsKbSuperadmin();

    Livewire::test(EditKnowledgeBaseEntry::class, ['record' => $entry->getKey()])
        ->fillForm(['answer' => 'Registration is mandatory above CHF 100k turnover.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($entry->fresh()->answer)->toBe('Registration is mandatory above CHF 100k turnover.');

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'kb.updated',
        'subject_id' => $entry->getKey(),
    ]);
});

it('exposes no create surface on the knowledge base resource', function () {
    expect(KnowledgeBaseEntryResource::canCreate())->toBeFalse()
        ->and(array_keys(KnowledgeBaseEntryResource::getPages()))->toBe(['index', 'edit']);
});
