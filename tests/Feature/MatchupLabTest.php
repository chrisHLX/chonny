<?php

namespace Tests\Feature;

use App\Http\Services\CooldownGraphService;
use App\Http\Services\MatchupProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Matchup Lab's engine and page.
 *
 * NO DATABASE FIXTURES FOR THE ENGINE TESTS, deliberately. `RefreshDatabase` gives an empty
 * schema and spells only ever arrive through `import:spelldata`, never a seeder — so a test that
 * needs a real spec kit either builds an elaborate fixture that drifts from the real data or
 * verifies nothing. CooldownGraphService takes plain profile arrays, which is exactly what makes
 * it testable: the profiles here are hand-written and minimal, and each one exists to make one
 * rule observable rather than to resemble a real spec.
 *
 * Only the smoke test touches the page itself, and it asserts the honest-limits copy is present
 * — the thing most likely to be quietly trimmed by a later layout change, and the thing that
 * makes the difference between a tool and a tool that overclaims.
 */
class MatchupLabTest extends TestCase
{
    // Only the page test needs a schema (an empty one: with nothing seeded the pickers render
    // empty, which is exactly the state the honest-limits copy has to survive). The engine tests
    // never touch the database at all — that is the point of the service taking plain arrays.
    use RefreshDatabase;

    private function profile(string $spec, array $overrides = []): array
    {
        return array_merge([
            'shapeVersion' => MatchupProfileService::PROFILE_SHAPE_VERSION,
            'class' => 'testclass',
            'className' => 'Test',
            'spec' => $spec,
            'specName' => ucfirst($spec),
            'offensive' => [],
            'answers' => [],
            'control' => [],
            'mobility' => [],
            'interrupts' => [],
        ], $overrides);
    }

    private function control(string $name, string $category, float $cooldown, float $duration = 4.0, bool $hard = true): array
    {
        return [
            'spellId' => crc32($name) % 100000,
            'name' => $name,
            'icon' => null,
            'cooldown' => $cooldown,
            'charges' => null,
            'drCategory' => $category,
            'hard' => $hard,
            'duration' => $duration,
            'isPeel' => false,
            'chainTarget' => null,
        ];
    }

    private function answer(string $name, float $cooldown, string $kind = 'cooldown', ?float $duration = 8.0): array
    {
        return [
            'spellId' => crc32($name) % 100000,
            'name' => $name,
            'icon' => null,
            'cooldown' => $cooldown,
            'charges' => null,
            'duration' => $duration,
            'kind' => $kind,
        ];
    }

    private function offensive(string $name, float $cooldown, ?float $duration = 20.0): array
    {
        return [
            'spellId' => crc32($name) % 100000,
            'name' => $name,
            'icon' => null,
            'cooldown' => $cooldown,
            'charges' => null,
            'duration' => $duration,
        ];
    }

    /**
     * A team of a healer plus two DPS, each DPS carrying one stun-category control and one
     * offensive cooldown, and every member carrying $answers defensives.
     */
    private function team(string $prefix, int $answers, float $controlCooldown = 30.0, float $burstCooldown = 60.0): array
    {
        $members = [];

        foreach (['healer', 'dps', 'dps'] as $index => $role) {
            $pool = [];
            for ($i = 0; $i < $answers; $i++) {
                $pool[] = $this->answer("{$prefix}-{$index}-answer-{$i}", 120 + $i);
            }

            $members[] = [
                'role' => $role,
                'profile' => $this->profile("{$prefix}{$index}", [
                    'specName' => "{$prefix}{$index}",
                    'answers' => $pool,
                    // Distinct DR categories per member, so a go can reach two players at once
                    // without the second landing on a diminished category.
                    'control' => [$this->control("{$prefix}-{$index}-cc", ['Stun', 'Incapacitate', 'Disorient'][$index], $controlCooldown)],
                    'offensive' => $role === 'healer' ? [] : [$this->offensive("{$prefix}-{$index}-burst", $burstCooldown)],
                ]),
            ];
        }

        return $members;
    }

    public function test_a_thinner_answer_pool_reaches_its_kill_window_first(): void
    {
        $graph = app(CooldownGraphService::class);

        $result = $graph->run(
            $this->team('a', answers: 6),
            $this->team('b', answers: 2),
            CooldownGraphService::EXECUTION_CLEAN
        );

        $first = ['a' => null, 'b' => null];
        foreach ($result['killWindows'] as $window) {
            $first[$window['side']] ??= $window['t'];
        }

        $this->assertNotNull($first['a'], 'The side attacking the thin pool should reach a window.');
        $this->assertTrue(
            $first['b'] === null || $first['a'] < $first['b'],
            'The side attacking two answers should open a window before the side attacking six.'
        );
        $this->assertSame('a', $result['verdict']['favoured']);
    }

    public function test_only_the_first_kill_window_per_side_is_reported(): void
    {
        $graph = app(CooldownGraphService::class);

        $result = $graph->run(
            $this->team('a', answers: 1),
            $this->team('b', answers: 1),
            CooldownGraphService::EXECUTION_CLEAN
        );

        $perSide = array_count_values(array_column($result['killWindows'], 'side'));

        foreach ($perSide as $side => $count) {
            $this->assertSame(1, $count, "Side {$side} reported {$count} kill windows; only the first is a window.");
        }

        // The goes that follow an emptied pool are still real events — they are Part 3's
        // "you are not allowed to play until you have these buttons back" — just not new windows.
        $this->assertNotEmpty(array_filter($result['events'], fn ($e) => $e['lockedOut']));
    }

    public function test_answering_late_empties_a_pool_sooner_than_trading_cleanly(): void
    {
        $graph = app(CooldownGraphService::class);

        $spentBy = function (string $execution) use ($graph) {
            $result = $graph->run($this->team('a', answers: 4), $this->team('b', answers: 4), $execution);

            $spent = 0;
            foreach ($result['events'] as $event) {
                if ($event['side'] === 'a' && $event['t'] <= 120) {
                    $spent += count($event['spent']);
                }
            }

            return $spent;
        };

        $late = $spentBy(CooldownGraphService::EXECUTION_LATE);
        $clean = $spentBy(CooldownGraphService::EXECUTION_CLEAN);

        // Asserted on answers burned rather than on the moment the window opens: with a deep
        // enough pool BOTH settings are eventually carried over the line by dampening, and
        // comparing the two timestamps then compares two dampening steps rather than the
        // overlap the test is about.
        $this->assertGreaterThan(0, $clean);
        $this->assertGreaterThan($clean, $late, 'Overlapping answers should burn the pool faster than trading one for one.');
    }

    public function test_diminishing_returns_shortens_a_repeated_category(): void
    {
        $graph = app(CooldownGraphService::class);

        // One shared category across both DPS, on a short cooldown: the second landing inside
        // the DR window has to come back diminished, and a third has to be skipped entirely.
        $spam = function (string $prefix) {
            $members = [];
            foreach (['healer', 'dps', 'dps'] as $index => $role) {
                $members[] = [
                    'role' => $role,
                    'profile' => $this->profile("{$prefix}{$index}", [
                        'specName' => "{$prefix}{$index}",
                        'answers' => [$this->answer("{$prefix}-{$index}-a", 120)],
                        'control' => [$this->control("{$prefix}-{$index}-stun", 'Stun', 10.0)],
                        'offensive' => $role === 'healer' ? [] : [$this->offensive("{$prefix}-{$index}-burst", 10.0)],
                    ]),
                ];
            }

            return $members;
        };

        $result = $graph->run($spam('a'), $spam('b'), CooldownGraphService::EXECUTION_CLEAN);

        $diminished = false;
        foreach ($result['events'] as $event) {
            foreach ($event['denied'] as $denied) {
                if ($denied['diminished']) {
                    $diminished = true;
                }
            }
        }

        $this->assertTrue($diminished, 'Repeating one DR category inside the window must land diminished.');
    }

    public function test_a_comp_with_no_hard_control_never_commits_a_go(): void
    {
        $graph = app(CooldownGraphService::class);

        $toothless = [];
        foreach (['healer', 'dps', 'dps'] as $index => $role) {
            $toothless[] = [
                'role' => $role,
                'profile' => $this->profile("t{$index}", [
                    'specName' => "T{$index}",
                    'answers' => [$this->answer("t-{$index}-a", 120)],
                    'control' => [$this->control("t-{$index}-slow", 'Slow', 15.0, 4.0, hard: false)],
                    'offensive' => $role === 'healer' ? [] : [$this->offensive("t-{$index}-burst", 60.0)],
                ]),
            ];
        }

        $result = $graph->run($toothless, $this->team('b', answers: 3), CooldownGraphService::EXECUTION_CLEAN);

        $this->assertSame(0, $result['teams']['a']['goes'], 'Control that denies no globals is not a go (Part 5).');
        $this->assertNull($result['teams']['a']['controlCadence']);
    }

    public function test_the_cadence_is_derived_from_the_comp_not_assumed(): void
    {
        $graph = app(CooldownGraphService::class);

        $result = $graph->run(
            $this->team('a', answers: 3, controlCooldown: 45.0, burstCooldown: 90.0),
            $this->team('b', answers: 3, controlCooldown: 25.0, burstCooldown: 120.0),
            CooldownGraphService::EXECUTION_CLEAN
        );

        $this->assertSame(45.0, (float) $result['teams']['a']['controlCadence']);
        $this->assertSame(25.0, (float) $result['teams']['b']['controlCadence']);

        // The healer carries no offensive cooldown, so the burst cadence is the DPS anchor and
        // nothing else.
        $this->assertSame(90.0, (float) $result['teams']['a']['cooldownCadence']);
        $this->assertSame(120.0, (float) $result['teams']['b']['cooldownCadence']);
    }

    /**
     * The route, through the real middleware stack and the real layout.
     *
     * `Livewire::test()` renders the component WITHOUT its layout, so it cannot see a missing
     * `->layout('layouts.app', ...)` call — this page shipped without one and every Livewire
     * assertion passed while the URL returned a 500 (MissingLayoutException). Only a request
     * through the router catches that class of error.
     */
    public function test_the_route_renders_through_the_real_layout(): void
    {
        $this->get(route('matchup-lab'))
            ->assertOk()
            ->assertSee('Matchup Lab');
    }

    public function test_the_page_always_states_what_it_cannot_see(): void
    {
        Livewire::test(\App\Livewire\MatchupLab::class)
            ->assertOk()
            ->assertSee('No win percentage, on purpose')
            ->assertSee('A window is not a kill')
            ->assertSee('No positioning, no calls');
    }
}
