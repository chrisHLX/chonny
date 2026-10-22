<?php

namespace Tests\Unit;

use App\Http\Services\GameMomentsService;
use Tests\TestCase;

/**
 * Moments from a round's stored timeline (version 16). The round is the 15 Oct game in miniature:
 * their Priest's Mind Control on our Warrior through Pain Suppression and Die by the Sword, dispelled
 * by our Disc Priest after a Penance; Rallying Cry up on everyone; their go on the Warrior.
 */
class GameMomentsServiceTest extends TestCase
{
    private function round(): array
    {
        $hp = fn (int $low) => array_merge(array_fill(0, 90, 90), [30, 26, 26, 26, 26, 26, 26, 26, $low, 40, 55, 60], array_fill(0, 20, 80));

        return [
            'version' => 16,
            'players' => [
                ['guid' => 'me', 'name' => 'Skylake-Frostmourne-US', 'spec' => 'Discipline Priest', 'side' => 'us', 'healer' => true, 'logger' => true],
                ['guid' => 'war', 'name' => 'Terrorizer-MoonGuard-US', 'spec' => 'Arms Warrior', 'side' => 'us', 'healer' => false, 'logger' => false],
                ['guid' => 'dk', 'name' => 'Marciral-Illidan-US', 'spec' => 'Unholy Death Knight', 'side' => 'us', 'healer' => false, 'logger' => false],
                ['guid' => 'pri', 'name' => 'Bigchalupaz-Sargeras-US', 'spec' => 'Discipline Priest', 'side' => 'them', 'healer' => true, 'logger' => false],
                ['guid' => 'dru', 'name' => 'Dogcarryme-Stormrage-US', 'spec' => 'Balance Druid', 'side' => 'them', 'healer' => false, 'logger' => false],
            ],
            'goes' => [
                ['side' => 'them', 'from' => 90.0, 'to' => 104.0, 'target' => 'war', 'kill' => null],
            ],
            'dispels' => [
                ['t' => 99.6, 'by' => 'me', 'on' => 'war', 'spell' => 'Dispel Magic', 'removed' => 'Mind Control'],
            ],
            'debuffs' => [],
            'defensives' => ['us' => ['rows' => [
                ['who' => 'me', 'spell' => 'Pain Suppression', 't' => 91.9],
                ['who' => 'me', 'spell' => 'Fade', 't' => 92.5],
            ]], 'them' => ['rows' => []]],
            'timeline' => [
                'casts' => [
                    'me' => [[90.6, 'Shadow Word: Pain', 'dru'], [91.9, 'Pain Suppression', 'war'], [95.0, 'Shadow Word: Pain', 'dru'], [98.4, 'Penance', 'dru'], [99.6, 'Dispel Magic', 'war'], [99.6, 'Dispel Magic', 'war'], [100.9, 'Power Word: Shield', 'war']],
                    'pri' => [[96.2, 'Mind Control', 'war']],
                ],
                'hp' => ['war' => $hp(20), 'me' => array_fill(0, 125, 99)],
                'control' => [
                    ['war', 'pri', 'Mind Control', 'Disorient', 96.2, 99.6],
                    ['dru', 'war', 'Storm Bolt', 'Stun', 30.0, 34.0],
                ],
                'defUp' => [
                    ['war', 'me', 'Pain Suppression', 91.9, 99.9],
                    ['war', 'war', 'Die by the Sword', 92.3, 100.3],
                    // Rallying Cry is on all three: team-wide, not the Warrior's own cover.
                    ['war', 'war', 'Rallying Cry', 91.8, 103.8],
                    ['me', 'war', 'Rallying Cry', 91.8, 103.8],
                    ['dk', 'war', 'Rallying Cry', 91.8, 103.8],
                    // A grip is tagged defensive but protects no one.
                    ['dru', 'pri', 'Leap of Faith', 31.0, 35.0],
                ],
            ],
        ];
    }

    public function test_control_through_big_defensives_is_one_moment_with_what_it_cost(): void
    {
        $moments = app(GameMomentsService::class)->forRound($this->round(), ['Discipline Priest' => ['Mind Control' => true]]);
        $control = collect($moments)->where('kind', 'control')->values();

        $this->assertCount(1, $control, 'the Storm Bolt through a Leap of Faith is not a moment');
        $m = $control[0];
        $this->assertSame('Mind Control on Terrorizer through Pain Suppression and Die by the Sword', $m['title']);
        $this->assertStringContainsString('lost 3.4s of each', $m['text']);
        $this->assertStringContainsString('You dispelled it (Dispel Magic)', $m['text']);
        $this->assertStringContainsString('You were free to act for 3.4s of it', $m['text'], 'the in-their-go reading folds into it');
        $this->assertStringNotContainsString('Rallying Cry', $m['title']);
        $this->assertSame(['Penance', 'Dispel Magic'], $m['during']['casts']);
        $this->assertSame('warn', $m['tone']);
        // The same control in their go is not shown twice.
        $this->assertCount(0, collect($moments)->where('kind', 'dispel'));
        // The Dispel Magic logged twice at 99.6 shows once in the steps.
        $this->assertSame(1, collect($m['steps'])->where('spell', 'Dispel Magic')->count());
    }

    public function test_the_start_of_their_go_says_what_was_pressed_and_what_answered_it(): void
    {
        $moments = app(GameMomentsService::class)->forRound($this->round());
        $opening = collect($moments)->firstWhere('kind', 'opening');

        $this->assertSame('Their go on Terrorizer', $opening['title']);
        $this->assertStringContainsString('went from 30% to 20%', $opening['text']);
        $this->assertStringContainsString('Your first cast came after 0.6s: Shadow Word: Pain and Pain Suppression', $opening['text']);
        // Pain Suppression is a big defensive; Fade (30s) is not.
        $this->assertStringContainsString('Your Pain Suppression came after 1.9s', $opening['text']);
    }

    public function test_control_in_their_go_without_a_defensive_is_its_own_moment(): void
    {
        $round = $this->round();
        $round['timeline']['defUp'] = [];
        $round['dispels'] = [];
        $moments = app(GameMomentsService::class)->forRound($round, ['Discipline Priest' => ['Mind Control' => true]]);
        $dispel = collect($moments)->firstWhere('kind', 'dispel');

        $this->assertSame('Mind Control on Terrorizer in an enemy go', $dispel['title']);
        $this->assertStringContainsString('It was not dispelled by you', $dispel['text']);
        $this->assertSame('warn', $dispel['tone']);
    }

    public function test_a_round_measured_before_version_16_has_no_moments(): void
    {
        $round = $this->round();
        $round['version'] = 15;
        unset($round['timeline']);

        $this->assertSame([], app(GameMomentsService::class)->forRound($round));
    }
}
