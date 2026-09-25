<?php

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideSectionKind;
use App\Http\Services\SpellSynergyService;
use App\Livewire\Guides\Builder;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellEffect;
use App\Models\SpellRelationship;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideMember;
use App\Models\UserGuideSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The Synergy section: one ability and the talents that change it.
 *
 * The suggestion list comes from real imported spell_relationships, which a fixture can build
 * directly. What is asserted here is the behaviour that has to hold whatever data is loaded — and
 * in particular that a modifier cannot be attached from a hand-crafted request, the same guard
 * addSpell() has against an arbitrary palette pick.
 */
function synergyFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.1.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Discipline', 'slug' => 'discipline']);
    // A real username, because the guides.show binding resolves a slug per AUTHOR and looks the
    // author up by username (CLAUDE.md rule 29). Without one, handle() falls back to the name and
    // the route 404s.
    $user = User::factory()->create(['username' => 'synergyauthor']);

    $guide = UserGuide::create([
        'user_id' => $user->id, 'status' => 'draft', 'visibility' => 'invited', 'title' => 'Synergy guide',
    ]);
    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => $spec->id]);

    $section = UserGuideSection::create([
        'user_guide_id' => $guide->id,
        'kind' => UserGuideSectionKind::Synergy,
        'title' => 'How this ability changes',
        'row' => 0, 'column' => 0,
        'created_by_user_id' => $user->id,
    ]);

    $subject = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 47540, 'name' => 'Penance',
        'is_passive' => false, 'not_in_spellbook' => false, 'cooldown_seconds' => 9,
        // The formula's own talent — the only way Power of the Dark Side is ever visible.
        'variables' => '$darkside=$?a198069[${1+($198069s1/100)}][${1}]'."\n".'$dmg=${$s1*$<darkside>}',
        'description' => 'Causes $<dmg> Holy damage.',
    ]);
    SpellEffect::create(['spell_id' => $subject->id, 'effect_index' => 1, 'base_value' => 0, 'scaled_value' => 0, 'sp_coefficient' => 0.932, 'type' => 'School Damage (2): holy']);
    SpellClassAvailability::create(['spell_id' => $subject->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'baseline']);

    $darkside = Spell::create(['patch_id' => $patch->id, 'spell_id' => 198069, 'name' => 'Power of the Dark Side']);
    SpellEffect::create(['spell_id' => $darkside->id, 'effect_index' => 1, 'base_value' => 30, 'scaled_value' => 30, 'type' => 'Dummy']);
    SpellClassAvailability::create(['spell_id' => $darkside->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'baseline']);

    // A structural modifier with a real magnitude — the other source the section draws on.
    $waste = Spell::create(['patch_id' => $patch->id, 'spell_id' => 999500, 'name' => 'Waste No Time']);
    SpellClassAvailability::create(['spell_id' => $waste->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'talent']);
    SpellRelationship::create([
        'source_spell_id' => $waste->id,
        'target_spell_id' => $subject->id,
        'relationship_type' => 'modifies_cooldown',
        'modifier_value' => -1.5,
        'modifier_unit' => 'seconds',
    ]);

    return compact('game', 'patch', 'class', 'spec', 'user', 'guide', 'section', 'subject', 'darkside', 'waste');
}

function synergySubject(array $f): UserGuideBlock
{
    return UserGuideBlock::create([
        'user_guide_section_id' => $f['section']->id,
        'position' => 0,
        'block_type' => UserGuideBlockType::Spell,
        'payload' => ['external_spell_id' => 47540, 'source_spec_id' => $f['spec']->id, 'role' => 'subject'],
        'added_by_user_id' => $f['user']->id,
    ]);
}

test('the suggestion list names both kinds of fact about the same ability', function () {
    $f = synergyFixture();
    synergySubject($f);

    $rows = collect(app(SpellSynergyService::class)->rowsForSection($f['section']->fresh()));

    $byName = $rows->keyBy('name');

    // A structural relationship, with the magnitude the dump recorded.
    expect($byName['Waste No Time']['effect'])->toBe('Changes its cooldown')
        ->and($byName['Waste No Time']['magnitude'])->toBe('-1.5 seconds')
        ->and($byName['Waste No Time']['source'])->toBe('relationship');

    // And the formula term, which has no spell_relationships row at all.
    expect($byName['Power of the Dark Side']['source'])->toBe('formula')
        ->and($byName['Power of the Dark Side']['effect'])->toBe('A term in its damage formula')
        ->and($byName['Power of the Dark Side']['magnitude'])->toBeNull();
});

test('a section with no subject yet suggests nothing', function () {
    $f = synergyFixture();

    expect(app(SpellSynergyService::class)->rowsForSection($f['section']))->toBe([]);
});

test('the author attaches a suggested talent, and can only attach a suggested one', function () {
    $f = synergyFixture();
    synergySubject($f);

    $component = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']]);

    $component->call('addSynergyModifier', $f['section']->id, 198069);

    $modifiers = $f['section']->blocks()->get()
        ->filter(fn ($b) => ($b->payload['role'] ?? null) === 'modifier');

    expect($modifiers)->toHaveCount(1)
        ->and((int) $modifiers->first()->payload['external_spell_id'])->toBe(198069);

    // Nothing our data connects to this ability gets in, however the request is shaped — the same
    // guard addSpell() has against an arbitrary palette pick.
    $component->call('addSynergyModifier', $f['section']->id, 12345);
    expect($f['section']->blocks()->count())->toBe(2);

    // And the same talent twice is a no-op rather than a duplicate row.
    $component->call('addSynergyModifier', $f['section']->id, 198069);
    expect($f['section']->blocks()->count())->toBe(2);
});

test('a modifier cannot be pushed into a section of another kind, or another author guide', function () {
    $f = synergyFixture();
    synergySubject($f);

    $sequence = UserGuideSection::create([
        'user_guide_id' => $f['guide']->id,
        'kind' => UserGuideSectionKind::Sequence,
        'title' => 'A sequence', 'row' => 1, 'column' => 0,
        'created_by_user_id' => $f['user']->id,
    ]);

    $component = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']]);
    $component->call('addSynergyModifier', $sequence->id, 198069);

    expect($sequence->blocks()->count())->toBe(0);

    // A stranger cannot even mount the builder, so the section is unreachable through it.
    Livewire::actingAs(User::factory()->create())
        ->test(Builder::class, ['guide' => $f['guide']])
        ->assertForbidden();

    expect($f['section']->blocks()->count())->toBe(1);
});

test('a second palette pick is refused once the section has its subject', function () {
    $f = synergyFixture();
    synergySubject($f);

    // The refusal happens before offersSpell() builds the palette — there is nothing to validate
    // once the section already has the one ability it is about. That ordering is what makes this
    // assertable without a real imported kit, and it also saves building a palette to say no.
    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])
        ->call('addSpell', $f['section']->id, 198069, $f['spec']->id);

    expect($f['section']->blocks()->count())->toBe(1)
        ->and($f['section']->blocks()->first()->payload['role'])->toBe('subject');
});

test('both pages render over HTTP with a synergy section on them', function () {
    $f = synergyFixture();
    synergySubject($f);

    UserGuideBlock::create([
        'user_guide_section_id' => $f['section']->id,
        'position' => 1,
        'block_type' => UserGuideBlockType::Spell,
        'payload' => [
            'external_spell_id' => 198069,
            'source_spec_id' => $f['spec']->id,
            'role' => 'modifier',
            'note' => 'Shield twice before the go.',
        ],
        'added_by_user_id' => $f['user']->id,
    ]);

    // A full-page GET, not only a Livewire render: a component missing its ->layout() call passes
    // every Livewire assertion and 500s at the real URL.
    $this->actingAs($f['user'])
        ->get(route('guides.edit', $f['guide']->slug))
        ->assertOk()
        ->assertSee('Penance');

    $f['guide']->forceFill(['status' => 'published', 'visibility' => 'public'])->save();

    $this->get(route('guides.show', ['username' => $f['user']->handle(), 'guide' => $f['guide']->slug]))
        ->assertOk()
        ->assertSee('Penance')
        // The author's own note, and the fact the data supplies beside it.
        ->assertSee('Shield twice before the go.')
        ->assertSee('A term in its damage formula');
});
