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
