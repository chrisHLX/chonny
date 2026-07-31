<?php

use App\Http\Services\BlizzardTalentStringCodec;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;

/**
 * The bit-packing algorithm under test is a literal port of Blizzard's own shipped client Lua
 * (Blizzard_ClassTalentImportExport.lua + ExportUtil.lua, Gethe/wow-ui-source) — see
 * BlizzardTalentStringCodec's class docblock. The header test below decodes a real string
 * copied from the in-game Discipline Priest talent UI; spec ID 256 is Blizzard's well-known,
 * stable numeric ID for Discipline Priest, so a correct decode has an externally checkable
 * right answer, not just internal self-consistency.
 */
test('decodeHeader reads version and spec id from a real discipline priest export string', function () {
    $string = 'CAQA4VPTJ8eQb8/qEm8PyGu4yADsMGWmZMzYGmZbGzMzgZGAAAAAAAAAAzMz2MYmZGLzYmhFmmJGYmZWwQYMLjlZjxYsYAAYMDjBDMzMzMTwA';

    $header = (new BlizzardTalentStringCodec())->decodeHeader($string);

    expect($header['version'])->toBe(2)
        ->and($header['specId'])->toBe(256);
});

test('decodeHeader throws on a string too short to contain a header', function () {
    expect(fn () => (new BlizzardTalentStringCodec())->decodeHeader('AA'))
        ->toThrow(InvalidArgumentException::class);
});

function makeTalentTreeFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '11.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $spec = Specialization::create([
        'class_id' => $class->id, 'name' => 'Discipline', 'slug' => 'discipline', 'external_spec_id' => 256,
    ]);
    $tree = TalentTree::create([
        'patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id,
        'type' => 'spec', 'name' => 'Discipline', 'external_tree_id' => 1,
    ]);

    return compact('game', 'patch', 'class', 'spec', 'tree');
}

test('decode round-trips a synthetic loadout through encode and back to the exact same entries', function () {
    $fixture = makeTalentTreeFixture();
    $codec = new BlizzardTalentStringCodec();

    // Node 1: simple single-entry node, selected.
    $simpleSpell = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 1001, 'name' => 'Simple Talent']);
    $simpleNode = TalentNode::create(['talent_tree_id' => $fixture['tree']->id, 'external_node_id' => 1, 'type' => 'PASSIVE', 'max_ranks' => 1]);
    $simpleEntry = TalentNodeEntry::create(['talent_node_id' => $simpleNode->id, 'spell_id' => $simpleSpell->id, 'rank' => 1, 'max_rank' => 1]);

    // Node 2: 2-rank node, partially ranked at rank 1 of 2 (ranksPurchased=1 means "currently at
    // rank 1", not "2 ranks spent" — per the format spec, the value is the active rank number).
    $rankedSpell = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 1002, 'name' => 'Ranked Talent']);
    $rankedNode = TalentNode::create(['talent_tree_id' => $fixture['tree']->id, 'external_node_id' => 2, 'type' => 'PASSIVE', 'max_ranks' => 2]);
    $rankedEntryRank1 = TalentNodeEntry::create(['talent_node_id' => $rankedNode->id, 'spell_id' => $rankedSpell->id, 'rank' => 1, 'max_rank' => 2]);
    TalentNodeEntry::create(['talent_node_id' => $rankedNode->id, 'spell_id' => $rankedSpell->id, 'rank' => 2, 'max_rank' => 2]);

    // Node 3: choice node, 2 options, second option chosen, selected.
    $choiceSpellA = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 1003, 'name' => 'Choice A']);
    $choiceSpellB = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 1004, 'name' => 'Choice B']);
    $choiceNode = TalentNode::create(['talent_tree_id' => $fixture['tree']->id, 'external_node_id' => 3, 'type' => 'CHOICE', 'max_ranks' => 1]);
    TalentNodeEntry::create(['talent_node_id' => $choiceNode->id, 'spell_id' => $choiceSpellA->id, 'rank' => 1, 'max_rank' => 1]);
    $choiceEntryB = TalentNodeEntry::create(['talent_node_id' => $choiceNode->id, 'spell_id' => $choiceSpellB->id, 'rank' => 1, 'max_rank' => 1]);

    // Node 4: unselected — should contribute only a single 0 bit and resolve to nothing.
    TalentNode::create(['talent_tree_id' => $fixture['tree']->id, 'external_node_id' => 4, 'type' => 'PASSIVE', 'max_ranks' => 1]);

    $entries = [
        ['bitWidth' => 8, 'value' => 2],   // version
        ['bitWidth' => 16, 'value' => 256], // spec id
    ];
    for ($i = 0; $i < 16; $i++) {
        $entries[] = ['bitWidth' => 8, 'value' => 0]; // zero-filled tree hash
    }

    // Node 1: selected, purchased, not partially ranked, not choice.
    $entries[] = ['bitWidth' => 1, 'value' => 1];
    $entries[] = ['bitWidth' => 1, 'value' => 1];
    $entries[] = ['bitWidth' => 1, 'value' => 0];
    $entries[] = ['bitWidth' => 1, 'value' => 0];

    // Node 2: selected, purchased, partially ranked (1), not choice.
    $entries[] = ['bitWidth' => 1, 'value' => 1];
    $entries[] = ['bitWidth' => 1, 'value' => 1];
    $entries[] = ['bitWidth' => 1, 'value' => 1];
    $entries[] = ['bitWidth' => 6, 'value' => 1];
    $entries[] = ['bitWidth' => 1, 'value' => 0];

    // Node 3: selected, purchased, not partially ranked, choice index 1 (second option).
    $entries[] = ['bitWidth' => 1, 'value' => 1];
    $entries[] = ['bitWidth' => 1, 'value' => 1];
    $entries[] = ['bitWidth' => 1, 'value' => 0];
    $entries[] = ['bitWidth' => 1, 'value' => 1];
    $entries[] = ['bitWidth' => 2, 'value' => 1];

    // Node 4: not selected.
    $entries[] = ['bitWidth' => 1, 'value' => 0];

    $string = $codec->encode($entries);

    $result = $codec->decode($string, $fixture['spec']->fresh(), $fixture['patch']->id);

    expect($result['warnings'])->toBeEmpty()
        ->and($result['selections'])->toHaveCount(3);

    $resolvedEntryIds = $result['selections']->pluck('entry.id')->sort()->values()->all();
    expect($resolvedEntryIds)->toBe(collect([$simpleEntry->id, $rankedEntryRank1->id, $choiceEntryB->id])->sort()->values()->all());
});

test('decode throws when the spec id does not match the target specialization', function () {
    $fixture = makeTalentTreeFixture();
    $codec = new BlizzardTalentStringCodec();

    $entries = [
        ['bitWidth' => 8, 'value' => 2],
        ['bitWidth' => 16, 'value' => 999], // wrong spec id
    ];
    for ($i = 0; $i < 16; $i++) {
        $entries[] = ['bitWidth' => 8, 'value' => 0];
    }

    $string = $codec->encode($entries);

    expect(fn () => $codec->decode($string, $fixture['spec']->fresh(), $fixture['patch']->id))
        ->toThrow(InvalidArgumentException::class);
});

test('decode produces a warning rather than an error when the string is truncated mid-content', function () {
    $fixture = makeTalentTreeFixture();
    $codec = new BlizzardTalentStringCodec();

    // Header+hash is 152 bits — not a multiple of 6, so base64 padding leaves up to 5 spare
    // zero bits after it (an inherent property of the format, not a bug: Blizzard's own encoder
    // has the same trailing padding). A single node would just read one of those harmless
    // padding zeros as "unselected" rather than genuinely running out — several nodes are needed
    // to actually exhaust the stream and prove the "ran out mid-read" path.
    for ($i = 1; $i <= 6; $i++) {
        TalentNode::create(['talent_tree_id' => $fixture['tree']->id, 'external_node_id' => $i, 'type' => 'PASSIVE', 'max_ranks' => 1]);
    }

    $entries = [
        ['bitWidth' => 8, 'value' => 2],
        ['bitWidth' => 16, 'value' => 256],
    ];
    for ($i = 0; $i < 16; $i++) {
        $entries[] = ['bitWidth' => 8, 'value' => 0];
    }
    // No content bits at all — stream ends exactly at the header/hash boundary (plus incidental padding).

    $string = $codec->encode($entries);

    $result = $codec->decode($string, $fixture['spec']->fresh(), $fixture['patch']->id);

    expect($result['selections'])->toBeEmpty()
        ->and($result['warnings'])->not->toBeEmpty();
});
