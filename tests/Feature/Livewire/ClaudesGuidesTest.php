<?php

use App\Http\Services\TalentSelectionService;
use App\Livewire\ClaudesGuides;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * Covers ClaudesGuides — the isolated, experimental "Claude's Guides" page (2026-08-31/09-01).
 * Writes a synthetic guide JSON under data/claudes-guides/{testClassSlug}/{testSpecSlug}.json
 * (real path, no injectable override exists on this component by design — everything for this
 * page is meant to live under that one real folder) and deletes it afterward so the real,
 * shipped guide content isn't disturbed by the test run.
 */
function claudesGuideTestPath(string $classSlug, string $specSlug): string
{
    return base_path("data/claudes-guides/{$classSlug}/{$specSlug}.json");
}

afterEach(function () {
    $path = claudesGuideTestPath('test-guide-class', 'test-guide-spec');
    if (File::exists($path)) {
        File::delete($path);
        @rmdir(dirname($path));
    }
});

test('renders a guide, resolves its spellGrid entries via SpecKitComputer, and shows the disclaimer', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Test Guide Class', 'slug' => 'test-guide-class']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Test Guide Spec', 'slug' => 'test-guide-spec']);

    $spell = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 555001, 'name' => 'Test Guide Cooldown',
        'cast_type' => 'instant', 'cooldown_seconds' => 45,
    ]);

    $tree = TalentTree::create(['patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Test Guide Spec', 'external_tree_id' => $spec->id]);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => $spec->id, 'type' => 'ACTIVE', 'max_ranks' => 1]);
    $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 1, 'max_rank' => 1]);

    $service = new TalentSelectionService();
    $build = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    $service->saveChoice($build, $node, $entry);
    $service->setDefault($build);

    $guide = [
        'classSlug' => 'test-guide-class',
        'specSlug' => 'test-guide-spec',
        'title' => 'Test Guide Class Guide',
        'subtitle' => 'A synthetic test guide.',
        'dataSources' => ['playstyleSample' => 3, 'ratingRange' => [2000, 2100], 'patch' => '12.0.0'],
        'sections' => [
            ['type' => 'prose', 'heading' => 'Identity', 'paragraphs' => ['Test paragraph one.']],
            ['type' => 'spellGrid', 'heading' => 'Offensive Cooldowns', 'intro' => 'Test intro.', 'spellIds' => [555001]],
        ],
        'disclaimer' => 'This is a synthetic test disclaimer, not real guide content.',
    ];

    $path = claudesGuideTestPath('test-guide-class', 'test-guide-spec');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode($guide));

    $component = Livewire::test(ClaudesGuides::class, ['classSlug' => 'test-guide-class', 'specSlug' => 'test-guide-spec']);

    $html = $component->html();

    expect($html)->toContain('Test Guide Class Guide')
        ->and($html)->toContain('Test paragraph one.')
        ->and($html)->toContain('Test Guide Cooldown')
        ->and($html)->toContain('synthetic test disclaimer');

    // The spellGrid entry resolved through the real SpecKitComputer/talent-build machinery —
    // its cooldown (45s, unmodified — no relationship rows exist for this synthetic spell) is
    // genuinely present, not just the spell's name.
    expect($html)->toContain('45');
});

test('picker only lists guides that resolve to a real class/spec', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    // A guide file whose classSlug/specSlug don't resolve to anything real must be silently
    // skipped from the picker, same "don't show something broken" precedent as
    // WowComps::getPresetsProperty().
    $path = claudesGuideTestPath('test-guide-class', 'test-guide-spec');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode([
        'classSlug' => 'test-guide-class', 'specSlug' => 'test-guide-spec', 'title' => 'Orphan Guide',
        'sections' => [], 'disclaimer' => '',
    ]));

    $component = Livewire::test(ClaudesGuides::class);
    $guides = $component->instance()->availableGuides;

    expect(collect($guides)->pluck('title'))->not->toContain('Orphan Guide');
});

test('renders the real burst-window sequence when the guide requests one and a rotation file exists', function () {
    if (!File::exists(base_path('data/arena-logs/rotations/rogue/assassination.json'))) {
        $this->markTestSkipped('No rogue/assassination rotation file on disk for this environment.');
    }

    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    Patch::create(['game_id' => $game->id, 'build_version' => '12.0.7.68453', 'is_current' => true]);

    // getRealBurstWindowProperty() resolves the real rogue/assassination class+spec by slug —
    // RefreshDatabase gives an empty schema, so this minimal test DB needs its own rows with
    // the real slugs for that lookup to succeed (real spell resolution inside
    // resolveWindowSteps() is expected to legitimately miss here — same "no seeded spell rows
    // outside import:spelldata" rule TopCcChainsTest already documents).
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    Specialization::create(['class_id' => $class->id, 'name' => 'Assassination', 'slug' => 'assassination']);

    $component = Livewire::test(ClaudesGuides::class, ['classSlug' => 'rogue', 'specSlug' => 'assassination']);
    $window = $component->instance()->realBurstWindow;

    expect($window)->not->toBeNull()
        ->and($window['steps'])->not->toBeEmpty();

    // Every step is either resolved to a real Spell or flagged unresolved — never silently
    // dropped, matching ArenaLogService::resolveWindowSteps()'s own documented contract.
    foreach ($window['steps'] as $step) {
        expect($step)->toHaveKey('displayName');
    }
});
