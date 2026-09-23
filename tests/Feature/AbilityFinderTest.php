<?php

namespace Tests\Feature;

use App\Http\Services\AbilityFinder;
use Tests\TestCase;

/**
 * The drafting-aid ability finder.
 *
 * These read the committed matchup profiles, which are real data in the repo, so they run
 * anywhere. They do NOT need the spell database: with no schema the property join comes back
 * empty and the profile half still works, which is what the first test relies on.
 */
class AbilityFinderTest extends TestCase
{
    public function test_it_only_offers_filters_that_read_a_curated_field(): void
    {
        // The whole design rule. Every filter names the field it reads, so a property with no
        // field cannot quietly become a text search — which returned ~60% false positives when
        // it was tried, because "causes a knockback" and "immune to knockbacks" share a word.
        foreach (AbilityFinder::FILTERS as $filter => $field) {
            $this->assertNotEmpty($field, "Filter --{$filter} does not say which field it reads.");
        }

        // And the gaps are named rather than omitted, so asking gets an honest answer.
        $this->assertArrayHasKey('dispel', AbilityFinder::UNSUPPORTED);
        $this->assertArrayHasKey('targets', AbilityFinder::UNSUPPORTED);
    }

    public function test_it_names_the_gaps_it_cannot_answer(): void
    {
        $finder = app(AbilityFinder::class);

        $hits = $finder->unsupported(['dispel', 'freedom', 'dr']);

        $this->assertArrayHasKey('dispel', $hits);
        $this->assertArrayHasKey('freedom', $hits);
        $this->assertArrayNotHasKey('dr', $hits, 'dr IS backed by a field and must not be reported as a gap.');
    }

    public function test_it_excludes_abilities_no_spec_actually_takes(): void
    {
        // Mighty Ox Kick is a curated Knockback in the spell table and the project's own player
        // has never seen it taken or used. Reading the profiles rather than `spells` drops it
        // for free, which is the reason this class does not query the table directly.
        $names = array_column(app(AbilityFinder::class)->all(), 'name');

        $this->assertNotEmpty($names, 'No profiles found — data/matchup-profiles is missing.');
        $this->assertNotContains('Mighty Ox Kick', $names);
    }

    public function test_every_ability_is_attributed_to_at_least_one_spec(): void
    {
        // A candidate nobody can press is not a candidate. This is the property the raw spells
        // table cannot provide at all.
        foreach (app(AbilityFinder::class)->all() as $row) {
            $this->assertNotEmpty($row['specs'], "{$row['name']} has no spec that can press it.");
        }
    }

    public function test_knockback_returns_the_displacement_tools_and_nothing_else(): void
    {
        $rows = app(AbilityFinder::class)->find(['dr' => 'Knockback']);
        $names = array_column($rows, 'name');

        $this->assertContains('Typhoon', $names);
        $this->assertContains("Ursol's Vortex", $names);

        // The four that a description search wrongly returned, because each is IMMUNE to
        // knockback rather than causing one. None may appear here.
        foreach (['Divine Shield', 'Tranquility', 'Ultimate Penitence', "Death's Advance"] as $immunity) {
            $this->assertNotContains($immunity, $names, "{$immunity} is immune to knockback, not a source of one.");
        }

        // And the homonym: "knocks down" in Leg Sweep's text is a stun.
        $this->assertNotContains('Leg Sweep', $names);
    }

    public function test_filters_compose(): void
    {
        $finder = app(AbilityFinder::class);

        $all = $finder->find(['dr' => 'Stun']);
        $cheap = $finder->find(['dr' => 'Stun', 'max-cd' => 30]);

        $this->assertNotEmpty($cheap);
        $this->assertLessThanOrEqual(count($all), count($cheap));

        foreach ($cheap as $row) {
            $this->assertNotNull($row['cooldown']);
            $this->assertLessThanOrEqual(30, $row['cooldown']);
        }
    }
}
