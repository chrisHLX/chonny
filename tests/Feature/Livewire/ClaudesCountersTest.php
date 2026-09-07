<?php

use App\Http\Services\SpellCounterIndexer;
use App\Livewire\ClaudesCounters;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellCounter;
use App\Models\SpellEffect;
use Livewire\Livewire;

/**
 * Covers the counters pipeline end to end.
 *
 * Every correctness rule these tests encode was developed against the 2026-09-04 version of
 * ClaudesCounters, which derived counters live on each page load. As of 2026-09-06 that derivation
 * lives in SpellCounterIndexer and is materialized into spell_counters; the rules are unchanged,
 * so each scenario below is preserved verbatim in intent and simply asserts against the stored
 * index instead of the component's own former computation.
 *
 * The counter fixtures all carry a real cooldown deliberately. SpellCounterIndexer::
 * narrowToPressable() requires a cooldown, charges, or a real talent/PvP/verified-override link
 * before treating something as a counter at all — without that, a real Kidney Shot query returned
 * 401 "counters" including Weakened Soul, Echo of Light and a literal "GGO - Test - Void Blink".
 * A fixture with no cooldown is therefore not a counter, which the dedicated test at the bottom
 * asserts directly rather than leaving implicit.
 */
function makeCounterCcSpell(Patch $patch, int $spellId, string $name, string $drCategory, ?string $school = 'Physical', bool $bypassesActiveDefense = false): Spell
{
    return Spell::create([
        'patch_id' => $patch->id, 'spell_id' => $spellId, 'name' => $name,
        'dr_category' => $drCategory, 'school' => $school, 'is_passive' => false,
        // A real cooldown is required as of 2026-09-07: SpellCounterIndexer::rebuild() now runs
        // narrowToPressable() over the COUNTERED pool as well as the counter pools, so a CC
        // fixture with no cooldown and no kit link is correctly treated as a non-pressable aura
        // copy and excluded. Every real pressable CC in production has one.
        'cooldown_seconds' => 30,
        'bypasses_active_defense' => $bypassesActiveDefense,
    ]);
}

function attachToClass(Spell $spell, GameClass $class, ?Specialization $spec = null, string $source = 'talent'): void
{
    SpellClassAvailability::create([
        'spell_id' => $spell->id, 'class_id' => $class->id, 'spec_id' => $spec?->id, 'source' => $source,
    ]);
}

/** A pressable counter ability: real cooldown, not passive, visible. */
function makeCounterAbility(Patch $patch, int $spellId, string $name, array $extra = []): Spell
{
    return Spell::create(array_merge([
        'patch_id' => $patch->id, 'spell_id' => $spellId, 'name' => $name,
        'cooldown_seconds' => 120, 'is_passive' => false, 'not_in_spellbook' => false,
    ], $extra));
}

/** Materializes spell_counters, exactly as import:spelldata / wow:rebuild-spell-counters does. */
function rebuildCounterIndex(): void
{
    app(SpellCounterIndexer::class)->rebuild();
}

/** @return \Illuminate\Support\Collection<int, string> counter names in one mechanism bucket */
function counterNames(array $row, string $mechanism)
{
    return ($row['buckets'][$mechanism] ?? collect())->map(fn (SpellCounter $c) => $c->counterSpell->name);
}

function counterFixtureWorld(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $classA = GameClass::create(['game_id' => $game->id, 'name' => 'Class A', 'slug' => 'class-a']);
    $classB = GameClass::create(['game_id' => $game->id, 'name' => 'Class B', 'slug' => 'class-b']);

    return [$game, $patch, $classA, $classB];
}

test('a Stun with a usable-while-stunned ability elsewhere shows it as a counter', function () {
    [, $patch, $classA, $classB] = counterFixtureWorld();

    $stun = makeCounterCcSpell($patch, 9101, 'Test Stun', 'Stun');
    attachToClass($stun, $classA);

    $counterSpell = makeCounterAbility($patch, 9102, 'Usable While Stunned', ['usable_while_cc' => 'stun,flee']);
    attachToClass($counterSpell, $classB);

    rebuildCounterIndex();

    $row = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass['Class A']
        ->firstWhere('spell.name', 'Test Stun');

    expect($row['hasAnyCounter'])->toBeTrue()
        ->and(counterNames($row, SpellCounter::MECHANISM_USABLE_WHILE))->toContain('Usable While Stunned');
});

test('a Stun with an immunity-granting cooldown elsewhere shows it as a counter', function () {
    [, $patch, $classA, $classB] = counterFixtureWorld();

    $stun = makeCounterCcSpell($patch, 9201, 'Test Stun', 'Stun');
    attachToClass($stun, $classA);

    $immunitySpell = makeCounterAbility($patch, 9202, 'Stun Ward');
    attachToClass($immunitySpell, $classB);
    // 12 = Stun, per ModuleSpellReferenceService::MECHANIC_IMMUNITY_CODE_MAP
    SpellEffect::create(['spell_id' => $immunitySpell->id, 'effect_index' => 1, 'type' => 'Mechanic Immunity', 'misc_value' => 12]);

    rebuildCounterIndex();

    $row = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass['Class A']
        ->firstWhere('spell.name', 'Test Stun');

    expect($row['hasAnyCounter'])->toBeTrue()
        ->and(counterNames($row, SpellCounter::MECHANISM_IMMUNITY_MECHANIC))->toContain('Stun Ward');
});

test('a Physical CC is countered by a School Immunity effect covering All or Physical, but not one covering only Shadow', function () {
    // Real gap found and closed 2026-09-04: Cloak of Shadows/Divine Shield/Blessing of Protection
    // all use School Immunity (a completely different effect type from Mechanic Immunity above),
    // and none showed up as a counter to a Physical-school Stun like Kidney Shot because
    // spell_effects.affected_schools was never captured at all.
    [, $patch, $classA, $classB] = counterFixtureWorld();

    $stun = makeCounterCcSpell($patch, 9251, 'Physical Stun', 'Stun', school: 'Physical');
    attachToClass($stun, $classA);

    $allSchoolImmune = makeCounterAbility($patch, 9252, 'Cloak Clone');
    attachToClass($allSchoolImmune, $classB);
    SpellEffect::create(['spell_id' => $allSchoolImmune->id, 'effect_index' => 1, 'type' => 'School Immunity', 'affected_schools' => 'All']);

    $physicalOnlyImmune = makeCounterAbility($patch, 9253, 'BoP Clone');
    attachToClass($physicalOnlyImmune, $classB);
    SpellEffect::create(['spell_id' => $physicalOnlyImmune->id, 'effect_index' => 1, 'type' => 'School Immunity', 'affected_schools' => 'Physical']);

    $shadowOnlyImmune = makeCounterAbility($patch, 9254, 'Shadow Ward Clone');
    attachToClass($shadowOnlyImmune, $classB);
    SpellEffect::create(['spell_id' => $shadowOnlyImmune->id, 'effect_index' => 1, 'type' => 'School Immunity', 'affected_schools' => 'Arcane, Fire, Frost, Holy, Nature, Shadow']);

    rebuildCounterIndex();

    $row = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass['Class A']
        ->firstWhere('spell.name', 'Physical Stun');

    expect($row['hasAnyCounter'])->toBeTrue()
        ->and(counterNames($row, SpellCounter::MECHANISM_IMMUNITY_SCHOOL))->toContain('Cloak Clone', 'BoP Clone')
        ->and(counterNames($row, SpellCounter::MECHANISM_IMMUNITY_SCHOOL))->not->toContain('Shadow Ward Clone');
});

test('a dodgeable Physical-school Stun shows a real dodge/parry buff as a counter', function () {
    [, $patch, $classA, $classB] = counterFixtureWorld();

    $stun = makeCounterCcSpell($patch, 9301, 'Dodgeable Stun', 'Stun', school: 'Physical', bypassesActiveDefense: false);
    attachToClass($stun, $classA);

    $dodgeSpell = makeCounterAbility($patch, 9302, 'Evasion Clone');
    attachToClass($dodgeSpell, $classB);
    SpellEffect::create(['spell_id' => $dodgeSpell->id, 'effect_index' => 1, 'type' => 'Modify Dodge%', 'base_value' => 200]);

    rebuildCounterIndex();

    $row = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass['Class A']
        ->firstWhere('spell.name', 'Dodgeable Stun');

    expect($row['hasAnyCounter'])->toBeTrue()
        ->and(counterNames($row, SpellCounter::MECHANISM_DODGE_PARRY))->toContain('Evasion Clone');
});

test('a magic-school CC never gets a dodge/parry counter, even when a dodge buff exists elsewhere', function () {
    // Real bug found and fixed 2026-09-04: Evasion was showing as a counter to Fear/Howl of
    // Terror (both School: Shadow) purely because bypasses_active_defense was false on them too
    // — true, but meaningless, since dodge/parry/block is a physical-combat-only mechanic that a
    // magic-school cast never enters regardless of that flag.
    [, $patch, $classA, $classB] = counterFixtureWorld();

    $fear = makeCounterCcSpell($patch, 9401, 'Test Fear', 'Disorient', school: 'Shadow', bypassesActiveDefense: false);
    attachToClass($fear, $classA);

    $dodgeSpell = makeCounterAbility($patch, 9402, 'Evasion Clone');
    attachToClass($dodgeSpell, $classB);
    SpellEffect::create(['spell_id' => $dodgeSpell->id, 'effect_index' => 1, 'type' => 'Modify Dodge%', 'base_value' => 200]);

    rebuildCounterIndex();

    $row = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass['Class A']
        ->firstWhere('spell.name', 'Test Fear');

    expect(counterNames($row, SpellCounter::MECHANISM_DODGE_PARRY))->toBeEmpty()
        ->and($row['hasAnyCounter'])->toBeFalse();
});

test('a CC type with no verified dr_category mapping (Root) shows no known counter, never a guess', function () {
    [, $patch, $classA, $classB] = counterFixtureWorld();

    $root = makeCounterCcSpell($patch, 9501, 'Test Root', 'Root');
    attachToClass($root, $classA);

    // Even a spell explicitly usable-while-stunned (a token that has no Root equivalent at all)
    // must not surface — there is no verified DR_CATEGORY_TO_CC_TOKEN entry for Root.
    $unrelated = makeCounterAbility($patch, 9502, 'Unrelated', ['usable_while_cc' => 'stun']);
    attachToClass($unrelated, $classB);

    rebuildCounterIndex();

    $row = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass['Class A']
        ->firstWhere('spell.name', 'Test Root');

    expect($row['hasAnyCounter'])->toBeFalse()
        ->and($row['buckets'])->toBeEmpty();
});

test('a candidate with no cooldown and no kit linkage is not treated as a counter', function () {
    // The pressability filter, asserted directly. Weakened Soul / Echo of Light / Focused Light
    // are neither is_passive nor not_in_spellbook, so the old hygiene filter could not exclude
    // them and they surfaced as "counters" to every Stun in the game.
    [, $patch, $classA, $classB] = counterFixtureWorld();

    $stun = makeCounterCcSpell($patch, 9601, 'Filtered Stun', 'Stun');
    attachToClass($stun, $classA);

    $realCounter = makeCounterAbility($patch, 9602, 'Real Defensive', ['usable_while_cc' => 'stun']);
    attachToClass($realCounter, $classB);

    // Same flags as a real lingering-debuff record: visible, not passive, but nothing a player
    // can press — no cooldown, no charges, no talent/PvP/verified link.
    $lingeringDebuff = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9603, 'name' => 'Weakened Soul Clone',
        'usable_while_cc' => 'stun', 'is_passive' => false, 'not_in_spellbook' => false,
    ]);
    attachToClass($lingeringDebuff, $classB);

    rebuildCounterIndex();

    $row = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass['Class A']
        ->firstWhere('spell.name', 'Filtered Stun');

    expect(counterNames($row, SpellCounter::MECHANISM_USABLE_WHILE))->toContain('Real Defensive')
        ->and(counterNames($row, SpellCounter::MECHANISM_USABLE_WHILE))->not->toContain('Weakened Soul Clone');
});

test('same-name duplicate copies of one counter collapse to a single entry', function () {
    // Five separate copies of Metamorphosis were showing as five counters to the same CC. The
    // cooldown-bearing copy is the one kept.
    [, $patch, $classA, $classB] = counterFixtureWorld();

    $stun = makeCounterCcSpell($patch, 9701, 'Dup Stun', 'Stun');
    attachToClass($stun, $classA);

    $real = makeCounterAbility($patch, 9702, 'Metamorphosis Clone', ['usable_while_cc' => 'stun']);
    attachToClass($real, $classB);

    $internalCopy = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9703, 'name' => 'Metamorphosis Clone',
        'usable_while_cc' => 'stun', 'charges' => 1, 'is_passive' => false, 'not_in_spellbook' => false,
    ]);
    attachToClass($internalCopy, $classB);

    rebuildCounterIndex();

    $row = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass['Class A']
        ->firstWhere('spell.name', 'Dup Stun');

    $matches = counterNames($row, SpellCounter::MECHANISM_USABLE_WHILE)->filter(fn ($n) => $n === 'Metamorphosis Clone');

    expect($matches)->toHaveCount(1);
});

test('the stored index agrees with a live computation of the same rules', function () {
    // Guards the actual risk of materializing: that the table drifts from the rules that built it.
    [, $patch, $classA, $classB] = counterFixtureWorld();

    $stun = makeCounterCcSpell($patch, 9801, 'Agreement Stun', 'Stun');
    attachToClass($stun, $classA);

    $counter = makeCounterAbility($patch, 9802, 'Agreement Ward');
    attachToClass($counter, $classB);
    SpellEffect::create(['spell_id' => $counter->id, 'effect_index' => 1, 'type' => 'Mechanic Immunity', 'misc_value' => 12]);

    $indexer = app(SpellCounterIndexer::class);
    $indexer->rebuild();

    $availableIds = SpellClassAvailability::pluck('spell_id')->unique();
    $live = collect($indexer->countersFor($stun->fresh(), $indexer->buildPools($patch, $availableIds)))
        ->map(fn (array $c) => $c['spell']->id.'|'.$c['mechanism'])
        ->sort()
        ->values();

    $stored = $stun->fresh()->counteredBy
        ->map(fn (SpellCounter $c) => $c->counter_spell_id.'|'.$c->mechanism)
        ->sort()
        ->values();

    expect($stored->all())->toBe($live->all())
        ->and($stored)->not->toBeEmpty();
});

test('renders without a picker and shows the class-grouped list directly on load', function () {
    [, $patch, $classA] = counterFixtureWorld();

    $classA->update(['name' => 'Render Class', 'slug' => 'render-class']);

    $stun = makeCounterCcSpell($patch, 9901, 'Render Stun', 'Stun');
    attachToClass($stun, $classA);

    rebuildCounterIndex();

    $html = Livewire::test(ClaudesCounters::class)->html();

    expect($html)->toContain('Render Class')
        ->and($html)->toContain('Render Stun')
        ->and($html)->not->toContain('Simulated Pressure') // the old matchup sim is gone
        ->and($html)->not->toContain('<select'); // no class/spec picker anywhere on the page
});

/**
 * 2026-09-07. dr_category is deliberately curated onto a CC's real AURA spell_id as well as onto
 * the ability a player presses, because the aura id is what SPELL_AURA_APPLIED carries in a combat
 * log and FindCcChains/CcTargetingAnalyzer match on it. That is correct and must stay — but this
 * index is about abilities you press, so the aura copy has no business here.
 *
 * Reported as the contradiction it produced: Frost DK's "Absolute Zero" (the 3s freeze aura a
 * PASSIVE adds to Frostwyrm's Fury, not a button) had 44 counters on /spell-counters while being
 * correctly absent from WoW Comps' Crowd Control, which builds from the spec kit.
 */
test('a non-pressable aura copy of a CC gets no counters row, while its pressable copy does', function () {
    [, $patch, $classA, $classB] = counterFixtureWorld();

    // The pressable ability: real cooldown.
    $pressable = makeCounterCcSpell($patch, 8801, 'Twin Stun', 'Stun');
    attachToClass($pressable, $classA);

    // The aura copy the combat log actually carries: same name, same dr_category, no cooldown and
    // no talent/PvP/verified-override link of any kind.
    $aura = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 8802, 'name' => 'Twin Stun',
        'dr_category' => 'Stun', 'school' => 'Physical', 'is_passive' => false,
    ]);
    attachToClass($aura, $classA);

    $counter = makeCounterAbility($patch, 8803, 'Twin Ward');
    attachToClass($counter, $classB);
    SpellEffect::create(['spell_id' => $counter->id, 'effect_index' => 1, 'type' => 'Mechanic Immunity', 'misc_value' => 12]);

    rebuildCounterIndex();

    expect($pressable->fresh()->counteredBy)->not->toBeEmpty()
        ->and($aura->fresh()->counteredBy)->toBeEmpty()
        // The curation itself is untouched — only this index's view of it narrowed.
        ->and($aura->fresh()->dr_category)->toBe('Stun');
});

test('a passive-granted CC aura with no pressable copy at all is excluded entirely', function () {
    [, $patch, $classA, $classB] = counterFixtureWorld();

    // Absolute Zero's exact shape: the only tagged copy is the aura, and nothing presses it.
    $aura = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 377048, 'name' => 'Absolute Zero',
        'dr_category' => 'Stun', 'school' => 'Physical', 'is_passive' => false,
    ]);
    attachToClass($aura, $classA, null, 'baseline');

    $counter = makeCounterAbility($patch, 8805, 'Zero Ward');
    attachToClass($counter, $classB);
    SpellEffect::create(['spell_id' => $counter->id, 'effect_index' => 1, 'type' => 'Mechanic Immunity', 'misc_value' => 12]);

    rebuildCounterIndex();

    expect($aura->fresh()->counteredBy)->toBeEmpty();
});
