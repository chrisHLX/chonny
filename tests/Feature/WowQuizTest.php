<?php

use App\Livewire\Quizzes\WowQuizIndex;
use App\Livewire\Quizzes\WowQuizPlay;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\QuizAttempt;
use App\Models\Specialization;
use App\Models\User;
use App\Quiz\QuizService;
use App\Quiz\Wow\WowAbility;
use App\Quiz\Wow\WowAbilityFacts;
use App\Quiz\Wow\WowQuestionBuilder;
use App\Quiz\Wow\WowQuiz;
use Livewire\Livewire;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Class quizzes. Real questions need imported spell data, which the test database never has, so
 * the ability facts are made up here and the rules are tested on those; the real kits were checked
 * against the dev database when this was built.
 */
function quizAbilities(): array
{
    return [
        new WowAbility(1, 'Zenith', 'a.jpg', cooldown: 90, offensive: true, className: 'Monk'),
        new WowAbility(2, 'Strike of the Windlord', 'b.jpg', cooldown: 30, offensive: true, className: 'Monk'),
        new WowAbility(3, 'Touch of Karma', 'c.jpg', cooldown: 90, offensive: true, defensive: true, className: 'Monk'),
        new WowAbility(4, 'Fortifying Brew', 'd.jpg', cooldown: 120, defensive: true, className: 'Monk'),
        new WowAbility(5, 'Leg Sweep', 'e.jpg', drCategory: 'Stun', cooldown: 60, className: 'Monk'),
        new WowAbility(6, 'Paralysis', 'f.jpg', drCategory: 'Incapacitate', cooldown: 45, className: 'Monk'),
        new WowAbility(7, 'Spear Hand Strike', 'g.jpg', cooldown: 15, interrupt: true, className: 'Monk'),
    ];
}

function quizOthers(): array
{
    return [
        new WowAbility(101, 'Polymorph', 'p.jpg', drCategory: 'Incapacitate', className: 'Mage'),
        new WowAbility(102, 'Fear', 'q.jpg', drCategory: 'Disorient', className: 'Warlock'),
        new WowAbility(103, 'Kidney Shot', 'r.jpg', drCategory: 'Stun', className: 'Rogue'),
        new WowAbility(104, 'Silence', 's.jpg', drCategory: 'Silence', className: 'Priest'),
        new WowAbility(105, 'Frost Nova', 't.jpg', drCategory: 'Root', className: 'Mage'),
    ];
}

function quizBuilder(int $seed = 1): WowQuestionBuilder
{
    return new WowQuestionBuilder('Windwalker Monk', quizAbilities(), quizOthers(), quizOthers(), new Randomizer(new Mt19937($seed)));
}

function quizSpec(): Specialization
{
    $game = Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
    $class = GameClass::firstOrCreate(['game_id' => $game->id, 'slug' => 'monk'], ['name' => 'Monk']);

    return Specialization::firstOrCreate(['class_id' => $class->id, 'slug' => 'windwalker'], ['name' => 'Windwalker', 'external_spec_id' => 269]);
}

function fakeQuizFacts(): void
{
    app()->instance(WowAbilityFacts::class, Mockery::mock(WowAbilityFacts::class, function ($mock) {
        $mock->shouldReceive('specAbilities')->andReturn(quizAbilities());
        $mock->shouldReceive('otherClassAbilities')->andReturn(quizOthers());
        $mock->shouldReceive('ccPool')->andReturn(quizOthers());
    }));
}

test('every question has one right answer among four different options', function () {
    foreach (range(1, 20) as $seed) {
        foreach ([1, 2, 3] as $level) {
            foreach (quizBuilder($seed)->build($level, 8) as $question) {
                $keys = array_column($question->options, 'key');

                expect($keys)->toHaveCount(4)
                    ->and(array_unique($keys))->toHaveCount(4)
                    ->and($keys)->toContain($question->correctKey);
            }
        }
    }
});

test('the answers match the facts', function () {
    $builder = quizBuilder();
    [$zenith, , $karma, $brew, $legSweep] = quizAbilities();

    expect($builder->make('ability_role', $zenith)->correctKey)->toBe('offensive')
        ->and($builder->make('dr_category', $legSweep)->correctKey)->toBe('Stun')
        ->and($builder->make('cooldown_length', $brew)->correctKey)->toBe('120')
        ->and($builder->make('is_defensive_cd', $brew)->correctKey)->toBe('4');

    // Leg Sweep shares diminishing returns with the only other Stun.
    expect($builder->make('shares_dr_with', $legSweep)->correctKey)->toBe('103');

    // "Which is yours" never offers another of your own abilities as a wrong answer.
    $wrong = collect($builder->make('which_is_yours', $zenith)->options)->pluck('key')->reject(fn ($k) => $k === '1');
    expect($wrong->every(fn ($k) => (int) $k >= 100))->toBeTrue();
});

test('a question with no single right answer is not asked', function () {
    $builder = quizBuilder();
    $karma = quizAbilities()[2]; // both offensive and defensive

    expect($builder->make('ability_role', $karma))->toBeNull()
        ->and($builder->make('is_offensive_cd', $karma))->toBeNull()
        ->and($builder->make('dr_category', quizAbilities()[0]))->toBeNull();

    // Two offensive cooldowns share the longest cooldown, so there is no clear winner.
    $tied = new WowQuestionBuilder('X', [
        new WowAbility(1, 'A', cooldown: 90, offensive: true),
        new WowAbility(2, 'B', cooldown: 90, offensive: true),
        new WowAbility(3, 'C', cooldown: 30, offensive: true),
    ], [], []);
    expect($tied->make('longest_offensive', quizAbilities()[0]))->toBeNull();
});

test('wrong cooldown answers are clearly different from the right one and each other', function () {
    $question = quizBuilder()->make('cooldown_length', quizAbilities()[1]); // 30 sec
    $values = array_map('intval', array_column($question->options, 'key'));
    sort($values);

    foreach ($values as $i => $value) {
        if ($i > 0) {
            expect($value - $values[$i - 1])->toBeGreaterThanOrEqual(10);
        }
    }
});

test('an answer counts once, only for a real option, and finishing completes the attempt', function () {
    fakeQuizFacts();
    $user = User::factory()->create();
    $service = app(QuizService::class);

    $attempt = $service->start('wow', WowQuiz::subjectFor(quizSpec()), 1, $user, 'session');
    $question = $attempt->question(0);

    expect($service->answer($attempt, 0, 'not-an-option'))->toBeFalse()
        ->and($service->answer($attempt, 0, $question->correctKey))->toBeTrue()
        ->and($service->answer($attempt->fresh(), 0, $question->correctKey))->toBeFalse()
        ->and($attempt->fresh()->score)->toBe(1);

    foreach (range(1, $attempt->total - 1) as $i) {
        $service->answer($attempt->fresh(), $i, $attempt->question($i)->correctKey);
    }

    expect($attempt->fresh()->completed_at)->not->toBeNull()
        ->and($attempt->fresh()->passed())->toBeTrue();
});

test('the play page never shows the right answer before the player picks', function () {
    fakeQuizFacts();
    quizSpec();

    $play = Livewire::test(WowQuizPlay::class, ['classSlug' => 'monk', 'specSlug' => 'windwalker', 'level' => 3]);
    $attempt = QuizAttempt::find($play->get('attemptId'));
    $question = $attempt->question(0);

    $play->assertDontSee($question->explanation)
        ->assertDontSeeHtml('border-green-500');

    $play->call('answer', $question->correctKey)
        ->assertSee($question->explanation)
        ->assertSee('Correct');
});

test('a player cannot answer someone else\'s attempt', function () {
    fakeQuizFacts();
    $spec = quizSpec();
    $owner = User::factory()->create();

    $attempt = app(QuizService::class)->start('wow', WowQuiz::subjectFor($spec), 1, $owner, 'x');

    $play = Livewire::actingAs(User::factory()->create())
        ->test(WowQuizPlay::class, ['classSlug' => 'monk', 'specSlug' => 'windwalker', 'level' => 1]);

    // A request that points the component at another player's attempt is rejected by Livewire.
    expect(fn () => $play->set('attemptId', $attempt->id))->toThrow(Exception::class);
    expect($attempt->fresh()->answers)->toBe([]);
});

test('the quiz pages render over HTTP, and an unknown spec or level is a 404', function () {
    fakeQuizFacts();
    quizSpec();

    $this->get(route('wow-quiz'))->assertOk()->assertSee('Windwalker');
    $this->get(route('wow-quiz', ['classSlug' => 'monk', 'specSlug' => 'windwalker']))->assertOk()->assertSee('Know your kit');
    $this->get(route('wow-quiz.play', ['classSlug' => 'monk', 'specSlug' => 'windwalker', 'level' => 2]))->assertOk();

    $this->get(route('wow-quiz', ['classSlug' => 'monk', 'specSlug' => 'nope']))->assertNotFound();
    $this->get(route('wow-quiz.play', ['classSlug' => 'monk', 'specSlug' => 'windwalker', 'level' => 9]))->assertNotFound();
});

test('best scores are the viewer\'s own, and the next level to take follows them', function () {
    fakeQuizFacts();
    $spec = quizSpec();
    $user = User::factory()->create();
    $subject = WowQuiz::subjectFor($spec);

    QuizAttempt::create(['user_id' => $user->id, 'game' => 'wow', 'subject' => $subject, 'level' => 1, 'questions' => [], 'score' => 8, 'total' => 8, 'completed_at' => now()]);
    QuizAttempt::create(['user_id' => User::factory()->create()->id, 'game' => 'wow', 'subject' => $subject, 'level' => 2, 'questions' => [], 'score' => 8, 'total' => 8, 'completed_at' => now()]);

    $best = app(QuizService::class)->bestByLevel('wow', $subject, $user, 'x');
    expect(array_keys($best))->toBe([1]);

    Livewire::actingAs($user)->test(WowQuizIndex::class, ['classSlug' => 'monk', 'specSlug' => 'windwalker'])
        ->assertSee('Passed')
        ->assertSee('Best: 8 / 8');
});

test('the leaderboard ranks signed-in players by questions answered and leaves guests out', function () {
    fakeQuizFacts();
    $subject = WowQuiz::subjectFor(quizSpec());
    $alice = User::factory()->create(['username' => 'alice']);
    $bob = User::factory()->create(['username' => 'bob']);

    $row = fn (?User $u, int $answered, int $score) => QuizAttempt::create([
        'user_id' => $u?->id, 'session_id' => $u ? null : 'guest', 'game' => 'wow', 'subject' => $subject,
        'level' => 1, 'questions' => [], 'answered' => $answered, 'score' => $score, 'total' => 8,
    ]);
    $row($alice, 8, 6);
    $row($bob, 8, 8);
    $row($bob, 4, 2);
    $row(null, 40, 40);

    $board = app(QuizService::class)->leaderboard('wow');

    expect($board->map(fn ($r) => [$r->user->username, $r->answered, $r->correct])->all())
        ->toBe([['bob', 12, 10], ['alice', 8, 6]]);

    $this->get(route('home'))->assertOk()->assertSeeInOrder(['Most questions answered', '@bob', '@alice']);
});

test('starting a quiz as a guest remembers the session it was taken under, for claiming at sign-in', function () {
    fakeQuizFacts();
    $service = app(QuizService::class);
    $subject = WowQuiz::subjectFor(quizSpec());

    $service->start('wow', $subject, 1, null, 'sess-a');
    $service->start('wow', $subject, 2, null, 'sess-a');
    $service->start('wow', $subject, 1, User::factory()->create(), 'sess-b');

    expect(session(QuizService::GUEST_SESSIONS_KEY))->toBe(['sess-a']);
});

test('answering updates the answered count', function () {
    fakeQuizFacts();
    $service = app(QuizService::class);
    $attempt = $service->start('wow', WowQuiz::subjectFor(quizSpec()), 3, User::factory()->create(), 's');

    $service->answer($attempt, 0, $attempt->question(0)->correctKey);
    $service->answer($attempt->fresh(), 1, $attempt->question(1)->correctKey);

    expect($attempt->fresh()->answered)->toBe(2);
});

test('the spec picker shows which specs you have finished, and your results', function () {
    fakeQuizFacts();
    $spec = quizSpec();
    $user = User::factory()->create();

    QuizAttempt::create(['user_id' => $user->id, 'game' => 'wow', 'subject' => WowQuiz::subjectFor($spec), 'level' => 1,
        'questions' => [], 'answered' => 8, 'score' => 7, 'total' => 8, 'completed_at' => now()]);

    Livewire::actingAs($user)->test(WowQuizIndex::class)
        ->assertSee('Your results')
        ->assertSee('L1 7/8')
        ->assertSee('1/3 passed')
        ->assertSeeHtml('border-green-500/50 bg-green-500/5');

    // Someone who has taken nothing sees no results section.
    Livewire::actingAs(User::factory()->create())->test(WowQuizIndex::class)->assertDontSee('Your results');
});
