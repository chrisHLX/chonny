<?php

use App\Http\Services\SpellCounterIndexer;
use App\Http\Services\SpellProfileBuilder;
use App\Livewire\SpellDetail;
use App\Livewire\SpellDetailModal;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellEffect;
use App\Support\SpellProfile;
use Livewire\Livewire;

/**
 * Covers the 2026-09-06 consolidation: one SpellProfile, built by one SpellProfileBuilder, shown
 * by both the site-wide modal and the /spell/{id} page.
 *
 * The tests worth having here are the ones that catch the failure mode this work exists to
 * prevent — two views of the same spell disagreeing — plus the "read the materialized column,
 * don't recompute it" rule, which is invisible in output and therefore easy to regress silently.
 */
function spellProfileWorld(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create([
        'class_id' => $class->id, 'name' => 'Assassination', 'slug' => 'assassination', 'external_spec_id' => 259,
    ]);

    return [$patch, $class, $spec];
}

function makeProfileSpell(Patch $patch, array $attributes = []): Spell
{
    return Spell::create(array_merge([
        'patch_id' => $patch->id,
        'spell_id' => 408,
        'name' => 'Kidney Shot',
        'school' => 'Physical',
        'description' => 'Stuns the target.',
        'dr_category' => 'Stun',
        'category' => 'Crowd Control',
        'cooldown_seconds' => 30,
        'duration_seconds' => 3,
        'pvp_duration_seconds' => 5,
        'is_passive' => false,
        'not_in_spellbook' => false,
    ], $attributes));
}

test('build-independent facts are read from the materialized column, not recomputed', function () {
    [$patch] = spellProfileWorld();

    // A value categorize() could never produce. If the profile returns it, the column was read.
    $spell = makeProfileSpell($patch, ['category' => 'Sentinel Category']);

    $profile = app(SpellProfileBuilder::class)->forDetail($spell);

    expect($profile->category)->toBe('Sentinel Category');
});

test('category falls back to a live computation when the column has not been materialized', function () {
    // Not defensive padding: the column is only ever written by import:spelldata, so it is null
    // for every spell created outside a real import — which is every test fixture in this suite.
    [$patch] = spellProfileWorld();

    $spell = makeProfileSpell($patch, ['category' => null]);
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'type' => 'Stun']);

    $profile = app(SpellProfileBuilder::class)->forDetail($spell->fresh());

    expect($profile->category)->toBe('Crowd Control');
});

test('traits() surfaces category and dr_category together, not one instead of the other', function () {
    [$patch] = spellProfileWorld();

    $spell = makeProfileSpell($patch, ['is_interrupt' => true]);
    $labels = array_column(app(SpellProfileBuilder::class)->forDetail($spell)->traits(), 'label');

    expect($labels)->toContain('Crowd Control')
        ->and($labels)->toContain('Stun')
        ->and($labels)->toContain('Interrupt');
});

test('the PvP-verified duration wins over the PvE tooltip value and says so', function () {
    // pvp_duration_seconds is hand-verified per spell and is NOT derivable from duration_seconds
    // — Kidney Shot's stored 3.00 is a low-combo-point value against a real 6s in PvP.
    [$patch] = spellProfileWorld();

    $profile = app(SpellProfileBuilder::class)->forDetail(makeProfileSpell($patch));

    expect($profile->effectiveDurationSeconds())->toBe(5.0)
        ->and($profile->durationIsPvpVerified())->toBeTrue();
});

test('a spell with no PvP duration falls back to the PvE duration and does not claim verification', function () {
    [$patch] = spellProfileWorld();

    $profile = app(SpellProfileBuilder::class)->forDetail(makeProfileSpell($patch, ['pvp_duration_seconds' => null]));

    expect($profile->effectiveDurationSeconds())->toBe(3.0)
        ->and($profile->durationIsPvpVerified())->toBeFalse();
});

test('the ArrayAccess bridge answers every key the old entry arrays exposed', function () {
    // This bridge is what let the consolidation land without rewriting 13 blade templates and
    // invalidating every data/spell-kits file in the same change. If a key is dropped, a template
    // silently renders nothing rather than erroring, so it is asserted explicitly.
    [$patch] = spellProfileWorld();

    $profile = app(SpellProfileBuilder::class)->forDetail(makeProfileSpell($patch));

    foreach (['spell', 'category', 'description', 'formulaModifiers', 'modifiers', 'cooldown', 'charges', 'grantsCcImmunity'] as $key) {
        expect($profile[$key])->not->toBeNull("bridge key '{$key}' returned null");
    }

    expect($profile['spell']->spell_id)->toBe(408)
        ->and($profile['modifiers'])->toHaveKeys(['named', 'baseline', 'potential']);
});

test('a profile cannot be mutated through the array bridge', function () {
    [$patch] = spellProfileWorld();
    $profile = app(SpellProfileBuilder::class)->forDetail(makeProfileSpell($patch));

    expect(fn () => $profile['category'] = 'Something Else')->toThrow(LogicException::class);
});

test('a profile built without a spec context still reports base values rather than guessing a build', function () {
    [$patch] = spellProfileWorld();

    $profile = app(SpellProfileBuilder::class)->forDetail(makeProfileSpell($patch));

    expect($profile['cooldown']['seconds'])->toEqual(30.0)
        ->and($profile->hasBuildContext())->toBeTrue();
});

test('counters are available on the profile itself, not only on the counters page', function () {
    // The whole reason spell_counters exists: before it, this question could only be asked from
    // inside ClaudesCounters.
    [$patch, $class] = spellProfileWorld();

    $stun = makeProfileSpell($patch);
    SpellClassAvailability::create(['spell_id' => $stun->id, 'class_id' => $class->id, 'source' => 'talent']);

    $iceBlock = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 45438, 'name' => 'Ice Block',
        'cooldown_seconds' => 240, 'is_passive' => false, 'not_in_spellbook' => false,
    ]);
    SpellClassAvailability::create(['spell_id' => $iceBlock->id, 'class_id' => $class->id, 'source' => 'talent']);
    SpellEffect::create(['spell_id' => $iceBlock->id, 'effect_index' => 1, 'type' => 'School Immunity', 'affected_schools' => 'All']);

    app(SpellCounterIndexer::class)->rebuild();

    $profile = app(SpellProfileBuilder::class)->forDetail($stun->fresh());

    expect($profile->counteredBy)->not->toBeNull()
        ->and($profile->counteredBy->pluck('counterSpell.name'))->toContain('Ice Block')
        ->and($profile->countersByMechanism()->keys())->toContain('immunity_school');
});

test('the detail page renders the facts that were previously missing from the modal entirely', function () {
    // school, dr_category, duration, availability and counters were all absent from the site-wide
    // spell view before this work, despite every one of them already being in the database.
    [$patch, $class] = spellProfileWorld();

    $stun = makeProfileSpell($patch);
    SpellClassAvailability::create(['spell_id' => $stun->id, 'class_id' => $class->id, 'source' => 'talent']);

    $html = Livewire::test(SpellDetail::class, ['spellId' => $stun->id])->html();

    expect($html)->toContain('Kidney Shot')
        ->and($html)->toContain('Physical')      // school
        ->and($html)->toContain('Stun')          // dr_category
        ->and($html)->toContain('Crowd Control') // category
        ->and($html)->toContain('Duration')
        ->and($html)->toContain('Available To')
        ->and($html)->toContain('Rogue');
});

test('the modal and the detail page agree on the same spell', function () {
    // The anti-drift guarantee. These were two independently-maintained shapes that had already
    // diverged in both directions before being unified.
    [$patch, $class] = spellProfileWorld();

    $stun = makeProfileSpell($patch);
    SpellClassAvailability::create(['spell_id' => $stun->id, 'class_id' => $class->id, 'source' => 'talent']);

    $pageProfile = Livewire::test(SpellDetail::class, ['spellId' => $stun->id])->instance()->profile;
    $modalProfile = Livewire::test(SpellDetailModal::class)->call('show', $stun->id)->instance()->profile;

    expect($modalProfile)->toBeInstanceOf(SpellProfile::class)
        ->and($modalProfile->category)->toBe($pageProfile->category)
        ->and($modalProfile->drCategory())->toBe($pageProfile->drCategory())
        ->and($modalProfile->school())->toBe($pageProfile->school())
        ->and($modalProfile->effectiveDurationSeconds())->toBe($pageProfile->effectiveDurationSeconds())
        ->and(array_column($modalProfile->traits(), 'label'))->toBe(array_column($pageProfile->traits(), 'label'));
});

test('a ?spec that cannot actually cast the spell is ignored rather than silently used', function () {
    // Honouring it would resolve talent-modified numbers against a build that cannot cast this
    // spell at all — output that looks like real data but is not.
    [$patch, $class, $spec] = spellProfileWorld();

    $otherClass = GameClass::create(['game_id' => $class->game_id, 'name' => 'Mage', 'slug' => 'mage']);
    $otherSpec = Specialization::create([
        'class_id' => $otherClass->id, 'name' => 'Frost', 'slug' => 'frost', 'external_spec_id' => 64,
    ]);

    $stun = makeProfileSpell($patch);
    SpellClassAvailability::create([
        'spell_id' => $stun->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'talent',
    ]);

    $component = Livewire::test(SpellDetail::class, ['spellId' => $stun->id, 'spec' => $otherSpec->id])->instance();

    expect($component->specId)->toBeNull()
        ->and($component->classId)->toBeNull();

    $accepted = Livewire::test(SpellDetail::class, ['spellId' => $stun->id, 'spec' => $spec->id])->instance();

    expect($accepted->specId)->toBe($spec->id)
        ->and($accepted->classId)->toBe($class->id);
});

test('the route serves a real spell and 404s an unknown one', function () {
    // Exercised over HTTP rather than through Livewire::test() — the latter renders the component
    // in isolation and swallows mount()'s abort, so it can neither prove the route/layout wiring
    // works nor that a bad id actually 404s.
    [$patch, $class] = spellProfileWorld();

    $stun = makeProfileSpell($patch);
    SpellClassAvailability::create(['spell_id' => $stun->id, 'class_id' => $class->id, 'source' => 'talent']);

    $this->get(route('spell.show', $stun->id))
        ->assertOk()
        ->assertSee('Kidney Shot');

    $this->get('/spell/999999')->assertNotFound();
});

/**
 * Talent-conditional dr_category (2026-09-06). The real case: Holy Word: Chastise incapacitates
 * by default and stuns once Censure is talented, and Censure is selected in the Holy Priest
 * admin-default build the site actually renders — so the flat column was wrong for essentially
 * every viewer. These assert the resolution rule itself, and (critically) that it stays a
 * DISPLAY concern: the stored column must never be rewritten, because every build-independent
 * consumer is right to keep reading it.
 */
function makeConditionalCcSpell(Patch $patch): array
{
    $gating = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 200199, 'name' => 'Censure',
        'description' => 'Holy Word: Chastise stuns the target.', 'is_passive' => true,
    ]);

    $chastise = makeProfileSpell($patch, [
        'spell_id' => 88625,
        'name' => 'Holy Word: Chastise',
        'dr_category' => 'Incapacitate',
        'conditional_dr_gating_spell_id' => 200199,
        'conditional_dr_category' => 'Stun',
    ]);

    return [$chastise, $gating];
}

test('a talent-conditional dr_category resolves to the alternate when the gating talent is selected', function () {
    [$patch] = spellProfileWorld();
    [$chastise, $gating] = makeConditionalCcSpell($patch);

    $resolved = app(SpellProfileBuilder::class)->resolveDrCategory($chastise, collect([$gating->id]));

    expect($resolved)->toBe('Stun');
});

test('a talent-conditional dr_category stays on the base value when the gating talent is not selected', function () {
    [$patch] = spellProfileWorld();
    [$chastise] = makeConditionalCcSpell($patch);

    // A build with real selections, just not this one — distinct from the no-build case below.
    $other = makeProfileSpell($patch, ['spell_id' => 9999, 'name' => 'Something Else', 'dr_category' => null]);

    expect(app(SpellProfileBuilder::class)->resolveDrCategory($chastise, collect([$other->id])))->toBeNull();
});

test('the gating id is matched against external spell_id, never the internal primary key', function () {
    // The two id spaces are genuinely different and have collided in this codebase before (see
    // fetch-spell-icons.php's trace in CLAUDE.md). Passing the gating spell's INTERNAL id as if
    // it were selected must not accidentally satisfy a gate written in EXTERNAL ids, and a
    // selection whose internal id happens to equal the external gating number must not either.
    [$patch] = spellProfileWorld();
    [$chastise, $gating] = makeConditionalCcSpell($patch);

    $decoy = makeProfileSpell($patch, ['spell_id' => 12345, 'name' => 'Decoy', 'dr_category' => null]);

    expect($gating->id)->not->toBe(200199)
        ->and(app(SpellProfileBuilder::class)->resolveDrCategory($chastise, collect([$decoy->id])))->toBeNull()
        ->and(app(SpellProfileBuilder::class)->resolveDrCategory($chastise, collect([$gating->id])))->toBe('Stun');
});

test('resolving a conditional dr_category never writes to the spell', function () {
    [$patch] = spellProfileWorld();
    [$chastise, $gating] = makeConditionalCcSpell($patch);

    app(SpellProfileBuilder::class)->resolveDrCategory($chastise, collect([$gating->id]));

    expect($chastise->fresh()->dr_category)->toBe('Incapacitate');
});

test('SpellProfile::drCategory() and traits() both report the resolved value, not the base column', function () {
    [$patch] = spellProfileWorld();
    [$chastise] = makeConditionalCcSpell($patch);

    $profile = new SpellProfile(
        spell: $chastise,
        category: 'Crowd Control',
        grantsCcImmunity: collect(),
        resolvedDrCategory: 'Stun',
    );

    $drTrait = collect($profile->traits())->firstWhere('tone', 'dr');

    expect($profile->drCategory())->toBe('Stun')
        ->and($drTrait['label'])->toBe('Stun')
        ->and($profile['drCategory'])->toBe('Stun');
});

test('SpellProfile::drCategory() falls back to the base column when nothing was resolved', function () {
    [$patch] = spellProfileWorld();
    [$chastise] = makeConditionalCcSpell($patch);

    $profile = new SpellProfile(spell: $chastise, category: 'Crowd Control', grantsCcImmunity: collect());

    expect($profile->drCategory())->toBe('Incapacitate');
});
