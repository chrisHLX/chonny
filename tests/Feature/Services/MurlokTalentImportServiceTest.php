<?php

use App\Http\Services\MurlokTalentImportService;
use App\Http\Services\TalentSelectionService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\Specialization;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use Illuminate\Support\Facades\Http;

/**
 * Covers a real report (2026-08-09): "hardly any spells for Evoker" traced to
 * MurlokTalentImportService failing to match Evoker's core kit at all. Root cause: Evoker
 * spell names routinely carry a legitimate "(desc=Color)" suffix as part of their raw name
 * (e.g. "Pyre (desc=Red)") — a per-dragonflight-color naming convention specific to this
 * class — but murlok's page never shows that internal annotation, so exact-name matching
 * failed for nearly the entire class. normalizeSpellName() strips it before comparing.
 */
function makeMurlokFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Evoker', 'slug' => 'evoker']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Devastation', 'slug' => 'devastation', 'external_spec_id' => 1467]);

    $pyre = Spell::create(['patch_id' => $patch->id, 'spell_id' => 357211, 'name' => 'Pyre (desc=Red)']);

    $tree = TalentTree::create(['patch_id' => $patch->id, 'spec_id' => $spec->id, 'class_id' => $class->id, 'type' => 'spec', 'name' => 'Devastation']);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 1, 'type' => 'ACTIVE']);
    TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $pyre->id, 'rank' => 1, 'max_rank' => 1]);

    return compact('spec', 'patch', 'tree', 'node', 'pyre');
}

test('normalizeSpellName strips the desc= suffix so Evoker talents match murlok\'s plain names', function () {
    $fixture = makeMurlokFixture();

    Http::fake([
        'murlok.io/*' => Http::response(
            '<div id="talents-specialization"><ul><li class="guide-talent-tree-cell"><img alt="Pyre"><div class="guide-talent-count">50/50</div></li></ul></div>'
            .'<div id="talents-class"></div><div id="talents-hero"></div><div id="talents-pvp"></div>',
            200
        ),
    ]);

    $preview = app(MurlokTalentImportService::class)->preview($fixture['spec']);

    expect($preview['choices'])->toHaveCount(1)
        ->and($preview['choices']->first()['entry']->spell_id)->toBe($fixture['pyre']->id)
        ->and($preview['unmatchedNames'])->not->toContain('Pyre (desc=Red)');
});

test('a spell that genuinely has no murlok match still lands in unmatchedNames', function () {
    $fixture = makeMurlokFixture();

    Http::fake([
        'murlok.io/*' => Http::response(
            '<div id="talents-specialization"></div><div id="talents-class"></div><div id="talents-hero"></div><div id="talents-pvp"></div>',
            200
        ),
    ]);

    $preview = app(MurlokTalentImportService::class)->preview($fixture['spec']);

    expect($preview['choices'])->toHaveCount(0)
        ->and($preview['unmatchedNames'])->toContain('Pyre (desc=Red)');
});

/**
 * Covers a real incident (2026-08-31): `wow:import-murlok-defaults --all --apply` wiped Death
 * Knight/Blood and Warrior/Protection's real ~91-choice default builds down to zero, because
 * murlok.io serves a stripped, WASM-shell page with no talent grid at all for a spec/bracket it
 * doesn't have enough real high-rated players to build a heatmap for — `preview()` can't tell
 * that apart from "real page, genuinely zero picks," and `apply()` had no check before deleting
 * existing choices. See MurlokTalentImportService::apply()'s own docblock for the full trace.
 */
function makeFullMurlokFixture(): array
{
    $fixture = makeMurlokFixture();
    $classTree = TalentTree::create(['patch_id' => $fixture['patch']->id, 'class_id' => $fixture['spec']->class_id, 'type' => 'class', 'name' => 'Evoker Class']);
    $classNode = TalentNode::create(['talent_tree_id' => $classTree->id, 'external_node_id' => 2, 'type' => 'ACTIVE']);
    $classSpell = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 900001, 'name' => 'Hover']);
    TalentNodeEntry::create(['talent_node_id' => $classNode->id, 'spell_id' => $classSpell->id, 'rank' => 1, 'max_rank' => 1]);

    return array_merge($fixture, compact('classTree', 'classNode', 'classSpell'));
}

test('apply() refuses to write when murlok resolved zero real class/spec picks, and leaves existing choices untouched', function () {
    $fixture = makeFullMurlokFixture();
    $talentService = app(TalentSelectionService::class);
    $murlok = app(MurlokTalentImportService::class);

    // A healthy prior build, standing in for the real ~91-choice builds that got wiped.
    $build = $talentService->getOrCreateDefaultBuild($fixture['spec']->id, $fixture['patch']->id);
    $talentService->saveChoice($build, $fixture['node'], $fixture['node']->entries->first());
    expect($build->choices()->count())->toBe(1);

    // Simulate murlok's stripped placeholder page — real totals, zero real matches, exactly the
    // 0/47-style shape from the incident.
    Http::fake([
        'murlok.io/*' => Http::response(
            '<div id="talents-specialization"></div><div id="talents-class"></div><div id="talents-hero"></div><div id="talents-pvp"></div>',
            200
        ),
    ]);
    $preview = $murlok->preview($fixture['spec']);
    expect($preview['classNodesSelected'])->toBe(0)->and($preview['classNodesTotal'])->toBeGreaterThan(0);

    expect(fn () => $murlok->apply($preview, $talentService))->toThrow(RuntimeException::class);

    // The real, pre-existing choice must survive the refused apply — this is the actual bug.
    expect($build->fresh()->choices()->count())->toBe(1);
});

test('apply() refuses to write when the spec has a real hero tree but murlok resolved zero hero picks', function () {
    $fixture = makeFullMurlokFixture();
    $heroTree = TalentTree::create(['patch_id' => $fixture['patch']->id, 'class_id' => $fixture['spec']->class_id, 'type' => 'hero', 'name' => 'Scalecommander']);
    $heroTree->specializations()->attach($fixture['spec']->id);
    $heroNode = TalentNode::create(['talent_tree_id' => $heroTree->id, 'external_node_id' => 3, 'type' => 'ACTIVE']);
    $heroSpell = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 900002, 'name' => 'Mass Disintegrate']);
    TalentNodeEntry::create(['talent_node_id' => $heroNode->id, 'spell_id' => $heroSpell->id, 'rank' => 1, 'max_rank' => 1]);

    $talentService = app(TalentSelectionService::class);
    $murlok = app(MurlokTalentImportService::class);

    // Class/spec resolve fine (real matches present) — the hero tree itself IS found (matched by
    // name, so heroNodesTotal > 0), but its own page section lists a talent that doesn't match
    // any of our node entries, so heroNodesSelected stays 0 — the exact "real tree, empty picks"
    // shape distinct from "no hero tree resolved at all" (heroNodesTotal === 0).
    Http::fake([
        'murlok.io/*' => Http::response(
            '<div id="talents-specialization"><ul><li class="guide-talent-tree-cell"><img alt="Pyre"><div class="guide-talent-count">50/50</div></li></ul></div>'
            .'<div id="talents-class"><ul><li class="guide-talent-tree-cell"><img alt="Hover"><div class="guide-talent-count">50/50</div></li></ul></div>'
            .'<div id="talents-hero"><div class="hero"><h3>Scalecommander Hero Talents</h3><ul><li class="guide-talent-tree-cell"><img alt="Something Unrelated"><div class="guide-talent-count">0/50</div></li></ul></div></div>'
            .'<div id="talents-pvp"></div>',
            200
        ),
    ]);
    $preview = $murlok->preview($fixture['spec']);
    expect($preview['classNodesSelected'])->toBeGreaterThan(0)
        ->and($preview['specNodesSelected'])->toBeGreaterThan(0)
        ->and($preview['heroNodesTotal'])->toBeGreaterThan(0)
        ->and($preview['heroNodesSelected'])->toBe(0);

    expect(fn () => $murlok->apply($preview, $talentService))->toThrow(RuntimeException::class);
});

test('apply() does not misfire on a spec with no hero tree at all in our own data', function () {
    // makeFullMurlokFixture() has class + spec trees but deliberately NO hero tree, so
    // heroNodesTotal is genuinely 0 in this fixture (unlike Demon Hunter/Devourer's real 0/0,
    // which turned out to be a resolveHeroTree() matching bug, not a real absence — see
    // apply()'s own docblock). A spec that truly has zero hero nodes must not be rejected.
    $fixture = makeFullMurlokFixture();
    $talentService = app(TalentSelectionService::class);
    $murlok = app(MurlokTalentImportService::class);

    Http::fake([
        'murlok.io/*' => Http::response(
            '<div id="talents-specialization"><ul><li class="guide-talent-tree-cell"><img alt="Pyre"><div class="guide-talent-count">50/50</div></li></ul></div>'
            .'<div id="talents-class"><ul><li class="guide-talent-tree-cell"><img alt="Hover"><div class="guide-talent-count">50/50</div></li></ul></div>'
            .'<div id="talents-hero"></div><div id="talents-pvp"></div>',
            200
        ),
    ]);
    $preview = $murlok->preview($fixture['spec']);
    expect($preview['heroNodesTotal'])->toBe(0);

    $build = $murlok->apply($preview, $talentService);

    expect($build->choices()->count())->toBe(2);
});

/**
 * Real bug (2026-08-31, found investigating the Devourer/Windwalker hero gaps flagged
 * alongside the apply() incident above): murlok's page headings render a hyphenated hero
 * tree's real name WITHOUT the hyphen ("Void Scarred", "Shado Pan") while our own imported
 * `talent_trees.name` keeps it ("Void-Scarred", "Shado-Pan") — resolveHeroTree()'s exact
 * string match failed on this for every affected spec, resolving heroNodesTotal to 0 even
 * though the tree and a real page match both genuinely existed.
 */
test('resolveHeroTree matches a hyphenated tree name against murlok\'s un-hyphenated page heading', function () {
    $fixture = makeFullMurlokFixture();
    $heroTree = TalentTree::create(['patch_id' => $fixture['patch']->id, 'class_id' => $fixture['spec']->class_id, 'type' => 'hero', 'name' => 'Void-Scarred']);
    $heroTree->specializations()->attach($fixture['spec']->id);
    $heroNode = TalentNode::create(['talent_tree_id' => $heroTree->id, 'external_node_id' => 3, 'type' => 'ACTIVE']);
    $heroSpell = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 900003, 'name' => 'Voidsurge']);
    TalentNodeEntry::create(['talent_node_id' => $heroNode->id, 'spell_id' => $heroSpell->id, 'rank' => 1, 'max_rank' => 1]);

    Http::fake([
        'murlok.io/*' => Http::response(
            '<div id="talents-specialization"><ul><li class="guide-talent-tree-cell"><img alt="Pyre"><div class="guide-talent-count">50/50</div></li></ul></div>'
            .'<div id="talents-class"><ul><li class="guide-talent-tree-cell"><img alt="Hover"><div class="guide-talent-count">50/50</div></li></ul></div>'
            // Deliberately NO hyphen here — reproduces murlok's real page markup exactly.
            .'<div id="talents-hero"><div class="hero"><h3>Void Scarred Hero Talents</h3><ul><li class="guide-talent-tree-cell"><img alt="Voidsurge"><div class="guide-talent-count">50/50</div></li></ul></div></div>'
            .'<div id="talents-pvp"></div>',
            200
        ),
    ]);

    $preview = app(MurlokTalentImportService::class)->preview($fixture['spec']);

    expect($preview['heroTreeName'])->toBe('Void-Scarred')
        ->and($preview['heroNodesSelected'])->toBe(1)
        ->and($preview['heroNodesTotal'])->toBe(1);
});
