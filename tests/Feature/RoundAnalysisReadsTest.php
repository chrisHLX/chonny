<?php

namespace Tests\Feature;

use App\Http\Services\RoundAnalysisService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Two reads inside RoundAnalysisService that take no database or log, tested on their own:
 * spells used both ways read per press (classifyContextual, version 8), and whether each
 * defensive was needed (warrant, version 9). Each case is one the match review settled
 * (match-review-analysis.md, 2026-10-03/04).
 */
class RoundAnalysisReadsTest extends TestCase
{
    private function invokePrivate(string $method, array $args)
    {
        $m = new ReflectionMethod(RoundAnalysisService::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(app(RoundAnalysisService::class), $args);
    }

    private function sideOf(): \Closure
    {
        return fn (?string $g) => $g === null ? null : (str_starts_with($g, 'P-') ? 'us' : (str_starts_with($g, 'E-') ? 'them' : null));
    }

    /** A damage event on $dst at $t, leaving them at $pct of 1,000,000. */
    private function hit(float $t, string $dst, int $amount, float $pct): array
    {
        return ['t' => $t, 'src' => 'E-1', 'dst' => $dst, 'amount' => $amount, 'spell' => 'Mortal Strike', 'hpAfter' => $pct, 'hpNow' => (int) (10000 * $pct)];
    }

    public function test_vanish_counts_as_defensive_only_when_pressed_in_danger_or_under_their_go(): void
    {
        $tl = ['commitments' => [
            ['t' => 5.0, 'who' => 'E-1', 'spell' => 'Avatar', 'cat' => 'offensive'],
            // Under their go, ours not running: defensive.
            ['t' => 8.0, 'who' => 'P-2', 'spell' => 'Vanish', 'cat' => 'utility'],
            ['t' => 60.0, 'who' => 'P-1', 'spell' => 'Army of the Dead', 'cat' => 'offensive'],
            // In our own go, nobody in danger: a re-stealth opener, not a defensive.
            ['t' => 61.0, 'who' => 'P-2', 'spell' => 'Vanish', 'cat' => 'utility'],
            // In danger (20% health), with nothing running: defensive.
            ['t' => 200.0, 'who' => 'P-2', 'spell' => 'Vanish', 'cat' => 'utility'],
            // A spell not on the list is never touched.
            ['t' => 201.0, 'who' => 'P-2', 'spell' => 'Kidney Shot', 'cat' => 'control'],
        ]];
        $dmg = [$this->hit(199.0, 'P-2', 50000, 20.0)];

        $args = [&$tl, $dmg, $this->sideOf()];
        $this->invokePrivate('classifyContextual', $args);

        $this->assertSame(['offensive', 'defensive', 'offensive', 'utility', 'defensive', 'control'], array_column($tl['commitments'], 'cat'));
        $this->assertTrue($tl['commitments'][1]['contextual']);
        $this->assertArrayNotHasKey('contextual', $tl['commitments'][5]);
    }

    public function test_a_defensive_is_needed_in_danger_or_when_it_breaks_crowd_control(): void
    {
        $defensives = ['us' => ['spent' => 4, 'outsideTheirGoes' => 0, 'rows' => [
            // Pain Suppression from the healer onto the Druid at 30%: read on the Druid, in danger.
            ['t' => 20.0, 'spell' => 'Pain Suppression', 'who' => 'P-1', 'outside' => false],
            // The healer's Medallion as their fear came off: it broke crowd control.
            ['t' => 14.1, 'spell' => "Gladiator's Medallion", 'who' => 'P-1', 'outside' => false],
            // Barkskin at 61% with little coming in, their go running, the healer feared: reasons, not a need.
            ['t' => 13.9, 'spell' => 'Barkskin', 'who' => 'P-2', 'outside' => false],
            // The Druid's own defensive while the healer is locked out: "alone".
            ['t' => 16.0, 'spell' => 'Frenzied Regeneration', 'who' => 'P-2', 'outside' => false],
        ]], 'them' => ['spent' => 0, 'outsideTheirGoes' => 0, 'rows' => []]];
        $named = [
            ['src' => 'P-1', 'spell' => 'Pain Suppression', 't' => 20.0, 'dst' => 'P-2'],
            ['src' => 'P-1', 'spell' => "Gladiator's Medallion", 't' => 14.1, 'dst' => ''],
            ['src' => 'P-2', 'spell' => 'Barkskin', 't' => 13.9, 'dst' => ''],
            ['src' => 'P-2', 'spell' => 'Frenzied Regeneration', 't' => 16.0, 'dst' => ''],
        ];
        $dmg = [
            $this->hit(13.0, 'P-2', 10000, 61.0),
            $this->hit(15.5, 'P-2', 20000, 58.0),
            $this->hit(19.5, 'P-2', 280000, 30.0),
            $this->hit(13.0, 'P-1', 1000, 99.0),
        ];
        $locked = ['P-1' => [[12.4, 14.1], [15.8, 24.7]], 'P-2' => []];
        $tl = ['commitments' => [['t' => 7.2, 'who' => 'E-1', 'spell' => 'Bladestorm', 'cat' => 'offensive']]];
        $credit = fn (string $src) => $src;

        $out = $this->invokePrivate('warrant', [$defensives, $named, $dmg, $locked, ['us' => 'P-1', 'them' => 'E-3'], $tl, $this->sideOf(), $credit]);
        $rows = collect($out['us']['rows'])->keyBy('spell');

        $this->assertSame('P-2', $rows['Pain Suppression']['on'], 'an external is read on whom it went on');
        $this->assertContains('danger', $rows['Pain Suppression']['reasons']);
        $this->assertTrue($rows['Pain Suppression']['needed']);

        $this->assertContains('cc', $rows["Gladiator's Medallion"]['reasons']);
        $this->assertTrue($rows["Gladiator's Medallion"]['needed']);

        // The healer was still feared (12.4-14.1s): reasons, as in the real 11:50 game, but no need.
        $this->assertSame(['focus', 'alone'], $rows['Barkskin']['reasons']);
        $this->assertFalse($rows['Barkskin']['needed']);
        $this->assertSame(61.0, $rows['Barkskin']['hp']);

        $this->assertContains('alone', $rows['Frenzied Regeneration']['reasons'], 'pressed while the healer was locked out');
    }
}
