<?php

use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideType;
use App\Http\Services\TalentSelectionService;
use App\Http\Services\UserGuideChainService;
use App\Livewire\Guides\Builder;
use App\Livewire\Guides\Index;
use App\Livewire\TalentSelector;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\TalentBuild;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideMember;
use App\Models\UserGuideSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Covers the 2026-09-08 guide changes: the merged Sequence section kind, the per-member talent
 * build, and the guide-health signal.
 *
 * The palette's own grouping is exercised against real imported spell data by hand (it needs a
 * talent tree, arena-log priority flags and a curated dr_category to say anything meaningful,
 * none of which a fixture has). What is asserted here is the behaviour that must hold regardless
 * of what data is loaded: that the retired kinds are gone, that a guide's build is private to
 * that guide, and that a step which stops resolving is reported rather than dropped.
 */
function guideFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '1.0.0-test', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety']);
    $user = User::factory()->create();

    $guide = UserGuide::create([
        'user_id' => $user->id,
        'status' => 'draft',
        'visibility' => 'invited',
        'title' => 'Test guide',
    ]);

    $member = UserGuideMember::create([
        'user_guide_id' => $guide->id,
        'position' => 0,
        'spec_id' => $spec->id,
    ]);

    return compact('game', 'patch', 'class', 'spec', 'user', 'guide', 'member');
}

/*
 * The merged Sequence kind.
 */

test('the retired chain and go kinds no longer exist', function () {
    expect(UserGuideSectionKind::tryFrom('chain'))->toBeNull()
        ->and(UserGuideSectionKind::tryFrom('go'))->toBeNull()
        ->and(UserGuideSectionKind::tryFrom('sequence'))->toBe(UserGuideSectionKind::Sequence);

    // Three kinds, and only one of them is an ability sequence.
    expect(collect(UserGuideSectionKind::cases())->map->value->all())
        ->toBe(['sequence', 'defensives', 'text']);
});

test('a request naming a retired kind creates nothing rather than falling back to a default', function () {
    $f = guideFixture();

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])
        ->call('addSection', 'chain')
        ->call('addSection', 'go');

    expect($f['guide']->sections()->count())->toBe(0);
});

test('the index creates a guide of each type, and both start with one sequence section', function () {
    $f = guideFixture();

    Livewire::actingAs($f['user'])->test(Index::class)->call('create', 'comp')->assertRedirect();
    Livewire::actingAs($f['user'])->test(Index::class)->call('create', 'class')->assertRedirect();

    $comp = UserGuide::where('title', 'Untitled comp guide')->firstOrFail();
    $class = UserGuide::where('title', 'Untitled class guide')->firstOrFail();

    expect($comp->type)->toBe(UserGuideType::Comp)
        ->and($class->type)->toBe(UserGuideType::ClassGuide)
        ->and($comp->sections()->first()->kind)->toBe(UserGuideSectionKind::Sequence)
        ->and($class->sections()->first()->kind)->toBe(UserGuideSectionKind::Sequence);

    // A section kind is not a guide type — passing one creates nothing.
    Livewire::actingAs($f['user'])->test(Index::class)->call('create', 'sequence');
    expect(UserGuide::count())->toBe(3); // the fixture's own guide, plus the two above
});

/*
 * Per-member talent builds.
 */

test('a comp slot has no build until the author opens its talents', function () {
    $f = guideFixture();

    expect($f['member']->talent_build_id)->toBeNull();

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])
        ->call('openTalents', 0);

    $build = $f['member']->fresh()->talentBuild;

    expect($build)->not->toBeNull()
        ->and($build->spec_id)->toBe($f['spec']->id);
});

test('a guide own build is invisible to the rest of the site', function () {
    $f = guideFixture();

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])->call('openTalents', 0);
    $guideBuild = $f['member']->fresh()->talentBuild;

    // user_id NULL + is_default FALSE is the combination that keeps it out of both lookups —
    // otherwise it would surface on WoW Comps and Spell Explorer for every visitor.
    expect($guideBuild->user_id)->toBeNull()
        ->and((bool) $guideBuild->is_default)->toBeFalse();

    $service = app(TalentSelectionService::class);

    expect($service->resolveActiveBuild(null, $f['spec']->id)->id)->not->toBe($guideBuild->id)
        ->and($service->resolveActiveBuild($f['user'], $f['spec']->id)->id)->not->toBe($guideBuild->id);
});

test('resetting talents deletes the build rather than emptying it', function () {
    $f = guideFixture();

    $c = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']]);
    $c->call('openTalents', 0);
    $buildId = $f['member']->fresh()->talent_build_id;

    $c->call('resetTalents', 0);

    // An empty build and no build are different states: an empty one would resolve every ability
    // to its untalented numbers, where "reset" has to mean "back to the spec's default".
    expect($f['member']->fresh()->talent_build_id)->toBeNull()
        ->and(TalentBuild::find($buildId))->toBeNull();
});

test('another author cannot open or reset talents on a guide they do not own', function () {
    $f = guideFixture();
    $stranger = User::factory()->create();

    Livewire::actingAs($stranger)->test(Builder::class, ['guide' => $f['guide']])->assertForbidden();

    expect($f['member']->fresh()->talent_build_id)->toBeNull();
});

test('the talent build id is locked, so it cannot be repointed from the client', function () {
    $f = guideFixture();

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])->call('openTalents', 0);
    $ours = $f['member']->fresh()->talentBuild;

    // The shape a tampered request would take: point the selector at somebody else's build.
    $victim = TalentBuild::create([
        'spec_id' => $f['spec']->id,
        'patch_id' => $f['patch']->id,
        'is_default' => true,
        'name' => 'Admin default',
        'share_slug' => 'victim-slug',
    ]);

    $component = Livewire::actingAs($f['user'])
        ->test(TalentSelector::class, ['specId' => $f['spec']->id, 'buildId' => $ours->id]);

    expect(fn () => $component->set('buildId', $victim->id))->toThrow(Exception::class);
    expect($component->instance()->buildId)->toBe($ours->id);
});

/*
 * Guide health — what a patch does to a saved guide.
 */

test('a step whose spell no longer exists is reported, not dropped', function () {
    $f = guideFixture();

    $section = UserGuideSection::create([
        'user_guide_id' => $f['guide']->id,
        'kind' => UserGuideSectionKind::Sequence,
        'title' => 'Opener',
        'row' => 0,
        'column' => 0,
    ]);

    // 999999 exists in no patch — the shape of an ability removed by a later patch.
    UserGuideBlock::create([
        'user_guide_section_id' => $section->id,
        'position' => 0,
        'block_type' => 'spell',
        'payload' => ['external_spell_id' => 999999, 'source_spec_id' => $f['spec']->id],
    ]);

    $svc = app(UserGuideChainService::class);
    $steps = $svc->resolve($section);

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['unresolved'])->toBeTrue()
        ->and($steps[0]['block']->externalSpellId())->toBe(999999);

    $health = $svc->health($f['guide']->fresh(), [$section->id => ['steps' => $steps, 'metrics' => []]]);

    expect($health['unresolved'])->toBe(1)
        ->and($health['sections'])->toBe([$section->id => 'Opener']);
});

test('a guide authored on an older patch is flagged, and one with no patch is not', function () {
    $f = guideFixture();
    $svc = app(UserGuideChainService::class);

    // No patch recorded — the guide was never really edited, so there is nothing to be stale.
    expect($svc->health($f['guide'], [])['patch_changed'])->toBeFalse();

    $old = Patch::create(['game_id' => $f['game']->id, 'build_version' => '0.9.0-old', 'is_current' => false]);
    $f['guide']->update(['patch_id' => $old->id]);

    $health = $svc->health($f['guide']->fresh()->load('patch'), []);

    expect($health['patch_changed'])->toBeTrue()
        ->and($health['authored_patch'])->toBe('0.9.0-old')
        ->and($health['current_patch'])->toBe('1.0.0-test');
});

/*
 * The two guide types.
 */

test('a comp guide holds three specs and a class guide holds one', function () {
    $f = guideFixture();
    $second = Specialization::create(['class_id' => $f['class']->id, 'name' => 'Outlaw', 'slug' => 'outlaw']);

    expect($f['guide']->type)->toBe(UserGuideType::Comp) // the column default
        ->and($f['guide']->maxMembers())->toBe(3);

    $f['guide']->update(['type' => UserGuideType::ClassGuide]);
    $guide = $f['guide']->fresh();

    expect($guide->maxMembers())->toBe(1);

    // Slot 1 is out of range for a class guide, so a request naming it must do nothing rather
    // than quietly building a comp inside a single-spec guide.
    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $guide])
        ->call('openMemberPicker', 1)
        ->call('setMember', $second->id);

    expect($guide->members()->count())->toBe(1);
});

test('a class guide never reports a bracket, however many rows it has', function () {
    $f = guideFixture();
    $f['guide']->update(['type' => UserGuideType::ClassGuide, 'opponent_spec_id' => $f['spec']->id]);

    expect($f['guide']->fresh()->bracket())->toBeNull();

    // The same roster in a comp guide is a real bracket, so this is the type deciding, not the count.
    $f['guide']->update(['type' => UserGuideType::Comp]);
    UserGuideMember::create(['user_guide_id' => $f['guide']->id, 'position' => 1, 'spec_id' => $f['spec']->id]);

    expect($f['guide']->fresh()->bracket())->toBe('2v2');
});

test('only a class guide names a guide-level opponent', function () {
    $f = guideFixture();

    // A comp guide's opponents are per-section, so the guide-level setter must refuse.
    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])
        ->call('openGuideOpponentPicker')
        ->call('setGuideOpponent', $f['spec']->id);

    expect($f['guide']->fresh()->opponent_spec_id)->toBeNull();

    $f['guide']->update(['type' => UserGuideType::ClassGuide]);

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])
        ->call('openGuideOpponentPicker')
        ->call('setGuideOpponent', $f['spec']->id);

    expect($f['guide']->fresh()->opponent_spec_id)->toBe($f['spec']->id);
});

test('a defensives section in a class guide inherits the guide opponent, and in a comp guide does not', function () {
    $f = guideFixture();
    $f['guide']->update(['type' => UserGuideType::ClassGuide, 'opponent_spec_id' => $f['spec']->id]);

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])
        ->call('addSection', 'defensives');

    expect($f['guide']->sections()->latest('id')->first()->opponent_spec_id)->toBe($f['spec']->id);

    // A comp guide's VS columns are per-section on purpose — nothing to inherit.
    $comp = UserGuide::create([
        'user_id' => $f['user']->id,
        'type' => UserGuideType::Comp,
        'status' => 'draft',
        'visibility' => 'invited',
        'title' => 'Comp',
    ]);

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $comp])
        ->call('addSection', 'defensives');

    expect($comp->sections()->latest('id')->first()->opponent_spec_id)->toBeNull();
});

/*
 * The comp-slot buttons, asserted as RENDERED MARKUP.
 *
 * Every other test here reaches the slot actions by calling them with an argument
 * (->call('openMemberPicker', 0)), which is exactly why a real bug shipped undetected: the
 * component's prop was named 'slot', which is RESERVED in a Blade component (Laravel injects the
 * component's slot content under that name and it wins over a same-named prop), so :slot="0"
 * rendered as an empty string and every button emitted openMemberPicker() with no argument at
 * all — a BindingResolutionException on a required int the moment anyone clicked it.
 *
 * A direct ->call() can never catch that. These assert the argument actually reaches the markup.
 */

test('an empty comp slot renders its own index into the picker button', function () {
    $f = guideFixture();
    $f['member']->delete();

    $html = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])->html();

    // The side argument is part of the call now, so capture the index specifically.
    preg_match_all('/wire:click="openMemberPicker\((\d*),\s*.([a-z]+).\)"/', $html, $m);

    expect($m[1])->not->toBeEmpty()
        ->and($m[1])->each->not->toBe('');           // the actual regression

    // Three of your own slots and three enemy ones, each numbered from 0 on its own side —
    // which is exactly why position is unique per (guide, SIDE) rather than per guide.

    expect(array_slice($m[1], 0, 3))->toBe(['0', '1', '2'])
        ->and(array_slice($m[2], 0, 3))->toBe(['team', 'team', 'team'])
        ->and(array_slice($m[1], 3, 3))->toBe(['0', '1', '2'])
        ->and(array_slice($m[2], 3, 3))->toBe(['enemy', 'enemy', 'enemy']);
});

test('a filled comp slot renders its own index into the talents and clear buttons', function () {
    $f = guideFixture();

    $html = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])->html();

    preg_match_all('/wire:click="(?:openTalents|removeMember)\((\d*),\s*.([a-z]+).\)"/', $html, $m);

    expect($m[1])->toHaveCount(2)
        ->and($m[1])->each->toBe('0')
        ->and($m[2])->each->toBe('team');
});

test('a class guide renders one slot and never offers a second', function () {
    $f = guideFixture();
    $f['guide']->update(['type' => UserGuideType::ClassGuide]);
    $f['member']->delete();

    $html = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])->html();

    preg_match_all('/wire:click="openMemberPicker\((\d*),\s*.([a-z]+).\)"/', $html, $m);

    // One comp slot, and no enemy slots at all: a class guide already names its single opponent
    // on the guide itself, so a second way to say the same thing would only confuse.
    expect($m[1])->toBe(['0'])
        ->and($m[2])->toBe(['team']);
});

/*
 * The palette's baseline sources.
 *
 * These assert behaviour that must hold whatever data is loaded. The real payoff — that a Rogue
 * class guide can name Rupture, Envenom and Mutilate — needs a talent tree, arena-log priority
 * flags and real availability rows, none of which a fixture has, so it is verified against the
 * live database by hand (see the section in CLAUDE.md).
 */

test('explicitBaselineAbilityIds applies no cooldown floor, and never touches the ambiguous bucket', function () {
    $f = guideFixture();
    $ts = app(App\Http\Services\TalentSelectionService::class);

    $make = function (string $name, int $externalId, ?float $cd, ?int $specId) use ($f) {
        $spell = App\Models\Spell::create([
            'patch_id' => $f['patch']->id,
            'spell_id' => $externalId,
            'name' => $name,
            'cooldown_seconds' => $cd,
            'is_passive' => false,
            'not_in_spellbook' => false,
        ]);

        App\Models\SpellClassAvailability::create([
            'spell_id' => $spell->id,
            'class_id' => $f['class']->id,
            'spec_id' => $specId,
            'source' => 'baseline',
            'patch_id' => $f['patch']->id,
        ]);

        return $spell;
    };

    $finisher = $make('Fixture Finisher', 900001, null, $f['spec']->id);   // no cooldown, explicit spec
    $cooldown = $make('Fixture Cooldown', 900002, 30.0, $f['spec']->id);   // has one, explicit spec
    $ambiguous = $make('Fixture Ambiguous', 900003, null, null);           // the spec_id = NULL bucket

    $ids = $ts->explicitBaselineAbilityIds($f['class']->id, $f['spec']->id);

    // The whole point: a cooldown-less ability is still a real button.
    expect($ids)->toContain($finisher->id)
        ->and($ids)->toContain($cooldown->id)
        // Never the ambiguous bucket — the one that put Mind Sear on Discipline Priest.
        ->and($ids)->not->toContain($ambiguous->id);

    // The narrower sibling still applies its floor, so the two remain genuinely different
    // questions rather than one having quietly become the other.
    $withFloor = $ts->explicitBaselineCooldownAbilityIds($f['class']->id, $f['spec']->id);
    expect($withFloor)->toContain($cooldown->id)
        ->and($withFloor)->not->toContain($finisher->id);
});

test('the accessibility auto-cast button is never offered as an ability', function () {
    $f = guideFixture();

    // Blizzard tags Single-Button Assistant to all 40 specs with a real, explicit baseline row,
    // so it passes every structural test a genuine ability passes.
    $spell = App\Models\Spell::create([
        'patch_id' => $f['patch']->id,
        'spell_id' => 1229376,
        'name' => 'Single-Button Assistant',
        'is_passive' => false,
        'not_in_spellbook' => false,
    ]);

    App\Models\SpellClassAvailability::create([
        'spell_id' => $spell->id,
        'class_id' => $f['class']->id,
        'spec_id' => $f['spec']->id,
        'source' => 'baseline',
        'patch_id' => $f['patch']->id,
    ]);

    expect(app(App\Http\Services\TalentSelectionService::class)
        ->explicitBaselineAbilityIds($f['class']->id, $f['spec']->id))
        ->not->toContain($spell->id);
});

/*
 * The palette is built only for the section that is open.
 *
 * It used to be rendered for every section and hidden with Alpine x-show, so a three-section
 * guide paid ~810ms and ~150 queries of palette work on EVERY round trip — including ones that
 * had nothing to do with palettes. Measured 2026-09-08: the initial render was 1,059ms/172
 * queries/279KB and renaming the guide cost 1,575ms/195 queries. Rendering only the open one
 * took those to 30ms/12 queries/20KB and 134ms/26 queries.
 *
 * Asserted through the rendered markup rather than by timing: a duration assertion would be
 * flaky, but "is the markup for a closed section's palette present at all" is exact, and it is
 * the thing that actually regresses if someone moves this back behind an x-show.
 */

test('no palette is built until a section is opened', function () {
    $f = guideFixture();
    $section = UserGuideSection::create([
        'user_guide_id' => $f['guide']->id,
        'kind' => UserGuideSectionKind::Sequence,
        'row' => 0, 'column' => 0, 'title' => 'Opener',
    ]);

    $component = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()]);

    expect($component->get('openPaletteFor'))->toBeNull();
    expect($component->html())->not->toContain('addSpell(');

    $component->call('togglePalette', $section->id);
    expect($component->get('openPaletteFor'))->toBe($section->id);

    // Clicking the same section again closes it rather than rebuilding.
    $component->call('togglePalette', $section->id);
    expect($component->get('openPaletteFor'))->toBeNull();
});

test('only one section palette is open at a time', function () {
    $f = guideFixture();
    $a = UserGuideSection::create(['user_guide_id' => $f['guide']->id, 'kind' => UserGuideSectionKind::Sequence, 'row' => 0, 'column' => 0, 'title' => 'A']);
    $b = UserGuideSection::create(['user_guide_id' => $f['guide']->id, 'kind' => UserGuideSectionKind::Sequence, 'row' => 1, 'column' => 0, 'title' => 'B']);

    $component = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])
        ->call('togglePalette', $a->id)
        ->call('togglePalette', $b->id);

    expect($component->get('openPaletteFor'))->toBe($b->id);
    expect($component->html())->not->toContain('addSpell('.$a->id.',');
});

test('a section belonging to someone else cannot be opened', function () {
    $f = guideFixture();

    $other = UserGuide::create([
        'user_id' => User::factory()->create()->id,
        'type' => UserGuideType::Comp,
        'status' => 'draft', 'visibility' => 'invited', 'title' => 'Theirs',
    ]);
    $theirSection = UserGuideSection::create([
        'user_guide_id' => $other->id,
        'kind' => UserGuideSectionKind::Sequence,
        'row' => 0, 'column' => 0, 'title' => 'Theirs',
    ]);

    $component = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])
        ->call('togglePalette', $theirSection->id);

    expect($component->get('openPaletteFor'))->toBeNull();
});

test('the title and summary save on blur rather than re-rendering mid-sentence', function () {
    $f = guideFixture();

    // A debounced live binding re-renders the whole component every 600ms while typing, which is
    // felt as the field stuttering. Nothing on the page derives from either field.
    $html = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])->html();

    expect($html)->toContain('wire:model.blur="title"')
        ->and($html)->toContain('wire:model.blur="summary"')
        ->and($html)->not->toContain('debounce.600ms="title"');

    // Still actually saves.
    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])
        ->set('title', 'Renamed on blur');

    expect($f['guide']->fresh()->title)->toBe('Renamed on blur');
});

/**
 * Cache payload shape — a production memory limit, not a preference.
 *
 * Production runs PHP with memory_limit=128M on a 1 vCPU / 2GB box. Caching kit entries as live
 * objects costs 6.4MB per spec to serialize (a Spell model and its relations is 5.3KB of each
 * ~6.6KB entry), and RedisStore::serialize() holds that whole string alongside the objects — so a
 * 3-spec comp guide whose members each name their own talent build died with an out-of-memory
 * fatal at that exact limit, reproducibly. Storing the compact toJsonSafeArray() shape instead
 * (the same representation the 40 precomputed kit files use) is 296KB, and the palette caches the
 * grouping as bare spell ids (2KB) rather than the entries.
 *
 * This asserts the SHAPE that keeps it small, because the failure it prevents only reproduces
 * under a real memory limit with real spell data — which a fixture cannot provide.
 */
test('kit and palette caches store compact shapes, never live models', function () {
    $service = new ReflectionClass(App\Http\Services\UserGuideChainService::class);

    $sourceOf = function (string $method) use ($service): string {
        $m = $service->getMethod($method);

        return implode("\n", array_slice(
            file($service->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));
    };

    // specEntriesRaw() owns the Cache::remember, so it is the method that decides what is
    // WRITTEN — and it must hand over toJsonSafeArray()'s compact shape, never live entries.
    // (Split out of specEntries() on 2026-09-08 so a section can filter the cached array down to
    // the handful of entries it needs before hydrating any of them.)
    expect($sourceOf('specEntriesRaw'))->toContain('Cache::remember')
        ->and($sourceOf('specEntriesRaw'))->toContain('toJsonSafeArray');

    // Both read paths rehydrate through fromJsonSafeArray(): the whole kit for the palette, and
    // a filtered subset for a section's own steps.
    expect($sourceOf('specEntries'))->toContain('fromJsonSafeArray')
        ->and($sourceOf('specEntriesForSpellIds'))->toContain('fromJsonSafeArray');

    // The palette caches ids and rehydrates, rather than caching the entries a second time.
    expect($sourceOf('groupsFor'))->toContain("\$e['spell']->id")
        ->and($sourceOf('groupsFor'))->toContain('specEntries');
});

test('a cached kit round-trips without losing anything the guide depends on', function () {
    $kits = app(App\Http\Services\SpecKitComputer::class);

    // An empty kit is the degenerate case the guide must survive: a spec with nothing resolved
    // must come back as nothing, not as a broken entry.
    expect($kits->fromJsonSafeArray(['entries' => $kits->toJsonSafeArray([])]))->toBe([]);

    // toJsonSafeArray() deliberately does NOT tolerate nulls, and does not need to: both callers
    // (PrecomputeSpellKits and specEntriesRaw()) feed it resolveEntriesForSpellIds()/compute(),
    // which already filter their own output. Asserted so that contract is visible rather than
    // assumed — if a future caller can produce holes, it must filter them first.
    //
    // Whitespace is normalised before matching: this is a source assertion standing in for a
    // property that needs real spell data to exercise, and it should fail when the filtering
    // goes away, not when the call is wrapped across lines.
    $method = new ReflectionMethod(App\Http\Services\SpecKitComputer::class, 'resolveEntriesForSpellIds');
    $source = preg_replace('/\s+/', '', implode('', array_slice(
        file($method->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    )));
    expect($source)->toContain('->filter()->values()');

    // The hole resolveEntriesForSpellIds() has to filter is a real one, not hypothetical: an id
    // with no spell in this patch resolves to null, and baselineCoreEntry() is where that null
    // comes from. Asserted for real rather than by reading the source.
    expect($kits->baselineCoreEntry(999999999))->toBeNull();
})->group('kit-roundtrip');

/*
 * The enemy team.
 *
 * This is what turns a list of abilities into a matchup plan, so what is asserted is the
 * behaviour that makes it trustworthy: that the two sides of the roster stay separate, and that
 * each numbers its own slots from zero.
 */

test('the two sides of the roster are independent and each numbers from zero', function () {
    $f = guideFixture();
    $f['guide']->update(['type' => UserGuideType::Comp]);
    $other = Specialization::create(['class_id' => $f['class']->id, 'name' => 'Outlaw', 'slug' => 'outlaw']);

    $c = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])
        ->call('openMemberPicker', 0, 'enemy')
        ->call('setMember', $other->id);

    $guide = $f['guide']->fresh();

    // Slot 0 now exists on BOTH sides without colliding — the whole reason the unique key moved.
    expect($guide->members()->count())->toBe(1)
        ->and($guide->enemies()->count())->toBe(1)
        ->and($guide->members()->first()->position)->toBe(0)
        ->and($guide->enemies()->first()->position)->toBe(0)
        ->and($guide->hasEnemies())->toBeTrue();

    // The picker resets to the author's own side, so the next pick cannot land on the enemy by
    // accident.
    expect($c->get('pickingSide'))->toBe('team');

    // Clearing one side leaves the other alone.
    $c->call('removeMember', 0, 'enemy');
    expect($f['guide']->fresh()->enemies()->count())->toBe(0)
        ->and($f['guide']->fresh()->members()->count())->toBe(1);
});

test('a class guide has no enemy slots at all', function () {
    $f = guideFixture();
    $f['guide']->update(['type' => UserGuideType::ClassGuide]);
    $other = Specialization::create(['class_id' => $f['class']->id, 'name' => 'Outlaw', 'slug' => 'outlaw']);

    // It already names its single opponent on the guide itself; a second way to say the same
    // thing would only be confusing, so the cap is zero and a tampered call does nothing.
    expect($f['guide']->maxEnemies())->toBe(0);

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])
        ->call('openMemberPicker', 0, 'enemy')
        ->call('setMember', $other->id);

    expect($f['guide']->fresh()->enemies()->count())->toBe(0);
});

test('the enemy team does not count toward the bracket or the roster guard', function () {
    $f = guideFixture();
    $f['guide']->update(['type' => UserGuideType::Comp]);
    $f['member']->delete();

    UserGuideMember::create([
        'user_guide_id' => $f['guide']->id,
        'side' => 'enemy', 'position' => 0, 'spec_id' => $f['spec']->id,
    ]);

    // An enemy is not your comp: a guide with only opposition still has nothing to draw a palette
    // from, and must not be publishable or labelled a bracket.
    $guide = $f['guide']->fresh();
    expect($guide->hasRoster())->toBeFalse()
        ->and($guide->bracket())->toBeNull()
        ->and($guide->members()->count())->toBe(0);
});

/*
 * Phase blocks were removed on 2026-09-08 — they were a divider you could name and aim at a
 * target, and in practice they did not work the way the layout needed. The three tests that
 * covered addPhase()/setPhaseName()/setPhaseTarget() went with them; what remains is one
 * assertion that the vocabulary itself no longer offers the case, so a re-add is a deliberate
 * decision rather than something that creeps back in through a copied payload.
 */
test('the block vocabulary no longer contains a phase', function () {
    expect(collect(App\Enums\UserGuideBlockType::cases())->pluck('value')->all())
        ->not->toContain('phase');
});

/*
 * Same-name de-duplication in the palette.
 *
 * An ability is routinely several `spells` rows, and the palette shows one of them. Which one it
 * picks is not cosmetic: pick wrong and the ability either disappears from crowd control or
 * appears twice, and both were live bugs reported from real use on 2026-09-09.
 */

/** Build a real kit entry for a synthetic spell, the same shape computeGroupsFor() sorts. */
function paletteEntryFor(array $spellAttributes, bool $isPriority = false, ?float $cooldown = null)
{
    $patch = Patch::where('is_current', true)->first();

    $spell = App\Models\Spell::create(array_merge([
        'patch_id' => $patch->id,
        'spell_id' => random_int(100000, 999999),
        'name' => 'Rake',
        'cooldown_seconds' => $cooldown,
    ], $spellAttributes));

    return app(App\Http\Services\SpellProfileBuilder::class)->forKitEntry(
        spell: $spell,
        category: $spell->dr_category !== null ? 'Crowd Control' : 'Offensive',
        description: ['text' => '', 'uncertain' => false],
        formulaModifiers: collect(),
        modifiers: ['named' => collect(), 'baseline' => collect(), 'potential' => collect()],
        cooldown: ['seconds' => $cooldown],
        charges: ['charges' => null],
        isSelected: true,
        source: 'talent',
        isPriority: $isPriority,
        offensiveDefensive: null,
    );
}

test('the copy carrying a curated dr_category survives de-duplication', function () {
    guideFixture();

    // Rake, exactly as the real data has it: the damaging ability a Feral presses constantly
    // (all over the arena logs, so isPriority) and the stun it applies from stealth (the row that
    // actually carries dr_category). NEITHER has a cooldown, which is what let isPriority decide
    // it before this was fixed — the damage copy won, the survivor had no dr_category, and Rake
    // vanished from crowd control entirely.
    $damage = paletteEntryFor(['dr_category' => null], isPriority: true);
    $stun = paletteEntryFor(['dr_category' => 'Stun', 'requires_stealth' => true]);

    $method = new ReflectionMethod(App\Http\Services\UserGuideChainService::class, 'onePerDisplayName');
    $kept = $method->invoke(app(App\Http\Services\UserGuideChainService::class), collect([$damage, $stun]));

    expect($kept)->toHaveCount(1)
        ->and($kept->first()['spell']->id)->toBe($stun['spell']->id)
        ->and($kept->first()['spell']->dr_category)->toBe('Stun');
});

test('a cooldown still wins when neither copy is crowd control', function () {
    guideFixture();

    // The original reason this sort exists (Secret Technique's effect-less internal copy beating
    // the real ability) must keep working — the dr_category test above it is a tie for both.
    $hidden = paletteEntryFor(['name' => 'Secret Technique', 'dr_category' => null]);
    $real = paletteEntryFor(['name' => 'Secret Technique', 'dr_category' => null], cooldown: 25.0);

    $method = new ReflectionMethod(App\Http\Services\UserGuideChainService::class, 'onePerDisplayName');
    $kept = $method->invoke(app(App\Http\Services\UserGuideChainService::class), collect([$hidden, $real]));

    expect($kept)->toHaveCount(1)
        ->and($kept->first()['spell']->id)->toBe($real['spell']->id);
});

test('the palette never offers an aura copy of an ability you press', function () {
    // Garrote is two curated rows and BOTH must stay tagged: a combat log records the aura (1330),
    // so FindCcChains keeps that one and drops 703, while a palette offers buttons and must do the
    // exact opposite. Asserted as a constant rather than through a full palette build, which needs
    // real imported spell data.
    $c = new ReflectionClass(App\Http\Services\UserGuideChainService::class);
    $excluded = $c->getConstant('PALETTE_EXCLUDED_CC_SPELL_IDS');

    expect($excluded)->toContain(1330)
        ->and($excluded)->not->toContain(703);
});
