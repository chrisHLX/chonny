<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Strategy teaser.
 *
 * The route test is the one that matters: `Livewire::test()` renders a component without its
 * layout, so a full-page component missing its `->layout(...)` call passes every Livewire
 * assertion while the URL 500s. That shipped once already on the Matchup Lab (2026-09-23).
 */
class StrategyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_route_renders_through_the_real_layout(): void
    {
        $this->get(route('strategy'))
            ->assertOk()
            ->assertSee('Strategy, shown in arena');
    }

    public function test_it_shows_the_concepts_and_says_where_the_borrowing_stops(): void
    {
        // The "not mapped" section is the honesty check on a page like this — if every famous
        // idea found an arena counterpart, the page would be retrofitting rather than observing.
        $this->get(route('strategy'))
            ->assertSee('Reconnaissance by fire')
            ->assertSee('Zugzwang')
            ->assertSee('Where the borrowing stops');
    }

    public function test_the_source_document_names_only_abilities_that_could_resolve(): void
    {
        // Every step is a real ability looked up in the committed matchup profiles. This asserts
        // the shape the resolver depends on rather than the resolution itself, which needs the
        // spell database and cannot run on an empty schema.
        $document = json_decode(file_get_contents(base_path('data/strategy/concepts.json')), true);

        $this->assertNotEmpty($document['concepts']);

        foreach ($document['concepts'] as $concept) {
            $this->assertNotEmpty($concept['sequence'], "{$concept['name']} has no sequence — the sequence is the point of the page.");

            foreach ($concept['sequence'] as $step) {
                $this->assertArrayHasKey('spec', $step);
                $this->assertArrayHasKey('spell', $step);
                $this->assertMatchesRegularExpression('#^[a-z-]+/[a-z-]+$#', $step['spec'], 'A step must name a real class/spec.');
                $this->assertFileExists(
                    base_path("data/matchup-profiles/{$step['spec']}.json"),
                    "No matchup profile for {$step['spec']}, so {$step['spell']} can never resolve."
                );
            }
        }
    }
}
