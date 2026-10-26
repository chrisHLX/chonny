<?php

namespace Tests\Feature;

use App\Http\Services\FallBreakdownService;
use App\Support\KickPriorities;
use Tests\TestCase;

/**
 * A fall broken down (FallBreakdownService), in the shapes Chriso read on video (25 Oct 19:11):
 * the chain in order with diminishing returns, a Polymorph on the healer as a kick chance with the
 * Feral free and Skull Bash ready ("I'm free, I should have stopped the clone"), and the falling
 * side as the fall began.
 */
class FallBreakdownTest extends TestCase
{
    protected function tearDown(): void
    {
        KickPriorities::flush();
        parent::tearDown();
    }

    private function frame(string $name, string $side, array $abilities, array $locked = [], ?float $died = null): array
    {
        return ['name' => $name, 'side' => $side, 'color' => '#FFFFFF', 'locked' => $locked, 'died' => $died, 'abilities' => $abilities];
    }

    public function test_a_fall_reads_its_chain_its_kick_chances_and_the_falling_side(): void
    {
        KickPriorities::fake(
            ['specs' => ['mage/fire-test' => ['high' => ['Polymorph'], 'medium' => [], 'low' => []]]],
            ['specs' => ['mage/fire-test' => ['listed' => ['Polymorph' => ['begun' => 40, 'done' => 30, 'stopped' => 9, 'ids' => [118]]]]]],
        );
        $analysis = [
            'players' => [
                ['guid' => 'P-1', 'side' => 'us', 'classSlug' => 'druid', 'specSlug' => 'feral'],
                ['guid' => 'P-H', 'side' => 'us', 'classSlug' => 'priest', 'specSlug' => 'discipline'],
                ['guid' => 'P-2', 'side' => 'us', 'classSlug' => 'hunter', 'specSlug' => 'beast-mastery'],
                ['guid' => 'E-1', 'side' => 'them', 'classSlug' => 'mage', 'specSlug' => 'fire-test'],
                ['guid' => 'E-2', 'side' => 'them', 'classSlug' => 'rogue', 'specSlug' => 'subtlety'],
            ],
            'timeline' => ['control' => [
                ['P-2', 'E-2', 'Cheap Shot', 'Stun', 40.0, 44.0],     // 25s before: reset by the Kidney Shot
                ['P-H', 'E-2', 'Cheap Shot', 'Stun', 64.9, 68.5],
                ['P-2', 'E-2', 'Kidney Shot', 'Stun', 67.4, 71.9],    // full: the last stun on him ended 23s ago
                ['P-H', 'E-1', 'Polymorph', 'Incapacitate', 70.0, 76.0],
                ['P-H', 'E-1', 'Polymorph', 'Incapacitate', 78.0, 81.0], // half: the first ended 2s before
            ]],
        ];
        $fall = ['on' => 'P-2', 'from' => 69.0, 'answers' => [['t' => 75.9, 'spell' => 'Survival of the Fittest', 'who' => 'P-2']], 'links' => [
            ['t' => 64.9, 'spell' => 'Cheap Shot', 'cat' => 'control', 'by' => 'E-2', 'on' => 'P-H', 'role' => 'healer', 'dr' => 'Stun'],
            ['t' => 67.4, 'spell' => 'Kidney Shot', 'cat' => 'control', 'by' => 'E-2', 'on' => 'P-2', 'role' => 'target', 'dr' => 'Stun'],
            ['t' => 70.0, 'spell' => 'Polymorph', 'cat' => 'control', 'by' => 'E-1', 'on' => 'P-H', 'role' => 'healer', 'dr' => 'Incapacitate'],
            ['t' => 78.0, 'spell' => 'Polymorph', 'cat' => 'control', 'by' => 'E-1', 'on' => 'P-H', 'role' => 'healer', 'dr' => 'Incapacitate'],
        ]];
        $frames = [
            // Free, Skull Bash ready at 69 (pressed at 50, 15s cooldown); kicked at 75, so not ready for the second.
            'P-1' => $this->frame('Crawlordx', 'us', [['name' => 'Skull Bash', 'kind' => 'interrupts', 'cd' => 15.0, 'charges' => 1, 'presses' => [50.0, 75.0]]]),
            'P-H' => $this->frame('Nearó', 'us', [['name' => 'Pain Suppression', 'kind' => 'answers', 'cd' => 180.0, 'charges' => 2, 'presses' => [60.0]]], [[64.9, 68.5, 'Cheap Shot']]),
            'P-2' => $this->frame('Doubletapz', 'us', [['name' => "Gladiator's Medallion", 'kind' => 'answers', 'cd' => 120.0, 'charges' => 1, 'presses' => []]], [[67.4, 71.9, 'Kidney Shot']]),
            'E-1' => $this->frame('Noitabretsam', 'them', []),
        ];
        $names = ['P-1' => 'Crawlordx', 'P-H' => 'Nearó', 'P-2' => 'Doubletapz', 'E-1' => 'Noitabretsam', 'E-2' => 'Christopher'];

        $bd = app(FallBreakdownService::class)->for($fall, $analysis, $frames, $names);

        $this->assertSame([-4.1, -1.6, 1.0, 9.0], array_column($bd['steps'], 'at'));
        $this->assertSame('Stun, full', $bd['steps'][1]['dr'], 'reset: 23s since the last stun on him');
        $this->assertSame('Incapacitate, half', $bd['steps'][3]['dr']);
        // The first Polymorph: the Feral free with Skull Bash; the second: nobody (kicked at 75).
        $this->assertSame(['Crawlordx (Skull Bash ready)'], $bd['steps'][2]['kick']);
        $this->assertSame([], $bd['steps'][3]['kick']);
        $this->assertArrayNotHasKey('kick', $bd['steps'][1], 'an instant stun is never a kick chance');

        $byName = collect($bd['defenders'])->keyBy('name');
        $this->assertSame('free', $byName['Crawlordx']['state']);
        $this->assertSame('Kidney Shot, 3s left', $byName['Doubletapz']['state']);
        $this->assertSame(["Gladiator's Medallion"], $byName['Doubletapz']['ready']['answers'], 'his trinket was up under the stun');
        $this->assertSame(['Pain Suppression'], $byName['Nearó']['ready']['answers'], 'two charges: one left');
        $this->assertSame([], $bd['spent']);
        $this->assertSame(['Survival of the Fittest (Doubletapz)'], $bd['answered']);
    }
}
