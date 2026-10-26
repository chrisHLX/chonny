<?php

namespace Tests\Feature;

use App\Http\Services\FallsService;
use Tests\TestCase;

/**
 * Falls: goes found from pressure first, read from a stored round (FallsService), in the shapes
 * Chriso checked on video (25 Oct 19:11). A fall with no offensive cooldown, only a Kidney Shot
 * landing with control on the healer, is a control-led go; under the old go rule it was not a go.
 */
class FallsServiceTest extends TestCase
{
    private function series(array $at): array
    {
        return array_map(fn ($s) => $at[$s] ?? 100, range(0, 120));
    }

    private function round(): array
    {
        return [
            'version' => 16,
            'players' => [
                ['guid' => 'P-1', 'side' => 'us', 'healer' => false], ['guid' => 'P-2', 'side' => 'us', 'healer' => false],
                ['guid' => 'P-H', 'side' => 'us', 'healer' => true], ['guid' => 'E-1', 'side' => 'them', 'healer' => false],
                ['guid' => 'E-2', 'side' => 'them', 'healer' => false], ['guid' => 'E-H', 'side' => 'them', 'healer' => true],
            ],
            // Our Hunter falls 100 -> 41 from 68s; their Mage to 57 at 74-78s.
            'timeline' => [
                'hp' => [
                    'P-1' => $this->series([]), 'P-H' => $this->series([]), 'E-2' => $this->series([]), 'E-H' => $this->series([]),
                    'P-2' => $this->series([68 => 95, 69 => 80, 70 => 60, 71 => 50, 72 => 41, 73 => 45, 74 => 60, 75 => 70, 76 => 80, 77 => 85, 78 => 88]),
                    'E-1' => $this->series([74 => 90, 75 => 75, 76 => 62, 77 => 57, 78 => 70, 79 => 85, 80 => 95]),
                ],
                'control' => [
                    ['P-2', 'E-1', 'Ring of Frost', 'Incapacitate', 62.1, 67.7],
                    ['P-H', 'E-2', 'Cheap Shot', 'Stun', 64.9, 68.5],
                    ['P-2', 'E-2', 'Kidney Shot', 'Stun', 67.4, 71.9],
                    ['P-H', 'E-1', 'Polymorph', 'Incapacitate', 66.3, 72.3],
                    // A slow on the healer is not lockout.
                    ['P-H', 'E-1', 'Frost Nova', 'Root', 66.0, 70.0],
                ],
            ],
            'goes' => [
                ['side' => 'us', 'from' => 10.1, 'links' => [['t' => 0.0, 'spell' => 'Feral Frenzy', 'cat' => 'offensive', 'by' => 'P-1']]],
                ['side' => 'us', 'from' => 73.9, 'links' => [['t' => 0.0, 'spell' => 'Bestial Wrath', 'cat' => 'offensive', 'by' => 'P-2']]],
            ],
            'defensives' => ['us' => ['rows' => []], 'them' => ['rows' => [['t' => 75.0, 'spell' => 'Ice Block', 'who' => 'E-1']]]],
            'deaths' => [],
        ];
    }

    public function test_a_fall_with_only_control_in_it_is_a_control_led_go_synced_on_the_healer(): void
    {
        $falls = app(FallsService::class)->read($this->round())['falls'];

        $theirs = collect($falls)->firstWhere('on', 'P-2');
        $this->assertSame('them', $theirs['side']);
        $this->assertSame('control', $theirs['kind'], 'no offensive cooldown, only control: still a go');
        $this->assertSame(41, $theirs['low']);
        // The closest pair: the Kidney Shot with the Polymorph on our healer, not the Ring of Frost 5s earlier.
        $this->assertSame('Kidney Shot', $theirs['sync']['targetBy']);
        $this->assertSame('Polymorph', $theirs['sync']['healerBy']);
        $this->assertEqualsWithDelta(1.1, $theirs['sync']['gap'], 0.01);
        $this->assertNotContains('Frost Nova', array_column($theirs['links'], 'spell'), 'a root is not lockout');
    }

    public function test_a_cooldown_led_fall_carries_their_answers_and_a_cooldown_in_no_fall_moved_nobody(): void
    {
        $read = app(FallsService::class)->read($this->round());

        $ours = collect($read['falls'])->firstWhere('on', 'E-1');
        $this->assertSame('cooldown', $ours['kind']);
        $this->assertSame(['Bestial Wrath'], array_column(array_filter($ours['links'], fn ($l) => $l['cat'] === 'offensive'), 'spell'));
        $this->assertSame(['Ice Block'], array_column($ours['answers'], 'spell'));

        $this->assertSame(['Feral Frenzy'], array_column($read['unmoved'], 'spell'));
        $this->assertTrue($read['unmoved'][0]['alone']);
    }

    public function test_a_round_measured_before_the_timeline_has_no_falls(): void
    {
        $this->assertSame(['falls' => [], 'unmoved' => []], app(FallsService::class)->read(['version' => 15, 'goes' => []]));
    }
}
