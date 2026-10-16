<?php

namespace Tests\Feature;

use App\Http\Services\RoundAnalysisService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Specialization;
use App\Support\KickPriorities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What to kick (2026-10-16): the priority lists set against the archive (KickPriorities) and the
 * measure built on them (RoundAnalysisService::priorityKicks, version 15).
 */
class KickPrioritiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        KickPriorities::fake(
            ['source' => 'Skill Capped', 'read' => '2026-10-16', 'patch' => '12.1', 'specs' => ['mage/fire' => [
                'url' => 'https://example.test/fire',
                'high' => ['Polymorph', 'Mass Polymorph', 'Ice Wall'],
                'medium' => ['Fireball'],
                'low' => ['Scorch'],
            ]]],
            ['games' => 10, 'specs' => ['mage/fire' => [
                'listed' => [
                    'Polymorph' => ['tier' => 'high', 'ids' => [118, 28272], 'begun' => 100, 'done' => 80, 'stopped' => 10],
                    // Never cast in the archive: not shown at all.
                    'Mass Polymorph' => ['tier' => 'high', 'ids' => [], 'begun' => 0, 'done' => 0, 'stopped' => 0],
                    // Cast often, never stopped: shown, but never a chance.
                    'Ice Wall' => ['tier' => 'high', 'ids' => [352278], 'begun' => 0, 'done' => 60, 'stopped' => 0],
                    'Fireball' => ['tier' => 'medium', 'ids' => [133], 'begun' => 70, 'done' => 300, 'stopped' => 5],
                    'Scorch' => ['tier' => 'low', 'ids' => [2948], 'begun' => 40, 'done' => 40, 'stopped' => 4],
                ],
                'notListed' => ['Ring of Frost' => ['ids' => [113724], 'begun' => 20, 'done' => 15, 'stopped' => 6]],
            ]]],
        );
    }

    protected function tearDown(): void
    {
        KickPriorities::flush();
        parent::tearDown();
    }

    public function test_only_casts_seen_stopped_count_and_never_cast_ones_are_left_out(): void
    {
        $rows = collect(KickPriorities::forSpec('mage/fire'))->keyBy('name');

        $this->assertFalse($rows->has('Mass Polymorph'), 'no one in the archive cast it');
        $this->assertSame(100, $rows['Polymorph']['casts'], 'a spell with a cast time is counted by the casts begun');
        $this->assertSame(70, $rows['Fireball']['casts'], 'instant Fireballs (procs) are not chances to kick');
        $this->assertSame(60, $rows['Ice Wall']['casts'], 'a cast with no start is counted by its casts');
        $this->assertFalse($rows['Ice Wall']['counts'], 'never stopped in 60 casts');
        $this->assertFalse($rows['Scorch']['counts'], 'low tier is shown but not counted');

        $countable = KickPriorities::countable('mage/fire');
        $this->assertSame([118, 28272, 133], array_keys($countable), 'every id a listed cast goes out under');
        $this->assertSame('Ring of Frost', KickPriorities::alsoKicked('mage/fire')[0]['name']);
        $this->assertTrue(KickPriorities::alwaysHasKick('rogue/assassination'));
        $this->assertFalse(KickPriorities::alwaysHasKick('priest/discipline'));
    }

    public function test_a_chance_needs_the_kicker_alive_free_and_with_the_kick_ready(): void
    {
        $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
        $spec = fn (string $class, string $slug, int $ext) => Specialization::create([
            'class_id' => (GameClass::firstWhere('slug', $class) ?? GameClass::create(['game_id' => $game->id, 'name' => ucfirst($class), 'slug' => $class]))->id,
            'name' => ucfirst($slug), 'slug' => $slug, 'external_spec_id' => $ext,
        ]);
        $spec('rogue', 'assassination', 259);
        $spec('priest', 'discipline', 256);
        $spec('mage', 'fire', 63);

        $metadata = ['units' => [['id' => 'P-1', 'spec' => '259'], ['id' => 'P-2', 'spec' => '256'], ['id' => 'E-1', 'spec' => '63']]];
        $habits = ['P-1' => [], 'P-2' => [], 'E-1' => []];
        $sideOf = fn (?string $g) => $g === null ? null : (str_starts_with($g, 'P-') ? 'us' : 'them');
        $credit = fn (string $src) => $src;
        $starts = [
            ['src' => 'E-1', 'id' => 118, 'spell' => 'Polymorph', 't' => 10.0],      // went through
            ['src' => 'E-1', 'id' => 28272, 'spell' => 'Polymorph', 't' => 20.0],    // the Rogue kicks it (a Pig, another id)
            ['src' => 'E-1', 'id' => 118, 'spell' => 'Polymorph', 't' => 25.0],      // Kick on cooldown: no chance
            ['src' => 'E-1', 'id' => 118, 'spell' => 'Polymorph', 't' => 40.0],      // the Rogue is stunned: no chance
            ['src' => 'E-1', 'id' => 133, 'spell' => 'Fireball', 't' => 50.0],       // started and stopped by the Mage: a fake
            ['src' => 'E-1', 'id' => 2948, 'spell' => 'Scorch', 't' => 55.0],        // low tier: not asked about
        ];
        $named = [
            ['src' => 'E-1', 'spell' => 'Polymorph', 't' => 11.7, 'dst' => 'P-2', 'id' => 118],
            ['src' => 'P-1', 'spell' => 'Kick', 't' => 20.4, 'dst' => 'E-1', 'id' => 1766],
            ['src' => 'E-1', 'spell' => 'Scorch', 't' => 55.1, 'dst' => 'P-1', 'id' => 2948],
        ];
        $interrupts = [['t' => 20.4, 'src' => 'P-1', 'dst' => 'E-1', 'spell' => 'Kick', 'stopped' => 'Polymorph', 'stoppedId' => 28272]];
        $locked = ['P-1' => [[39.0, 45.0]]];

        $m = new ReflectionMethod(RoundAnalysisService::class, 'priorityKicks');
        $m->setAccessible(true);
        $out = $m->invokeArgs(app(RoundAnalysisService::class), [$habits, $metadata, $sideOf, $credit, $starts, $named, $interrupts, $locked, []]);

        $p = $out['P-1']['priority'];
        $this->assertSame(3, $p['chances'], 'the Polymorph at 10s and 20s and the Fireball at 50s');
        $this->assertSame([1, 0, 1, 1], [$p['mine'], $p['mate'], $p['landed'], $p['faked']]);
        $this->assertSame(['chances' => 2, 'mine' => 1, 'landed' => 1], $p['high']);
        $this->assertSame(['Polymorph' => 1], $p['through']);
        $this->assertNull($out['P-2']['priority'], 'a Discipline Priest has no interrupt to count');
        $this->assertSame(0, $out['E-1']['priority']['chances'], 'the Mage has Counterspell, and nothing of ours is on a list');
    }

    public function test_a_silence_is_a_stop_and_a_healer_who_never_kicks_is_not_counted(): void
    {
        $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
        foreach ([['priest', 'shadow', 258], ['evoker', 'preservation', 1468], ['mage', 'fire', 63]] as [$class, $slug, $ext]) {
            Specialization::create(['class_id' => GameClass::create(['game_id' => $game->id, 'name' => ucfirst($class), 'slug' => $class])->id,
                'name' => ucfirst($slug), 'slug' => $slug, 'external_spec_id' => $ext]);
        }
        $metadata = ['units' => [['id' => 'P-1', 'spec' => '258'], ['id' => 'P-2', 'spec' => '1468'], ['id' => 'E-1', 'spec' => '63']]];
        $starts = [['src' => 'E-1', 'id' => 118, 'spell' => 'Polymorph', 't' => 10.0]];
        // Silence lands at 10.8, mid-cast: the log writes no interrupt, and the Polymorph never finishes.
        $named = [['src' => 'P-1', 'spell' => 'Silence', 't' => 10.8, 'dst' => 'E-1', 'id' => 15487]];

        $m = new ReflectionMethod(RoundAnalysisService::class, 'priorityKicks');
        $m->setAccessible(true);
        $out = $m->invokeArgs(app(RoundAnalysisService::class), [['P-1' => [], 'P-2' => [], 'E-1' => []], $metadata,
            fn (?string $g) => str_starts_with((string) $g, 'P-') ? 'us' : 'them', fn (string $s) => $s, $starts, $named, [], [], []]);

        $this->assertSame(1, $out['P-1']['priority']['mine'], 'the Silence stopped it');
        $this->assertNull($out['P-2']['priority'], 'a Preservation Evoker who pressed no Quell this round is not measured');
    }

    public function test_a_chance_needs_the_cast_begun_within_reach_of_the_kick(): void
    {
        $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
        foreach ([['rogue', 'assassination', 259], ['mage', 'fire', 63]] as [$class, $slug, $ext]) {
            Specialization::create(['class_id' => GameClass::create(['game_id' => $game->id, 'name' => ucfirst($class), 'slug' => $class])->id,
                'name' => ucfirst($slug), 'slug' => $slug, 'external_spec_id' => $ext]);
        }
        // The Kick the spell data holds: 5 yards, 15s.
        KickPriorities::fake(KickPriorities::doc(), KickPriorities::evidence(), ['rogue' => [['name' => 'Kick', 'range' => 5.0, 'cd' => 15.0]]]);

        $metadata = ['units' => [['id' => 'P-1', 'spec' => '259'], ['id' => 'E-1', 'spec' => '63']]];
        $starts = [
            ['src' => 'E-1', 'id' => 118, 'spell' => 'Polymorph', 't' => 10.0],   // 4 yards away: in reach
            ['src' => 'E-1', 'id' => 118, 'spell' => 'Polymorph', 't' => 20.0],   // 30 yards away: too far
            ['src' => 'E-1', 'id' => 118, 'spell' => 'Polymorph', 't' => 30.0],   // the Rogue's last place is 5s old
        ];
        $named = [
            ['src' => 'E-1', 'spell' => 'Polymorph', 't' => 11.7, 'dst' => 'P-1', 'id' => 118],
            ['src' => 'E-1', 'spell' => 'Polymorph', 't' => 21.7, 'dst' => 'P-1', 'id' => 118],
            ['src' => 'E-1', 'spell' => 'Polymorph', 't' => 31.7, 'dst' => 'P-1', 'id' => 118],
        ];
        $pos = [
            'P-1' => [[9.8, 0.0, 0.0], [19.9, 0.0, 0.0], [25.0, 0.0, 0.0]],
            'E-1' => [[10.2, 4.0, 0.0], [20.1, 30.0, 0.0], [30.0, 6.0, 0.0]],
        ];

        $m = new ReflectionMethod(RoundAnalysisService::class, 'priorityKicks');
        $m->setAccessible(true);
        $out = $m->invokeArgs(app(RoundAnalysisService::class), [['P-1' => [], 'E-1' => []], $metadata,
            fn (?string $g) => str_starts_with((string) $g, 'P-') ? 'us' : 'them', fn (string $s) => $s, $starts, $named, [], [], [], $pos]);

        $p = $out['P-1']['priority'];
        $this->assertSame(1, $p['chances'], 'only the Polymorph begun within 5 yards and a body');
        $this->assertSame(1, $p['landed']);
        $this->assertSame(1, $p['far']);
        $this->assertSame(1, $p['unplaced'], 'a 5-second-old place says nothing about where the Rogue was');
    }
}
