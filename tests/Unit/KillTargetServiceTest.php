<?php

namespace Tests\Unit;

use App\Http\Services\FallsService;
use App\Http\Services\KillTargetService;
use PHPUnit\Framework\TestCase;

class KillTargetServiceTest extends TestCase
{
    /** Their DPS falls from 100 to 40 in a go with a stun on their healer and two cooldowns. */
    private function analysis(array $over = []): array
    {
        return $over + [
            'version' => 18,
            'players' => [
                ['guid' => 'us-dps', 'side' => 'us', 'healer' => false],
                ['guid' => 'us-heal', 'side' => 'us', 'healer' => true],
                ['guid' => 'them-dps', 'side' => 'them', 'healer' => false],
                ['guid' => 'them-heal', 'side' => 'them', 'healer' => true],
            ],
            'taken' => ['them-dps' => 900, 'them-heal' => 100, 'us-dps' => 300, 'us-heal' => 500],
            'deaths' => [['who' => 'them-dps', 't' => 20.0]],
            'goes' => [['from' => 5.0, 'links' => [
                ['t' => 1.0, 'spell' => 'Feral Frenzy', 'cat' => 'offensive', 'by' => 'us-dps'],
                ['t' => 2.0, 'spell' => 'Feral Frenzy', 'cat' => 'offensive', 'by' => 'us-dps'],
                ['t' => 3.0, 'spell' => 'Bestial Wrath', 'cat' => 'offensive', 'by' => 'us-dps'],
            ]]],
            'timeline' => [
                'hp' => [
                    'them-dps' => [100, 100, 100, 100, 100, 100, 100, 95, 80, 60, 40, 40, 40, 40, 40, 40, 40, 40, 40, 40, 0],
                    'them-heal' => array_fill(0, 21, 100),
                    'us-dps' => array_fill(0, 21, 100),
                    'us-heal' => array_fill(0, 21, 100),
                ],
                'control' => [
                    ['them-heal', 'us-dps', 'Freezing Trap', 'Incapacitate', 6.0, 12.5],
                    ['them-dps', 'us-dps', 'Maim', 'Stun', 7.0, 10.0],
                ],
            ],
            'defensives' => [],
        ];
    }

    private function read(array $analysis): ?array
    {
        return (new KillTargetService(new FallsService))->read($analysis);
    }

    public function test_the_player_who_took_the_most_from_a_team_is_its_first_target_with_their_death(): void
    {
        $read = $this->read($this->analysis());

        $this->assertSame(['them-dps', 'them-heal'], array_column($read['us']['targets'], 'guid'));
        $this->assertSame(20.0, $read['us']['targets'][0]['died']);
        $this->assertNull($read['us']['targets'][1]['died']);
        $this->assertSame(['us-heal', 'us-dps'], array_column($read['them']['targets'], 'guid'));
    }

    public function test_a_go_holds_the_control_on_their_healer_the_drop_and_each_cooldown_once(): void
    {
        $go = $this->read($this->analysis())['us']['goes'][0];

        $this->assertSame('them-dps', $go['on']);
        $this->assertSame([100, 0], [$go['high'], $go['low']]);
        $this->assertSame([['spell' => 'Freezing Trap', 'seconds' => 6.5]], $go['healerCc']);
        $this->assertSame(['Feral Frenzy', 'Bestial Wrath'], $go['cooldowns']);
    }

    public function test_a_round_measured_before_it_has_no_box(): void
    {
        $this->assertNull($this->read(['version' => 17] + $this->analysis(['taken' => null])));
    }

    public function test_only_the_worst_goes_are_kept_and_shown_in_order(): void
    {
        $hp = [];
        for ($i = 0; $i < 10; $i++) {
            $drop = 25 + $i * 5;
            array_push($hp, 100, 100, 100 - $drop, 100 - $drop, 100, 100, 100, 100, 100, 100);
        }
        $analysis = $this->analysis();
        $analysis['timeline']['hp']['them-dps'] = $hp;
        $analysis['timeline']['hp']['them-heal'] = array_fill(0, 100, 100);
        $analysis['deaths'] = [];
        $analysis['timeline']['control'] = array_map(fn ($i) => ['them-heal', 'us-dps', 'Freezing Trap', 'Incapacitate', $i * 10 + 1.0, $i * 10 + 4.0], range(0, 9));

        $read = $this->read($analysis)['us'];

        $this->assertSame(10, $read['allGoes']);
        $this->assertCount(KillTargetService::MOST_GOES, $read['goes']);
        $this->assertSame([55, 50, 45, 40, 35, 30], array_column($read['goes'], 'low'), 'the six deepest of ten, earliest first');
    }
}
