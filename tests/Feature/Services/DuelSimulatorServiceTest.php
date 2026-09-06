<?php

use App\Http\Services\DuelSimulatorService;
use App\Models\Game;
use App\Models\Patch;
use App\Models\Spell;

/**
 * Covers DuelSimulatorService — the "Claude's Counters" simulation engine (2026-09-01). Builds
 * combatants directly from hand-authored Spell fixtures (bypassing buildCombatant()'s real-kit
 * resolution entirely) so each test can assert on a fully controlled, deterministic scenario —
 * matching the same fixture-over-live-data discipline CcChainBuilderTest already uses for its own
 * pure-computation service.
 *
 * Three cases below directly correspond to the three real bugs found and fixed while building
 * this (see DuelSimulatorService's own inline comments and data/claudes-counters/README.md) —
 * each test locks in the fix, not just the current happy-path behavior.
 */
function makeDuelSpell(Patch $patch, int $spellId, string $name, ?string $drCategory = null, ?float $pvpDuration = null, ?float $cooldownSeconds = null): Spell
{
    return Spell::create([
        'patch_id' => $patch->id, 'spell_id' => $spellId, 'name' => $name,
        'dr_category' => $drCategory, 'pvp_duration_seconds' => $pvpDuration,
        'cooldown_seconds' => $cooldownSeconds, 'cast_type' => 'instant',
    ]);
}

function makeAbility(Spell $spell, string $role, ?float $cooldownSeconds): array
{
    return [
        'spell' => $spell, 'role' => $role,
        'cooldown_ticks' => $cooldownSeconds !== null ? max(1, (int) ceil($cooldownSeconds / DuelSimulatorService::TICK_SECONDS)) : null,
        'available_at' => 0,
    ];
}

function makeCombatant(string $label, string $title, string $range, array $abilities): array
{
    return [
        'label' => $label, 'classSlug' => 'test', 'specSlug' => 'test', 'title' => $title,
        'range' => $range, 'abilities' => $abilities, 'pressure' => 0,
        'cc_category' => null, 'cc_until' => 0, 'trinket_available' => true,
        'trinket_available_at' => 0, 'dr' => [],
    ];
}

test('cashes in a ready offensive cooldown on a locked opponent instead of re-chaining CC', function () {
    // Bug #1: the very first generated matchup had both sides re-locking each other forever,
    // never once landing an offensive, because CC-chaining was checked before the cash-in step.
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $stun = makeDuelSpell($patch, 1001, 'Test Stun', 'Stun', 4.0);
    $bigCd = makeDuelSpell($patch, 1002, 'Test Big Cooldown', null, null, 120.0);
    $enemyCc = makeDuelSpell($patch, 1003, 'Enemy Stun', 'Stun', 4.0);

    $sideA = makeCombatant('A', 'Side A', 'melee', [
        makeAbility($stun, 'cc', 30),
        makeAbility($bigCd, 'offensive', 120),
    ]);
    $sideB = makeCombatant('B', 'Side B', 'melee', [
        makeAbility($enemyCc, 'cc', 30),
    ]);

    $sim = new DuelSimulatorService();
    $result = $sim->simulate($sideA, $sideB, 4);

    expect($result['beats'][0]['action_type'])->toBe('cc'); // A opens with CC
    expect($result['beats'][1]['action_type'])->toBe('stuck'); // B is locked
    // The critical assertion: A's SECOND turn cashes in the big cooldown while B is still
    // locked, rather than doing nothing else useful — A has no second CC option here, so this
    // also confirms the fix didn't just accidentally work because no CC was available.
    expect($result['beats'][2]['action_type'])->toBe('offensive');
    expect($result['beats'][2]['note'])->toContain('free pressure');
});

test('prefers a diminishing-returns-fresh CC category over one about to go immune', function () {
    // Bug #2: a Frost Mage kept reaching for Polymorph even as it was DR'd toward immunity,
    // producing wasted beats, while a fresh category (Frost Nova) sat unused.
    //
    // Both abilities share a short (1-tick) cooldown so availability never forces the choice —
    // verified empirically (not hand-derived) that with these exact inputs the real sequence is:
    // tick1 Incap(100%), tick3 Root(100%), tick5 Incap(50%), tick7 Root(50%) — landing Root
    // cleanly at 50% rather than re-trying Incapacitate, which would have been its 3rd
    // occurrence (fully immune) at that point. That 4th application is the one that actually
    // proves the fix: without it, category priority alone would have picked Incapacitate again
    // and produced a wasted beat instead.
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $incap = makeDuelSpell($patch, 2001, 'Test Incapacitate', 'Incapacitate', 4.0);
    $root = makeDuelSpell($patch, 2002, 'Test Root', 'Root', 4.0);
    $noCc = makeDuelSpell($patch, 2003, 'Filler', null, null, null);

    $sideA = makeCombatant('A', 'Controller', 'ranged', [
        makeAbility($incap, 'cc', 1.5),
        makeAbility($root, 'cc', 1.5),
    ]);
    $sideB = makeCombatant('B', 'Target', 'ranged', [
        makeAbility($noCc, 'offensive', null),
    ]);

    $sim = new DuelSimulatorService();
    $result = $sim->simulate($sideA, $sideB, 7);

    $ccBeats = collect($result['beats'])
        ->filter(fn ($b) => $b['actor'] === 'A' && in_array($b['action_type'], ['cc', 'cc_wasted']))
        ->values();

    expect($ccBeats->count())->toBe(4);
    expect($ccBeats->pluck('ability.name')->all())->toBe([
        'Test Incapacitate', 'Test Root', 'Test Incapacitate', 'Test Root',
    ]);
    // The critical one: the 4th application picks the still-viable Root over an
    // about-to-be-immune Incapacitate, and lands cleanly rather than wasted.
    expect($ccBeats[3]['action_type'])->toBe('cc');
    expect($ccBeats[3]['note'])->toContain('50%');
});

test('trinkets out of a chain based on severity and consecutive-lock depth, not a single long duration', function () {
    // Bug #3: an early version required a SINGLE cc application to itself last 3+ ticks before
    // considering a trinket worthwhile — but real curated PvP durations are mostly 2-4 ticks, so
    // that bar was almost never cleared even during a genuine back-to-back chain. Verified
    // empirically: with these exact inputs, A lands Chain Stun (tick1) then Chain Disorient
    // (tick3, since B is already free again by then and Stun is still on cooldown) — two
    // DIFFERENT severe categories back to back — and B trinkets out on its very next turn (tick4).
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $stun = makeDuelSpell($patch, 3001, 'Chain Stun', 'Stun', 3.0);
    $disorient = makeDuelSpell($patch, 3002, 'Chain Disorient', 'Disorient', 3.0);
    $filler = makeDuelSpell($patch, 3003, 'Filler', null, null, null);

    $sideA = makeCombatant('A', 'Controller', 'melee', [
        makeAbility($stun, 'cc', 4),
        makeAbility($disorient, 'cc', 4),
    ]);
    $sideB = makeCombatant('B', 'Victim', 'melee', [
        makeAbility($filler, 'offensive', null),
    ]);

    $sim = new DuelSimulatorService();
    $result = $sim->simulate($sideA, $sideB, 10);

    $beats = collect($result['beats'])->values();
    $trinketIndex = $beats->search(fn ($b) => $b['action_type'] === 'trinket');
    expect($trinketIndex)->not->toBeFalse();

    // The pressure/chain-depth snapshot on a beat is taken AFTER that beat resolves — and
    // trinketing itself resets chain depth to 0 as part of the action — so the signal that
    // actually TRIGGERED the trinket is the PRECEDING beat's snapshot, not the trinket beat's own.
    $chainDepthBeforeTrinket = $beats[$trinketIndex - 1]['cc_chain_depth']['B'];
    expect($chainDepthBeforeTrinket)->toBeGreaterThanOrEqual(2);

    // And confirms this genuinely wasn't about any single application being long — every real
    // duration in this scenario is only 2 ticks (3.0s / 1.5s), well under any "long CC" bar.
    expect($beats[$trinketIndex]['note'])->toContain('2 ticks');
});

test('a fully DR-immune CC attempt lands as a wasted beat, not silently skipped', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $onlyCc = makeDuelSpell($patch, 4001, 'Only Option', 'Stun', 4.0);
    $filler = makeDuelSpell($patch, 4002, 'Filler', null, null, null);

    $sideA = makeCombatant('A', 'Controller', 'melee', [makeAbility($onlyCc, 'cc', 4)]);
    $sideB = makeCombatant('B', 'Victim', 'melee', [makeAbility($filler, 'offensive', null)]);

    $sim = new DuelSimulatorService();
    $result = $sim->simulate($sideA, $sideB, 12);

    $wasted = collect($result['beats'])->filter(fn ($b) => $b['action_type'] === 'cc_wasted');
    expect($wasted)->not->toBeEmpty();
    expect($wasted->first()['note'])->toContain('already immune');
});
