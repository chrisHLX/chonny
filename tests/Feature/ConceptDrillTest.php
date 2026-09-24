<?php

use App\Learning\ConceptCoverage;
use App\Learning\ConceptDrillRecord;
use App\Learning\WowConcepts;
use App\Livewire\Quizzes\ConceptDrillPlay;
use App\Livewire\Quizzes\WowQuizIndex;
use App\Models\Concept;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\QuizAttempt;
use App\Models\Specialization;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserConceptMastery;
use App\Quiz\QuizService;
use App\Quiz\Wow\WowAbility;
use App\Quiz\Wow\WowAbilityFacts;
use App\Quiz\Wow\WowQuiz;
use Livewire\Livewire;

/**
 * Concept drills: generated questions scored against a learning-platform concept.
 *
 * As in WowQuizTest, the ability facts are made up — real ones need imported spell data the test
 * database never has. The live output was read end to end against the dev database when this was
 * built (Discipline Priest, all three generable concepts).
 */
function drillAbilities(): array
{
    return [
        new WowAbility(1, 'Zenith', 'a.jpg', cooldown: 90, offensive: true, className: 'Monk'),
        new WowAbility(2, 'Strike of the Windlord', 'b.jpg', cooldown: 30, offensive: true, className: 'Monk'),
        new WowAbility(3, 'Fortifying Brew', 'd.jpg', cooldown: 120, defensive: true, className: 'Monk', usableWhileCc: ['stun']),
        new WowAbility(4, 'Leg Sweep', 'e.jpg', drCategory: 'Stun', cooldown: 60, className: 'Monk', pvpDuration: 4),
        new WowAbility(5, 'Paralysis', 'f.jpg', drCategory: 'Incapacitate', cooldown: 45, className: 'Monk', pvpDuration: 8),
        new WowAbility(6, 'Spear Hand Strike', 'g.jpg', cooldown: 15, interrupt: true, className: 'Monk'),
    ];
}

function drillOthers(): array
{
    return [
        new WowAbility(101, 'Polymorph', 'p.jpg', drCategory: 'Incapacitate', className: 'Mage'),
        new WowAbility(102, 'Fear', 'q.jpg', drCategory: 'Disorient', className: 'Warlock'),
        new WowAbility(103, 'Kidney Shot', 'r.jpg', drCategory: 'Stun', className: 'Rogue'),
        new WowAbility(104, 'Silence', 's.jpg', drCategory: 'Silence', className: 'Priest'),
        new WowAbility(105, 'Frost Nova', 't.jpg', drCategory: 'Root', className: 'Mage'),
    ];
}

function drillSpec(): Specialization
{
    $game = Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
    $class = GameClass::firstOrCreate(['game_id' => $game->id, 'slug' => 'monk'], ['name' => 'Monk']);

    return Specialization::firstOrCreate(['class_id' => $class->id, 'slug' => 'windwalker'], ['name' => 'Windwalker', 'external_spec_id' => 269]);
}

/** The WoW subject and its concepts, as ConceptSeeder writes them. */
function drillConcepts(): Subject
{
    $subject = Subject::firstOrCreate(['name' => 'World of Warcraft: The War Within']);

    foreach (ConceptCoverage::concepts() as $name) {
        Concept::firstOrCreate(['subject_id' => $subject->id, 'name' => $name], ['description' => "About {$name}."]);
    }

    return $subject;
}

function fakeDrillFacts(): void
{
    app()->instance(WowAbilityFacts::class, Mockery::mock(WowAbilityFacts::class, function ($mock) {
        $mock->shouldReceive('specAbilities')->andReturn(drillAbilities());
        $mock->shouldReceive('otherClassAbilities')->andReturn(drillOthers());
        $mock->shouldReceive('ccPool')->andReturn(drillOthers());
    }));
}

test('a concept with a field behind it drills, and one without says why instead', function () {
    drillConcepts();

    expect(ConceptCoverage::isGenerable('Crowd Control'))->toBeTrue()
        ->and(ConceptCoverage::isGenerable('Positioning'))->toBeFalse()
        ->and(ConceptCoverage::unbackedReason('Positioning'))->toContain('where anyone is standing')
        ->and(ConceptCoverage::unbackedReason('Crowd Control'))->toBeNull();

    // Every mapped concept names at least one brain.md section, so a question always has a claim
    // to be argued with. Those ids are comment anchors and must not be renamed.
    foreach (ConceptCoverage::concepts() as $name) {
        expect(ConceptCoverage::brainSectionsFor($name))->not->toBeEmpty();
    }
});

test('the concepts resolve by name whatever their ids are', function () {
    drillConcepts();

    expect(WowConcepts::all()->pluck('name')->all())->toBe(ConceptCoverage::concepts())
        ->and(WowConcepts::findBySlug('crowd-control')?->name)->toBe('Crowd Control')
        ->and(WowConcepts::findBySlug('awareness-tracking')?->name)->toBe('Awareness & Tracking')
        ->and(WowConcepts::findBySlug('not-a-concept'))->toBeNull();
});

test('a drill asks only the question types its concept is mapped to', function () {
    fakeDrillFacts();
    drillConcepts();
    $spec = drillSpec();
    $quiz = app(WowQuiz::class);

    foreach (['Crowd Control', 'Cooldown Management', 'Role Fundamentals'] as $name) {
        $concept = WowConcepts::all()->firstWhere('name', $name);
        $questions = $quiz->questions(WowQuiz::drillSubjectFor($concept, $spec), 1, 8);

        expect($questions)->not->toBeEmpty();

        foreach ($questions as $question) {
            expect(ConceptCoverage::typesFor($name))->toContain($question->type);
        }
    }
});

test('a concept with no generated types produces no questions and no page', function () {
    fakeDrillFacts();
    drillConcepts();
    $spec = drillSpec();

    $positioning = WowConcepts::all()->firstWhere('name', 'Positioning');

    expect(app(WowQuiz::class)->questions(WowQuiz::drillSubjectFor($positioning, $spec), 1, 8))->toBe([]);

    $this->get(route('wow-quiz.drill', ['classSlug' => 'monk', 'specSlug' => 'windwalker', 'conceptSlug' => 'positioning']))
        ->assertNotFound();
});

test('a drill attempt is recorded without ever writing a questions row or a mastery row', function () {
    fakeDrillFacts();
    drillConcepts();
    $spec = drillSpec();
    $user = User::factory()->create();
    $concept = WowConcepts::all()->firstWhere('name', 'Crowd Control');

    $attempt = app(QuizService::class)->start('wow', WowQuiz::drillSubjectFor($concept, $spec), 1, $user, 'session');
    app(QuizService::class)->answer($attempt, 0, $attempt->question(0)->correctKey);

    expect($attempt->fresh()->subject)->toBe("concept:{$concept->id}:{$spec->id}")
        ->and($attempt->fresh()->score)->toBe(1)
        // The whole point of Layer 1: a generated question exists without a row of its own, so
        // the authored bank never grows and MasteryService's denominator never moves.
        ->and(DB::table('questions')->count())->toBe(0)
        ->and(UserConceptMastery::count())->toBe(0);
});

test('the record counts the questions a player was actually asked, newest first', function () {
    drillConcepts();
    $spec = drillSpec();
    $user = User::factory()->create();
    $concept = WowConcepts::all()->firstWhere('name', 'Crowd Control');
    $other = WowConcepts::all()->firstWhere('name', 'Cooldown Management');

    // Two questions, one right — stored the way a real attempt stores them.
    $questions = [
        ['type' => 'dr_category', 'prompt' => 'a', 'subject' => null, 'options' => [['key' => 'Stun', 'label' => 'Stun', 'icon' => null]], 'correct' => 'Stun', 'explanation' => ''],
        ['type' => 'dr_category', 'prompt' => 'b', 'subject' => null, 'options' => [['key' => 'Root', 'label' => 'Root', 'icon' => null]], 'correct' => 'Root', 'explanation' => ''],
    ];

    QuizAttempt::create([
        'user_id' => $user->id, 'game' => 'wow', 'subject' => "concept:{$concept->id}:{$spec->id}",
        'level' => 1, 'questions' => $questions, 'answers' => ['Stun', 'Stun'], 'answered' => 2, 'score' => 1, 'total' => 2,
    ]);

    // Another player's attempt on the same concept must not be counted.
    QuizAttempt::create([
        'user_id' => User::factory()->create()->id, 'game' => 'wow', 'subject' => "concept:{$concept->id}:{$spec->id}",
        'level' => 1, 'questions' => $questions, 'answers' => ['Stun', 'Root'], 'answered' => 2, 'score' => 2, 'total' => 2,
    ]);

    $records = ConceptDrillRecord::forViewer([$concept->id, $other->id], $user, 'session');

    expect($records[$concept->id]->asked)->toBe(2)
        ->and($records[$concept->id]->correct)->toBe(1)
        ->and($records[$concept->id]->percentage())->toBe(50)
        ->and($records[$other->id]->isEmpty())->toBeTrue()
        ->and($records[$other->id]->percentage())->toBeNull();
});

test('the drill page never shows the right answer before the player picks', function () {
    fakeDrillFacts();
    drillConcepts();
    drillSpec();

    $play = Livewire::test(ConceptDrillPlay::class, ['classSlug' => 'monk', 'specSlug' => 'windwalker', 'conceptSlug' => 'crowd-control']);
    $attempt = QuizAttempt::find($play->get('attemptId'));
    $question = $attempt->question(0);

    $play->assertDontSee($question->explanation);

    $play->call('answer', $question->correctKey)
        ->assertSee($question->explanation)
        ->assertSee('Correct');
});

test('the drill pages render over HTTP and the spec page lists every concept', function () {
    fakeDrillFacts();
    drillConcepts();
    drillSpec();

    // A full-page GET, not only a Livewire render: a component missing its ->layout() call passes
    // every Livewire assertion and 500s at the real URL.
    $this->get(route('wow-quiz.drill', ['classSlug' => 'monk', 'specSlug' => 'windwalker', 'conceptSlug' => 'crowd-control']))
        ->assertOk()
        ->assertSee('Crowd Control');

    $this->get(route('wow-quiz.drill', ['classSlug' => 'monk', 'specSlug' => 'nope', 'conceptSlug' => 'crowd-control']))->assertNotFound();

    $this->get(route('wow-quiz', ['classSlug' => 'monk', 'specSlug' => 'windwalker']))
        ->assertOk()
        ->assertSee('Crowd Control')
        // The unbacked ones are listed too, with the reason rather than a button.
        ->assertSee('Positioning')
        ->assertSee('Not generated');
});

test('the spec page shows the viewer their own drill record', function () {
    fakeDrillFacts();
    drillConcepts();
    $spec = drillSpec();
    $user = User::factory()->create();
    $concept = WowConcepts::all()->firstWhere('name', 'Crowd Control');

    QuizAttempt::create([
        'user_id' => $user->id, 'game' => 'wow', 'subject' => "concept:{$concept->id}:{$spec->id}",
        'level' => 1,
        'questions' => [['type' => 'dr_category', 'prompt' => 'a', 'subject' => null, 'options' => [['key' => 'Stun', 'label' => 'Stun', 'icon' => null]], 'correct' => 'Stun', 'explanation' => '']],
        'answers' => ['Stun'], 'answered' => 1, 'score' => 1, 'total' => 1,
    ]);

    Livewire::actingAs($user)->test(WowQuizIndex::class, ['classSlug' => 'monk', 'specSlug' => 'windwalker'])
        ->assertSee('1 right of your last 1');
});
