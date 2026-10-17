<?php

use App\Livewire\Quizzes\Sc2QuizPlay;
use App\Models\PageViewEvent;
use App\Models\QuizAttempt;
use App\Quiz\QuizService;
use App\Quiz\Sc2\Sc2QuestionBuilder;
use App\Quiz\Sc2\Sc2TechTree;
use App\Quiz\Sc2\Sc2Unit;
use Livewire\Livewire;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * StarCraft II quizzes. Unlike WoW's, the data is two committed files (data/sc2/), so these tests
 * read the real thing. The facts asserted here were checked against the game when it was built
 * (2026-10-18): 5.0.14 against Blizzard's 5.0.14 notes, and the corrections to 5.0.17 from theirs.
 */
function sc2Unit(string $race, string $label): Sc2Unit
{
    return collect(app(Sc2TechTree::class)->race($race))->firstWhere('label', $label);
}

it('prices a morph and a Zerg building as the button does', function () {
    expect(sc2Unit('zerg', 'Baneling')->cost())->toBe('25 / 25')
        ->and(sc2Unit('zerg', 'Ravager')->cost())->toBe('25 / 75')
        ->and(sc2Unit('zerg', 'Brood Lord')->cost())->toBe('150 / 150')
        ->and(sc2Unit('zerg', 'Spawning Pool')->cost())->toBe('200 / 0')
        ->and(sc2Unit('zerg', 'Hatchery')->cost())->toBe('275 / 0')
        ->and(sc2Unit('zerg', 'Greater Spire')->cost())->toBe('100 / 150')
        ->and(sc2Unit('terran', 'Orbital Command')->cost())->toBe('150 / 0')
        ->and(sc2Unit('terran', 'Planetary Fortress')->cost())->toBe('150 / 150');
});

it('brings 5.0.14 data up to the live patch from the corrections', function () {
    $spire = sc2Unit('zerg', 'Spire');
    expect($spire->cost())->toBe('150 / 150')->and($spire->seconds)->toBe(66)
        ->and(sc2Unit('terran', 'Viking')->cost())->toBe('125 / 75')
        ->and(sc2Unit('terran', 'Ghost')->supply)->toBe(2.0)
        ->and(sc2Unit('terran', 'Ghost')->weight())->toBe('Light')
        // Build times not asked until re-extracted (roster.json, noTime).
        ->and(sc2Unit('protoss', 'High Templar')->seconds)->toBeNull();
});

it('reads the tech tree off the game data', function () {
    $ghost = sc2Unit('terran', 'Ghost');
    expect($ghost->madeAt)->toBe('Barracks')->and($ghost->needs)->toBe('Ghost Academy')->and($ghost->needsAddon)->toBe('Tech Lab');

    $broodLord = sc2Unit('zerg', 'Brood Lord');
    expect($broodLord->morphsFrom)->toBe('Corruptor')->and($broodLord->needs)->toBe('Greater Spire');

    expect(sc2Unit('zerg', 'Baneling')->morphsFrom)->toBe('Zergling')
        ->and(sc2Unit('zerg', 'Lair')->needs)->toBe('Spawning Pool')
        ->and(sc2Unit('zerg', 'Hydralisk Den')->needs)->toBe('Lair')
        ->and(sc2Unit('terran', 'Starport')->needs)->toBe('Factory')
        ->and(sc2Unit('zerg', 'Queen')->madeAt)->toBe('Hatchery')
        ->and(sc2Unit('zerg', 'Mutalisk')->madeAt)->toBe('Larva')
        ->and(sc2Unit('terran', 'Hellbat')->madeAt)->toBe('Factory')
        // A requirement on a base town hall is not a question.
        ->and(sc2Unit('terran', 'Engineering Bay')->needs)->toBeNull();

    expect(sc2Unit('terran', 'Marine')->targets)->toBe('both')
        ->and(sc2Unit('protoss', 'Phoenix')->targets)->toBe('air')
        ->and(sc2Unit('zerg', 'Spore Crawler')->targets)->toBe('air')
        // A mode changes what a Viking hits, and the data has no weapon for a Carrier: neither is asked.
        ->and(sc2Unit('terran', 'Viking')->targets)->toBeNull()
        ->and(sc2Unit('protoss', 'Carrier')->targets)->toBeNull();
});

it('deals a full quiz with exactly one right answer for every race and level', function () {
    $tree = app(Sc2TechTree::class);

    foreach (['terran', 'protoss', 'zerg'] as $race) {
        foreach ([1, 2, 3, 4] as $level) {
            foreach (range(1, 15) as $seed) {
                $questions = (new Sc2QuestionBuilder($race, $tree->race($race), new Randomizer(new Mt19937($seed))))->build($level, 8);

                expect($questions)->toHaveCount(8);
                expect(collect($questions)->pluck('subject.label')->unique())->toHaveCount(8);
                foreach ($questions as $q) {
                    $labels = array_column($q->options, 'label');
                    expect(count($labels))->toBeGreaterThanOrEqual(3)
                        ->and(array_unique($labels))->toHaveCount(count($labels))
                        ->and(collect($q->options)->where('key', $q->correctKey))->toHaveCount(1)
                        ->and($q->explanation)->not->toContain('{');
                }
            }
        }
    }
});

it('never offers a building on the unit\'s own tech path as a wrong answer', function () {
    $tree = app(Sc2TechTree::class);
    $builder = new Sc2QuestionBuilder('zerg', $tree->race('zerg'));

    foreach (range(1, 30) as $i) {
        $q = $builder->make('needs', sc2Unit('zerg', 'Brood Lord'));
        expect(collect($q->options)->firstWhere('key', $q->correctKey)['label'])->toBe('Greater Spire');
        expect(array_column($q->options, 'label'))->not->toContain('Hive')->not->toContain('Spire')->not->toContain('Lair')
            ->not->toContain('Infestation Pit')->not->toContain('Spawning Pool');

        $q = $builder->make('made_at', sc2Unit('zerg', 'Corruptor'));
        expect(array_column($q->options, 'label'))->not->toContain('Morphed from a Corruptor');
    }

    // A Ghost needs a Tech Lab too, so a Tech Lab is never the wrong answer beside the Ghost Academy.
    $terran = new Sc2QuestionBuilder('terran', $tree->race('terran'));
    foreach (range(1, 30) as $i) {
        expect(array_column($terran->make('needs', sc2Unit('terran', 'Ghost'))->options, 'label'))->not->toContain('Tech Lab');
    }
});

it('serves the quiz pages, counts them, and links them from /sc2', function () {
    $this->get('/sc2/quiz')->assertOk()->assertSee('StarCraft II quizzes')->assertSee('Zerg')->assertSee('How do you play?', false);
    $this->get('/sc2/quiz/zerg')->assertOk()->assertSee('The tech tree')->assertSee('5.0.17');
    $this->get('/sc2/quiz/zerg/level/3')->assertOk()->assertSee('Level 3');
    $this->get('/sc2/quiz/elves')->assertNotFound();
    $this->get('/sc2/quiz/zerg/level/9')->assertNotFound();

    expect(PageViewEvent::where('page', 'sc2_quiz')->count())->toBe(2)
        ->and(PageViewEvent::where('page', 'sc2_quiz_play')->value('slot'))->toBe('zerg:3');

    $html = $this->get('/sc2')->assertOk()->getContent();
    expect($html)->toContain('Open now')->toContain(route('sc2-quiz'))->toContain('Unit and tech quizzes');
    expect($this->get('/sitemap.xml')->getContent())->toContain(url('/sc2/quiz/protoss'));
});

it('serves the SC2 diagnostic at its own address, under the SC2 menu', function () {
    $category = \App\Models\Category::create(['name' => 'Games']);
    $subject = \App\Models\Subject::create(['name' => 'StarCraft 2', 'category_id' => $category->id]);
    $module = \App\Models\Module::create(['subject_id' => $subject->id, 'name' => 'Commander Profile Assessment', 'type' => 'diagnostic', 'published' => true]);
    config(['app.screener_sc2_module_id' => $module->slug]);

    $html = $this->get('/sc2/diagnostic')->assertOk()->getContent();
    expect($html)->toContain('Commander Profile Assessment')->toContain('SC2 home')->not->toContain('Matchup Lab');
    expect(PageViewEvent::where('page', 'sc2_diagnostic')->count())->toBe(1);

    config(['app.screener_sc2_module_id' => null]);
    $this->get('/sc2/diagnostic')->assertNotFound();
});

it('saves an attempt on the first answer and scores it', function () {
    $play = Livewire::test(Sc2QuizPlay::class, ['race' => 'terran', 'level' => 1]);
    expect(QuizAttempt::count())->toBe(0);   // dealt, not saved (QuizService::PENDING_KEY)

    $question = app(QuizService::class)->pending($play->get('pendingKey'))->question(0);
    $play->call('answer', $question->correctKey)->call('next')->assertSet('index', 1);

    $attempt = QuizAttempt::sole();
    expect($attempt->game)->toBe('sc2')->and($attempt->subject)->toBe('race:terran')
        ->and($attempt->score)->toBe(1)->and($attempt->answered)->toBe(1);
});
