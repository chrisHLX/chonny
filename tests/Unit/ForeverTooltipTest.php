<?php

namespace Tests\Unit;

use App\Support\ForeverTooltip;
use PHPUnit\Framework\TestCase;

/** WoW Forever tooltips: resolved only from what the tables state, else null. */
class ForeverTooltipTest extends TestCase
{
    private function effect(array $over = []): array
    {
        return $over + ['EffectBasePointsF' => '0', 'Variance' => '0', 'EffectAuraPeriod' => '0', 'EffectRadiusIndex_0' => '0',
            'EffectRadiusIndex_1' => '0', 'EffectChainTargets' => '0', 'EffectPointsPerResource' => '0'];
    }

    private function ctx(array $effects = [], int $duration = 0, array $over = []): array
    {
        return $over + ['effects' => $effects, 'duration_ms' => $duration, 'radius' => fn (int $i) => $i === 14 ? 8.0 : null,
            'range' => 0.0, 'proc_chance' => 0, 'stacks' => 0, 'charges' => 0, 'max_targets' => 0];
    }

    public function test_plain_tokens_resolve(): void
    {
        $t = new ForeverTooltip;
        $this->assertSame('Stuns the target for 6 sec.', $t->resolve('Stuns the target for $d.', $this->ctx([], 6000)));
        $this->assertSame(
            'A quick kick that injures a single foe for 80 damage.',
            $t->resolve('A quick kick that injures a single foe for $s1 damage.', $this->ctx([$this->effect(['EffectBasePointsF' => '80'])]))
        );
        // Psychic Scream: targets from SpellTargetRestrictions, radius from the effect's second slot
        $this->assertSame(
            'causing 5 enemies within 8 yards to flee for 8 sec.',
            $t->resolve('causing $i enemies within $a1 yards to flee for $d.', $this->ctx([$this->effect(['EffectRadiusIndex_1' => '14'])], 8000, ['max_targets' => 5]))
        );
        $this->assertSame('Lasts 10 min.', $t->resolve('Lasts $d.', $this->ctx([], 600000)));
    }

    public function test_a_periodic_total_and_arithmetic(): void
    {
        $t = new ForeverTooltip;
        $dot = $this->effect(['EffectBasePointsF' => '20', 'EffectAuraPeriod' => '3000']);
        $this->assertSame('120 over 18 sec', $t->resolve('$o1 over $d', $this->ctx([$dot], 18000)));
        $this->assertSame('40 more', $t->resolve('${$s1*2} more', $this->ctx([$dot])));
    }

    public function test_anything_unsettled_is_null(): void
    {
        $t = new ForeverTooltip;
        // a damage range: the effect has a Variance, whose tooltip form is unchecked
        $this->assertNull($t->resolve('causing $s1 Frost damage', $this->ctx([$this->effect(['EffectBasePointsF' => '19', 'Variance' => '0.407'])])));
        $this->assertNull($t->resolve('${$m2$*$<frostdamage>} damage', $this->ctx([$this->effect(), $this->effect()])));
        $this->assertNull($t->resolve('$?s12345[Yes][No]', $this->ctx()));
        $this->assertNull($t->resolve('back to $z.', $this->ctx()));
        $this->assertNull($t->resolve('', $this->ctx()));
    }

    public function test_the_evaluator_takes_only_arithmetic(): void
    {
        $t = new ForeverTooltip;
        $this->assertSame(7.0, $t->evaluate('1+2*3'));
        $this->assertSame(9.0, $t->evaluate('(1+2)*3'));
        $this->assertNull($t->evaluate('1/0'));
        $this->assertNull($t->evaluate('system(1)'));
    }
}
