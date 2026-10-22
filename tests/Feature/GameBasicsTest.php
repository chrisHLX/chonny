<?php

namespace Tests\Feature;

use App\Console\Commands\BuildPopulation;
use App\Http\Services\GameBasicsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A game's Basics tab (GameBasicsService) and the population tables behind it (wow:population).
 * Fixtures are stored analyses as RoundAnalysisService writes them; the norms are the committed
 * data/population/norms.json, so these assert what is read and how it is worded, not today's rates.
 */
class GameBasicsTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(array $over = []): array
    {
        $go = fn (bool $locked, bool $joint, int $healerCc, array $links = []) => [
            'side' => 'us', 'healerCc' => $healerCc, 'links' => $links,
            'peak' => ['healerLocked' => $locked ? 6.0 : 0.0, 'healerKicked' => false, 'joint' => $joint],
            'kill' => null, 'killLater' => false,
        ];

        return $over + [
            'version' => 10,
            'won' => false,
            'players' => [
                ['guid' => 'P-1', 'name' => 'Chill-Realm-US', 'spec' => 'Fire Mage', 'specExternalId' => 63, 'side' => 'us', 'healer' => false, 'logger' => true],
                ['guid' => 'E-1', 'name' => 'Healz-Realm-US', 'spec' => 'Holy Paladin', 'specExternalId' => 65, 'side' => 'them', 'healer' => true, 'logger' => false],
            ],
            'goes' => [
                $go(true, true, 4, [['by' => 'P-1', 'cat' => 'control', 'role' => 'healer', 'spell' => 'Polymorph', 'on' => 'E-1', 't' => 1.0]]),
                $go(false, false, 0),
                ['side' => 'them', 'healerCc' => 0, 'links' => [], 'peak' => ['healerLocked' => 0, 'joint' => false], 'kill' => null, 'killLater' => false],
            ],
            'lockout' => ['P-1' => 10.0],
            'breakdown' => ['P-1' => ['alive' => 180.0, 'idle' => 20.0, 'damage' => [['spell' => 'Pyroblast', 'amount' => 3000000]]]],
            'habits' => ['P-1' => [
                'free' => 170.0, 'control' => 4, 'offTarget' => 3, 'kicks' => 1,
                'kicked' => ['Polymorph' => 4, 'Scorch' => 1],
                'petHits' => 0, 'petOnTarget' => 0,
                'casts' => ['Pyroblast' => 20, 'Fire Blast' => 30],
                'failed' => [
                    'Target not in line of sight' => ['n' => 5, 'spells' => ['Polymorph' => 3, 'Pyroblast' => 2]],
                    'Out of range' => ['n' => 2, 'spells' => ['Dragon\'s Breath' => 2]],
                ],
            ]],
        ];
    }

    public function test_the_basics_read_the_goes_your_casts_and_your_failed_casts(): void
    {
        $b = app(GameBasicsService::class)->forRounds([$this->analysis()]);
        $sections = collect($b['sections'])->keyBy('title');
        $rows = fn (string $title) => collect($sections[$title]['rows'])->keyBy('label');

        $goes = $rows("Your team's goes");
        $this->assertSame('1 of 2', $goes['Enemy healer locked during your burst']['value'], 'only your side\'s goes count');
        $this->assertSame('2.0', $goes['Crowd control on the enemy healer per go']['value']);

        $casts = $rows('Your crowd control and casts');
        $this->assertSame('5', $casts['Your casts that were kicked']['value']);
        $this->assertSame('warn', $casts['Your casts that were kicked']['tone']);
        $this->assertSame('3 of 4', $casts['Control and kicks on someone you were not hitting']['value']);
        $this->assertSame('Polymorph', $casts['Control and kicks on someone you were not hitting']['macro'], 'the spell to put on a focus macro is the one put on their healer');

        $place = $rows('Positioning and macros');
        $this->assertSame('5', $place['Not in line of sight']['value']);
        $this->assertStringContainsString('Polymorph ×3', $place['Not in line of sight']['text']);
        $this->assertFalse($place->has('Out of range'), 'two of a kind is not yet worth a line');
    }

    public function test_a_game_measured_before_version_10_has_no_basics(): void
    {
        $old = $this->analysis(['version' => 9]);
        unset($old['habits']);

        $this->assertNull(app(GameBasicsService::class)->forRounds([$old]));
    }

    public function test_every_number_in_a_line_comes_from_the_evidence_table_or_is_left_out(): void
    {
        $evidence = new ReflectionMethod(GameBasicsService::class, 'evidence');
        $evidence->setAccessible(true);
        $service = app(GameBasicsService::class);

        $this->assertSame('', $evidence->invoke($service, 'no-such-evidence', ' {with}% against {without}%'),
            'a line never states a number the archive does not hold');

        $line = $evidence->invoke($service, 'healerLockedInPeak', '{with}|{without}');
        if ($line !== '') {
            [$with, $without] = explode('|', $line);
            $this->assertMatchesRegularExpression('/^\d+$/', $with);
            $this->assertMatchesRegularExpression('/^\d+$/', $without);
        }
    }

    public function test_the_go_outcome_table_counts_kills_by_condition(): void
    {
        $go = fn (bool $locked, ?float $spread, bool $kill, int $drained = 0) => [
            'side' => 'us', 'good' => true, 'healerCc' => 0, 'pressers' => $spread === null ? 1 : 2, 'spread' => $spread,
            'joint' => false, 'healerLocked' => $locked ? 4.0 : 0.0, 'healerKicked' => false,
            'drained' => $drained, 'defs' => 0, 'kill' => $kill, 'killLater' => $kill,
        ];
        $games = collect([['goes' => [
            $go(true, 1.0, true), $go(true, 8.0, false), $go(false, 1.0, false), $go(false, null, false, 2),
        ]]]);

        $m = new ReflectionMethod(BuildPopulation::class, 'goOutcomes');
        $m->setAccessible(true);
        $out = $m->invoke(app(BuildPopulation::class), $games);

        $this->assertSame(['n' => 4, 'killRate' => 0.25], $out['all']);
        $this->assertSame(['n' => 2, 'killRate' => 0.5], $out['healerLocked']);
        $this->assertSame(['n' => 2, 'killRate' => 0.5], $out['cooldownsTogether'], 'within 3s');
        $this->assertSame(['n' => 1, 'killRate' => 0.0], $out['cooldownsApart']);
        $this->assertSame(1, $out['oneDefensiveDrainedOrMore']['n']);
    }

    /** A version 12 round: their goes on us, each with the ledger at its start, and our presses. */
    private function decisionRound(): array
    {
        $theirGo = fn (float $from, int $bigDown, array $answersUp, int $pressers, bool $killLater = false) => [
            'side' => 'them', 'from' => $from, 'to' => $from + 20, 'healerCc' => 0, 'killLater' => $killLater, 'kill' => null,
            'links' => array_map(fn ($i) => ['by' => "E-{$i}", 'cat' => 'offensive', 't' => 0.0], range(1, $pressers)),
            'peak' => ['healerLocked' => 0.0, 'joint' => false],
            'cover' => ['up' => 0, 'all' => 0, 'bigDown' => $bigDown, 'down' => [], 'answers' => $answersUp],
        ];
        $up = [['who' => 'P-1', 'spell' => 'Ice Block', 'cd' => 240, 'up' => true, 'locked' => false]];
        $lockedUp = [['who' => 'P-1', 'spell' => 'Ice Block', 'cd' => 240, 'up' => true, 'locked' => true]];

        return $this->analysis([
            'version' => 12,
            'goes' => [
                $theirGo(10, 2, $up, 2, true),        // heavy, drained, nothing pressed though Ice Block was back
                $theirGo(40, 2, $lockedUp, 2),        // the same, but its owner was stunned: forced
                $theirGo(70, 0, $up, 1),              // light, two answers, the first before danger
                $theirGo(100, 0, $up, 2),             // heavy, one answer pressed in danger
                $theirGo(200, 0, $up, 2),             // after the first death: left out
            ],
            'defensives' => ['us' => ['rows' => [
                ['t' => 72.0, 'spell' => 'Ice Barrier', 'who' => 'P-1', 'reasons' => []],
                ['t' => 75.0, 'spell' => 'Ice Block', 'who' => 'P-1', 'reasons' => ['danger']],
                ['t' => 101.0, 'spell' => "Gladiator's Medallion", 'who' => 'P-1', 'reasons' => []],
                ['t' => 105.0, 'spell' => 'Ice Block', 'who' => 'P-1', 'reasons' => ['danger']],
            ]], 'them' => ['rows' => []]],
            'deaths' => [['t' => 150.0, 'who' => 'P-1']],
        ]);
    }

    public function test_each_enemy_go_is_read_as_situation_choice_and_outcome(): void
    {
        $rows = \App\Support\DefensiveDecisions::goRows($this->decisionRound());

        $this->assertCount(4, $rows, 'a go after the first death says nothing about what was pressed');
        $this->assertSame(['us', 2, 'heavy', 'none', 0, true], array_values(array_intersect_key($rows[0], array_flip(['defending', 'bigDown', 'pressure', 'choice', 'count', 'died']))));
        $this->assertSame('forced', $rows[1]['choice'], 'nothing pressable when the only answer\'s owner was locked out');
        $this->assertSame(['light', 'early', 2], [$rows[2]['pressure'], $rows[2]['choice'], $rows[2]['count']]);
        $this->assertSame(['late', 1], [$rows[3]['choice'], $rows[3]['count']], 'the Medallion answers control, not damage');
        $this->assertSame([], \App\Support\DefensiveDecisions::goRows($this->analysis()), 'needs version 12');
    }

    public function test_the_basics_set_your_answers_against_goes_like_them(): void
    {
        $b = app(GameBasicsService::class)->forRounds([$this->decisionRound()]);
        $rows = collect(collect($b['sections'])->firstWhere('title', 'Your answers to enemy goes')['rows'])->keyBy('label');

        $drained = $rows['Heavy enemy goes met with nothing pressed, your big defensives already down'] ?? null;
        $this->assertNotNull($drained, 'the committed norms hold the drained-and-heavy cell');
        $this->assertSame('1 of 2', $drained['value'], 'the forced go is in the count but not held against you');
        $this->assertMatchesRegularExpression('/died \d+% of the time when nothing was pressed, against \d+%/', $drained['text']);

        $light = $rows['Light enemy goes that drew two or more answers'] ?? null;
        $this->assertNotNull($light);
        $this->assertSame('1 of 1', $light['value']);
    }
}
