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

    public function test_damage_is_counted_as_the_meter_counts_it(): void
    {
        $at = fn (string $clock, string $event) => "10/28/2026 14:51:{$clock}  {$event}";
        $me = 'Player-1-AAA';
        $them = 'Player-2-BBB';
        $enemy = "{$them},\"Enemy-Realm-US\",0x548,0x80000000";
        $onMe = "{$me},\"Me-Realm-US\",0x512,0x80000000";
        $lines = [
            // 1,000 to health and 200 into a shield.
            $at('01.000', "SPELL_DAMAGE,{$enemy},{$onMe},458128,\"Festering Scythe\",0x20,{$me},0000000000000000,1111805,1115920,794,3819,3162,3359,0,2091,0,245744,273000,0,1310.52,805.23,0,2.5993,344,1000,1300,-1,32,0,0,200,nil,nil,nil,AOE"),
            // A hit a shield took whole.
            $at('02.000', "SPELL_MISSED,{$enemy},{$onMe},52212,\"Death and Decay\",0x20,ABSORB,nil,500,557,nil,AOE"),
            // Onto a pet on our side (friendly to the logger).
            $at('03.000', "SPELL_DAMAGE,{$enemy},Creature-0-1-1-1-1-0000AAAA,\"Spirit Wolf\",0x1114,0x80000000,52212,\"Death and Decay\",0x20,Creature-0-1-1-1-1-0000AAAA,{$me},546780,560600,12681,12681,2481,0,0,0,3,100,100,0,1310.50,810.69,0,0.7438,344,300,400,-1,32,0,0,0,nil,nil,nil,AOE"),
            // One swing a shield took whole, logged both ways: counted once.
            $at('04.000', "SWING_MISSED,{$enemy},{$onMe},ABSORB,nil,700,900,nil"),
            $at('04.000', "SWING_DAMAGE_LANDED,{$enemy},{$onMe},{$me},0000000000000000,1138473,1138473,466,4197,2218,3605,0,149339,0,226808,250000,0,1314.04,813.39,0,3.1954,340,0,900,-1,1,0,0,700,nil,nil,nil"),
            // A pet's swing logged only as the miss.
            $at('05.000', "SWING_MISSED,Pet-0-1-1-1-1-0000BBBB,\"Ghoul\",0x1148,0x80000000,{$onMe},ABSORB,nil,50,60,nil"),
            // Ours onto their totem (hostile to the logger).
            $at('06.000', "SPELL_DAMAGE,{$me},\"Me-Realm-US\",0x512,0x80000000,Creature-0-1-1-1-2-0000CCCC,\"Totem\",0x2148,0x80000000,51505,\"Lava Burst\",0x4,Creature-0-1-1-1-2-0000CCCC,{$them},5000,5000,0,0,0,0,0,0,3,100,100,0,1310.50,810.69,0,0.7438,344,400,400,-1,4,0,0,0,nil,nil,nil,ST"),
        ];
        $roster = [$me => [], $them => []];
        $sideOf = fn (?string $g) => $g === $me ? 'us' : ($g === $them ? 'them' : null);
        $credit = fn (string $src) => str_starts_with($src, 'Pet-') ? $them : $src;

        $read = $this->invokePrivate('secondPass', [$lines, $roster]);
        [$dmg, , , , , $heals, $absorbs, $casts, $swingOnly] = $read;
        $out = $this->invokePrivate('breakdown', [$roster, $sideOf, $credit, array_merge($dmg, $swingOnly), $read[15], $heals, $absorbs, $casts, [], [], ['durationInSeconds' => 60]]);

        $by = fn (string $g) => array_column($out[$g]['damage'], 'amount', 'spell');
        $this->assertSame(['Festering Scythe' => 1200, 'Death and Decay' => 800, 'Melee' => 700, 'pet: Melee' => 50], $by($them));
        $this->assertSame(['Lava Burst' => 400], $by($me));
        $this->assertSame(1000, $dmg[0]['amount'], 'every other measure still reads only what reached health');
    }

    public function test_habits_read_off_target_control_kicks_a_pets_target_and_failed_casts(): void
    {
        $roster = ['P-1' => [], 'P-2' => [], 'E-1' => [], 'E-2' => []];
        // A pet's hits and presses are its owner's.
        $credit = fn (string $src) => $src === 'Pet-1' ? 'P-1' : $src;
        $dmg = [
            ['t' => 10.0, 'src' => 'P-1', 'dst' => 'E-1', 'amount' => 1000, 'spell' => 'Fireball', 'hpAfter' => 90.0, 'hpNow' => 900],
            ['t' => 10.5, 'src' => 'Pet-1', 'dst' => 'E-1', 'amount' => 100, 'spell' => 'Claw', 'hpAfter' => 89.0, 'hpNow' => 890],
            ['t' => 20.0, 'src' => 'P-1', 'dst' => 'E-2', 'amount' => 1000, 'spell' => 'Fireball', 'hpAfter' => 90.0, 'hpNow' => 900],
            // The pet still on the first target while its owner hits the second.
            ['t' => 21.5, 'src' => 'Pet-1', 'dst' => 'E-1', 'amount' => 100, 'spell' => 'Claw', 'hpAfter' => 88.0, 'hpNow' => 880],
        ];
        $named = [
            // Polymorph on their healer while hitting E-1: off target, what a focus macro is for.
            ['src' => 'P-1', 'spell' => 'Polymorph', 't' => 11.0, 'dst' => 'E-2', 'id' => 118],
            // A stun on the player being hit: on target.
            ['src' => 'P-1', 'spell' => 'Hammer of Justice', 't' => 21.0, 'dst' => 'E-2', 'id' => 853],
            ['src' => 'Pet-1', 'spell' => 'Claw', 't' => 10.5, 'dst' => 'E-1', 'id' => 16827],
        ];
        $interrupts = [
            ['t' => 12.0, 'src' => 'P-1', 'dst' => 'E-1', 'spell' => 'Counterspell', 'stopped' => 'Flash Heal'],
            ['t' => 30.0, 'src' => 'E-1', 'dst' => 'P-1', 'spell' => 'Pummel', 'stopped' => 'Polymorph'],
        ];
        $failed = [
            ['who' => 'P-1', 'spell' => 'Hammer of Justice', 't' => 19.0, 'why' => 'Out of range'],
            // Spam: a key pressed before the spell was back. Not a decision, not counted.
            ['who' => 'P-1', 'spell' => 'Fireball', 't' => 19.5, 'why' => 'Not yet recovered'],
        ];
        $locked = ['P-1' => [[40.0, 45.0]]];
        $metadata = ['durationInSeconds' => 60];
        $ccMap = [118 => 'Incapacitate', 853 => 'Stun'];

        $out = $this->invokePrivate('habits', [$roster, $this->sideOf(), $credit, $named, $dmg, $interrupts, $failed, $locked, [], $metadata, $ccMap]);
        $me = $out['P-1'];

        $this->assertSame(55.0, $me['free'], 'alive 60s, 5s of it locked out');
        $this->assertSame(3, $me['control'], 'two pieces of control and a kick, each with a damage target to compare against');
        $this->assertSame(1, $me['offTarget'], 'only the Polymorph went on someone other than the player being hit');
        $this->assertSame(1, $me['kicks']);
        $this->assertSame(['Polymorph' => 1], $me['kicked'], 'the interrupted spell, not the kick that did it');
        $this->assertSame([2, 1], [$me['petHits'], $me['petOnTarget']]);
        $this->assertSame(1, $me['casts']['pet: Claw']);
        $this->assertSame(['Out of range'], array_keys($me['failed']));
        $this->assertSame(['Hammer of Justice' => 1], $me['failed']['Out of range']['spells']);
    }
}
