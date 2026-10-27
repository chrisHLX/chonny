<?php

namespace Tests\Unit;

use App\Http\Services\GameMomentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moments from a round's stored timeline (version 16). The round is the 15 Oct game in miniature:
 * their Priest's Mind Control on our Warrior through Pain Suppression and Die by the Sword, dispelled
 * by our Disc Priest after a Penance; Rallying Cry up on everyone; their go on the Warrior.
 */
class GameMomentsServiceTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertStringContainsString('No heal could reach them for 3.4s', $m['text']);
        $this->assertStringContainsString('and Pain Suppression had run out and Die by the Sword had 0.7s left when it ended', $m['text']);
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

    /** Chriso, 28 Oct: a stun through a defensive is not the play; their healer heals through it. */
    public function test_only_control_that_stops_healing_counts_through_a_defensive(): void
    {
        $round = $this->round();
        $round['timeline']['control'] = [['war', 'pri', 'Kidney Shot', 'Stun', 93.0, 97.0]];

        $this->assertCount(0, collect(app(GameMomentsService::class)->forRound($round))->where('kind', 'control'));
    }

    public function test_a_peel_says_how_much_of_their_cooldown_it_took(): void
    {
        $round = $this->round();
        // Our Warrior's Storm Bolt (30.0 to 34.0) lands on their Balance Druid in Incarnation.
        $round['timeline']['offUp'] = [['dru', 'Incarnation: Chosen of Elune', 28.0, 48.0]];
        $peel = collect(app(GameMomentsService::class)->forRound($round))->firstWhere('kind', 'peel');

        $this->assertSame('Storm Bolt on Dogcarryme during Incarnation: Chosen of Elune', $peel['title']);
        $this->assertStringContainsString('2.0s after Incarnation: Chosen of Elune began: 4.0s of its 20.0s went by in it', $peel['text']);
        $this->assertSame('good', $peel['tone']);
    }

    public function test_an_interrupted_crowd_control_and_one_avoided_are_good_moments(): void
    {
        $round = $this->round();
        $round['timeline']['control'][] = ['me', 'pri', 'Psychic Scream', 'Disorient', 49.0, 53.0];
        $round['timeline']['control'][] = ['war', 'dru', 'Cyclone', 'Disorient', 10.0, 13.0];
        $round['timeline']['kicks'] = [[50.0, 'dk', 'dru', 'Mind Freeze', 'Cyclone', 'Disorient'], [70.0, 'dk', 'dru', 'Mind Freeze', 'Wrath', null]];
        $round['timeline']['casts']['dru'] = [[60.0, 'Cyclone', 'me']];
        $round['timeline']['casts']['me'][] = [59.6, 'Fade', null];
        $moments = collect(app(GameMomentsService::class)->forRound($round));

        $kicks = $moments->where('kind', 'kick')->values();
        $this->assertCount(1, $kicks, 'a kicked Wrath is not crowd control');
        $this->assertSame('Your Unholy Death Knight Marciral interrupted their Balance Druid Dogcarryme\'s Cyclone with Mind Freeze, while you were in Psychic Scream.', $kicks[0]['text']);

        $avoid = $moments->firstWhere('kind', 'avoid');
        $this->assertSame('Cyclone on you avoided with Fade', $avoid['title']);
        $this->assertStringContainsString('You pressed Fade 0.4s before it', $avoid['text']);
    }

    public function test_the_best_go_and_a_window_to_kill(): void
    {
        $round = $this->round();
        // Our go on their Druid at 1:00: Avatar, their healer in Strangulate, the Druid to 40%.
        $round['goes'][] = ['side' => 'us', 'from' => 58.0, 'to' => 70.0, 'target' => 'dru', 'kill' => null,
            'links' => [['t' => 0.0, 'spell' => 'Avatar', 'cat' => 'offensive', 'by' => 'war']]];
        $round['timeline']['control'][] = ['pri', 'dk', 'Strangulate', 'Silence', 61.0, 65.0];
        $round['timeline']['hp']['dru'] = array_merge(array_fill(0, 60, 100), [90, 75, 60, 45, 40, 55, 80, 95], array_fill(0, 60, 100));
        // Their Barkskin at 0:25 and Pain Suppression on the Druid at 0:20; their healer's trinket at 0:22.
        $round['players'][3]['kit'] = [['name' => 'Pain Suppression', 'kind' => 'answers', 'cd' => 180.0, 'charges' => 1], ['name' => "Gladiator's Medallion", 'kind' => 'answers', 'cd' => 120.0, 'charges' => 1]];
        $round['players'][4]['kit'] = [['name' => 'Barkskin', 'kind' => 'answers', 'cd' => 60.0, 'charges' => 1]];
        $round['players'][1]['kit'] = [['name' => 'Avatar', 'kind' => 'offensive', 'cd' => 90.0, 'charges' => 1], ['name' => 'Storm Bolt', 'kind' => 'control', 'cd' => 30.0, 'charges' => 1]];
        $round['timeline']['casts']['pri'][] = [20.0, 'Pain Suppression', 'dru'];
        $round['timeline']['casts']['pri'][] = [22.0, "Gladiator's Medallion", null];
        $round['timeline']['casts']['dru'] = [[25.0, 'Barkskin', null]];
        $round['timeline']['casts']['war'] = [[58.0, 'Avatar', null]];
        $moments = collect(app(GameMomentsService::class)->forRound($round));

        $best = $moments->firstWhere('kind', 'best');
        $this->assertSame('Your best go: Dogcarryme (Balance Druid) to 40%', $best['title']);
        $this->assertStringContainsString("Cooldowns in it: Terrorizer's Avatar.", $best['text']);
        $this->assertStringContainsString('Their healer Bigchalupaz was crowd controlled for 4.0s in a row (Strangulate).', $best['text']);
        $this->assertStringContainsString("Terrorizer's Storm Bolt", (string) $best['extra'], 'ready and not pressed in it');

        $window = $moments->firstWhere('kind', 'window');
        $this->assertSame('Dogcarryme (Balance Druid) with no big defensive for 60s', $window['title']);
        $this->assertStringContainsString("From 0:25 to 1:25, Dogcarryme had none of Bigchalupaz's Pain Suppression and Barkskin ready, and their healer's trinket was down.", $window['text']);
        $this->assertStringContainsString("Your team pressed Terrorizer's Avatar in it.", $window['text']);
        $this->assertSame('neutral', $window['tone']);
    }

    public function test_the_start_of_their_go_says_what_was_pressed_and_what_answered_it(): void
    {
        $moments = app(GameMomentsService::class)->forRound($this->round());
        $opening = collect($moments)->firstWhere('kind', 'opening');

        $this->assertSame('Enemy go on Terrorizer', $opening['title']);
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
