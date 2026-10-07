<?php

namespace Tests\Feature;

use App\Http\Services\TradePlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The Matchup Lab's trading plan (TradePlanService), read off a hand-built engine run: reductions
 * multiply, a go is judged by what is left at the next one, and the advice follows their cadence.
 */
class TradePlanTest extends TestCase
{
    use RefreshDatabase;

    private function engineRun(array $goTimes, array $spentPerGo, array $liveAtNext): array
    {
        $events = [];
        foreach ($goTimes as $i => $t) {
            $events[] = ['t' => $t, 'side' => 'a', 'target' => 'Feral Druid', 'spent' => $spentPerGo[$i] ?? [], 'killWindow' => false, 'lockedOut' => false];
        }
        $samples = [];
        foreach ($goTimes as $i => $t) {
            $samples[] = ['t' => $t, 'bPool' => [['live' => $liveAtNext[$i] ?? 3], ['live' => 4]]];
        }

        return ['events' => $events, 'samples' => $samples];
    }

    private function spell(string $name): array
    {
        return ['player' => 'Feral Druid', 'spell' => $name, 'spellId' => 1, 'icon' => null, 'kind' => 'cooldown'];
    }

    public function test_stacked_reductions_multiply_and_the_last_one_adds_less(): void
    {
        $stack = new ReflectionMethod(TradePlanService::class, 'stack');
        $stack->setAccessible(true);

        $out = $stack->invoke(app(TradePlanService::class), [
            ['spell' => 'Barkskin', 'reduction' => 0.2],
            ['spell' => 'Survival Instincts', 'reduction' => 0.5],
            ['spell' => 'Pain Suppression', 'reduction' => 0.4],
        ]);

        // 0.8 x 0.5 x 0.6 = 0.24 through: 76% less, not 110%.
        $this->assertSame(76.0, $out['combined']);
        $this->assertSame(100, $out['added'], 'adding them up would claim more than all of it');
        $this->assertSame(16.0, $out['lastAdds'], 'Pain Suppression takes 40% of the 40% left: 16 points');
    }

    public function test_a_go_is_judged_by_what_is_left_at_the_next_one(): void
    {
        $answers = ['Feral Druid' => [
            ['name' => 'Survival Instincts', 'cooldown' => 180, 'kind' => 'cooldown'],
            ['name' => 'Barkskin', 'cooldown' => 60, 'kind' => 'cooldown'],
            ['name' => 'Frenzied Regeneration', 'cooldown' => 36, 'kind' => 'cooldown'],
        ]];
        // Their goes every 30s; at the second go's start the thinnest player holds nothing.
        $plan = app(TradePlanService::class)->plan(
            // The first go spends all three, so the go at 0:40 meets the Feral with nothing back.
            $this->engineRun([10, 40, 70], [[$this->spell('Barkskin'), $this->spell('Survival Instincts'), $this->spell('Frenzied Regeneration')], [], []], [2, 0, 1]),
            'b', $answers,
        );

        $this->assertSame('a', $plan['attacker']);
        $this->assertSame(30, $plan['cadence']);
        $this->assertSame(0, $plan['goes'][0]['heldAtNext'], 'nothing off cooldown when the go at 0:40 came');
        $this->assertSame(2, $plan['goes'][1]['heldAtNext'], 'Frenzied Regeneration (36s) and Barkskin (60s, back at 1:10 exactly) are up by 1:10');
        $this->assertNull($plan['goes'][2]['heldAtNext'], 'no next go to judge the last by');
        $this->assertStringContainsString('every 30s', $plan['advice'][0]);
        $this->assertStringContainsString('One answer a go', $plan['advice'][0], 'only Frenzied Regeneration is back between goes 30s apart');
        $this->assertStringContainsString('At 0:40 their go meets Feral Druid', implode(' ', $plan['advice']));
    }

    public function test_a_team_that_goes_rarely_can_be_met_with_two_answers(): void
    {
        $answers = ['Feral Druid' => [['name' => 'Barkskin', 'cooldown' => 60, 'kind' => 'cooldown']]];
        $plan = app(TradePlanService::class)->plan($this->engineRun([20, 170, 320], [], [3, 3, 3]), 'b', $answers);

        $this->assertSame(150, $plan['cadence']);
        $this->assertStringContainsString('affordable', $plan['advice'][0]);
    }
}
