<?php

use App\Livewire\GuestRoadmap;
use App\Models\Category;
use App\Models\FunnelEvent;
use App\Models\Module;
use App\Models\Subject;
use App\Models\User;
use Livewire\Livewire;

function makeGuestRoadmapModule(): Module
{
    $category = Category::create(['name' => 'Games']);
    $subject  = Subject::create(['name' => 'World of Warcraft: The War Within', 'category_id' => $category->id]);

    return Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Diagnostic',
        'type'       => 'diagnostic',
    ]);
}

function fakeGuestQuizResult(int $moduleId): array
{
    return [
        'module_id'          => $moduleId,
        'trait_scores'       => ['reactivity' => 8],
        'survey_answers'     => [
            'current_rating' => ['text' => '1800–2099', 'value' => 3],
            'primary_goal'   => ['text' => 'Push Gladiator or higher', 'value' => 5],
        ],
        'question_evidence'  => [],
        'diagnostic_profile' => [
            'profile_title'       => 'Strategic Controller',
            'primary_growth_area' => ['name' => 'Cooldown Tracking', 'concepts' => []],
        ],
        'completed_at'       => now()->toIso8601String(),
    ];
}

test('guest with a stored diagnostic profile sees a collapsed button and logs profile_viewed once', function () {
    $module = makeGuestRoadmapModule();

    $this->withSession(['guest_quiz_results' => [$module->id => fakeGuestQuizResult($module->id)]]);

    Livewire::test(GuestRoadmap::class, ['moduleId' => $module->id])
        ->assertSet('available', true)
        ->assertSet('revealed', false);

    expect(FunnelEvent::where('event', 'profile_viewed')->where('module_id', $module->id)->count())->toBe(1);
});

test('clicking reveal builds the roadmap and logs roadmap_clicked exactly once', function () {
    $module = makeGuestRoadmapModule();

    $this->withSession(['guest_quiz_results' => [$module->id => fakeGuestQuizResult($module->id)]]);

    Livewire::test(GuestRoadmap::class, ['moduleId' => $module->id])
        ->call('reveal')
        ->assertSet('revealed', true)
        ->call('reveal') // second click must not double-log
        ->assertSet('revealed', true);

    expect(FunnelEvent::where('event', 'roadmap_clicked')->where('module_id', $module->id)->count())->toBe(1);
});

test('component is unavailable when guest session data is missing', function () {
    $module = makeGuestRoadmapModule();

    Livewire::test(GuestRoadmap::class, ['moduleId' => $module->id])
        ->assertSet('available', false)
        ->call('reveal')
        ->assertSet('revealed', false);

    expect(FunnelEvent::query()->count())->toBe(0);
});

test('component is unavailable for authenticated users even with guest session data present', function () {
    $module = makeGuestRoadmapModule();
    $user   = User::factory()->create();

    $this->withSession(['guest_quiz_results' => [$module->id => fakeGuestQuizResult($module->id)]]);

    Livewire::actingAs($user)
        ->test(GuestRoadmap::class, ['moduleId' => $module->id])
        ->assertSet('available', false);

    expect(FunnelEvent::query()->count())->toBe(0);
});
