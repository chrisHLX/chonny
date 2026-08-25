<?php

use App\Http\Services\CcFormulaService;
use App\Models\GameClass;
use App\Models\Game;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;

/**
 * Covers buildChainFromComp()'s isSelected filter, fixed 2026-08-25 — before this fix, every
 * real talent-tree/PvP-talent entry for a spec fed into the CC-chain pool regardless of whether
 * the build actually had it selected (unlike getSynergiesProperty(), which already checked
 * isSelected). Real reported symptom: a Death Knight comp's "Example CC Chains" showed both
 * Asphyxiate and Strangulate even when the build's own selections meant only one was actually
 * available — see TalentSelectionServiceTest's PVP_TALENT_REPLACES coverage for the companion
 * fix that makes selectedSpellIds() (and therefore isSelected) correct for that specific pair.
 */
function makeCcFormulaMember(string $className, string $specName, array $entries): array
{
    $game = Game::query()->firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
    Patch::query()->firstOrCreate(['game_id' => $game->id, 'is_current' => true], ['build_version' => '12.0.0']);

    $class = GameClass::create(['game_id' => $game->id, 'name' => $className, 'slug' => Str::slug($className)]);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => $specName, 'slug' => Str::slug($specName)]);

    return ['label' => 'DPS', 'class' => $class, 'spec' => $spec, 'entries' => $entries];
}

function makeCcFormulaEntry(Spell $spell, bool $isSelected): array
{
    return [
        'spell' => $spell,
        'isSelected' => $isSelected,
        'cooldown' => ['seconds' => $spell->cooldown_seconds],
        'charges' => ['charges' => null],
    ];
}

test('buildChainFromComp excludes an entry whose isSelected is false from both pools', function () {
    $game = Game::query()->first() ?? Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::where('is_current', true)->first() ?? Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $asphyxiate = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 221562, 'name' => 'Asphyxiate',
        'dr_category' => 'Stun', 'cast_type' => 'instant', 'cooldown_seconds' => 45,
    ]);
    $strangulate = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 47476, 'name' => 'Strangulate',
        'dr_category' => 'Silence', 'cast_type' => 'instant', 'cooldown_seconds' => 45,
    ]);

    // Asphyxiate NOT selected (as if Strangulate replaced it), Strangulate selected.
    $member1 = makeCcFormulaMember('Death Knight', 'Unholy', [
        makeCcFormulaEntry($asphyxiate, false),
        makeCcFormulaEntry($strangulate, true),
    ]);
    $member2 = makeCcFormulaMember('Test Class B', 'Test Spec B', []);
    $member3 = makeCcFormulaMember('Test Class C', 'Test Spec C', []);

    $service = app(CcFormulaService::class);
    $result = $service->buildChainFromComp([$member1, $member2, $member3], useRealData: false);

    $allSpellNames = collect($result['primary']['sequence'])->pluck('spell.name')
        ->merge(collect($result['primary']['leftover'])->pluck('spell.name'))
        ->merge($result['primary']['killTarget'] ? [$result['primary']['killTarget']['spell']->name] : []);

    expect($allSpellNames->contains('Asphyxiate'))->toBeFalse()
        ->and($allSpellNames->contains('Strangulate'))->toBeTrue();
});

test('buildChainFromComp includes both entries when both are genuinely selected', function () {
    $game = Game::query()->first() ?? Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::where('is_current', true)->first() ?? Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $stun = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9601, 'name' => 'Test Stun',
        'dr_category' => 'Stun', 'cast_type' => 'instant', 'cooldown_seconds' => 30,
    ]);
    $silence = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9602, 'name' => 'Test Silence',
        'dr_category' => 'Silence', 'cast_type' => 'instant', 'cooldown_seconds' => 30,
    ]);

    $member1 = makeCcFormulaMember('Test Class D', 'Test Spec D', [
        makeCcFormulaEntry($stun, true),
        makeCcFormulaEntry($silence, true),
    ]);
    $member2 = makeCcFormulaMember('Test Class E', 'Test Spec E', []);
    $member3 = makeCcFormulaMember('Test Class F', 'Test Spec F', []);

    $service = app(CcFormulaService::class);
    $result = $service->buildChainFromComp([$member1, $member2, $member3], useRealData: false);

    $allSpellNames = collect($result['primary']['sequence'])->pluck('spell.name')
        ->merge(collect($result['primary']['leftover'])->pluck('spell.name'))
        ->merge($result['primary']['killTarget'] ? [$result['primary']['killTarget']['spell']->name] : []);

    expect($allSpellNames->contains('Test Stun'))->toBeTrue()
        ->and($allSpellNames->contains('Test Silence'))->toBeTrue();
});
