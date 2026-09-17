<?php

use App\Enums\FriendshipStatus;
use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideMemberSide;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Http\Services\UserGuideChainService;
use App\Livewire\Guides\Builder;
use App\Livewire\Guides\Index;
use App\Models\Friendship;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\TalentBuildChoice;
use App\Models\TalentBuildPvpChoice;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideMember;
use App\Models\UserGuideSection;
use Livewire\Livewire;

/**
 * The enemy-abilities section (the old "Defensives to force", broadened 2026-09-14 to the
 * opponent's CC, interrupts and offensive cooldowns) and duplicating a guide to reuse it.
 *
 * The palette's CONTENTS need a real imported kit and are verified against the live database
 * (see CLAUDE.md); what is asserted here is everything that does not: how an enemy section
 * resolves its steps, the builder's controls for it, and exactly what a duplicate carries over.
 */
function enemyPatch(): Patch
{
    return Patch::firstOrCreate(
        ['build_version' => '12.0.0-enemy-test'],
        ['game_id' => Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft'])->id, 'is_current' => true],
    );
}

function enemySpec(string $class = 'rogue', string $slug = 'subtlety'): Specialization
{
    $gameClass = GameClass::firstOrCreate(
        ['slug' => $class],
        ['game_id' => Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft'])->id, 'name' => ucfirst($class)],
    );

    return Specialization::firstOrCreate(
        ['class_id' => $gameClass->id, 'slug' => $slug],
        ['name' => ucfirst($slug), 'external_spec_id' => crc32($class.$slug) % 100000],
    );
}

function enemyGuide(User $user, array $attrs = []): UserGuide
{
    return UserGuide::create(array_merge(['user_id' => $user->id, 'title' => 'Jungle vs RMP'], $attrs));
}

function enemyStep(UserGuideSection $section, int $position, int $externalId, Specialization $spec, ?string $note = null): UserGuideBlock
{
    return UserGuideBlock::create([
        'user_guide_section_id' => $section->id,
        'position' => $position,
        'block_type' => UserGuideBlockType::Spell,
        'payload' => array_filter(['external_spell_id' => $externalId, 'source_spec_id' => $spec->id, 'note' => $note]),
    ]);
}

// ------------------------------------------------------------------ the enemy section

test('an enemy section is a list of threats: no DR is tallied across it, and CC shows its full duration', function () {
    $rogue = enemySpec();
    foreach ([[408, 'Kidney Shot', 5], [1833, 'Cheap Shot', 4]] as [$id, $name, $dur]) {
        Spell::create(['patch_id' => enemyPatch()->id, 'spell_id' => $id, 'name' => $name,
            'dr_category' => 'Stun', 'cast_type' => 'instant', 'pvp_duration_seconds' => $dur]);
    }

    $guide = enemyGuide(User::factory()->create());
    $enemy = UserGuideSection::create(['user_guide_id' => $guide->id, 'kind' => UserGuideSectionKind::Defensives, 'title' => 'Watch out for', 'row' => 0, 'column' => 0]);
    $sequence = UserGuideSection::create(['user_guide_id' => $guide->id, 'kind' => UserGuideSectionKind::Sequence, 'title' => 'The go', 'row' => 1, 'column' => 0]);
    foreach ([$enemy, $sequence] as $section) {
        enemyStep($section, 0, 408, $rogue);
        enemyStep($section, 1, 1833, $rogue);
    }

    $svc = app(UserGuideChainService::class);
    $enemySteps = $svc->resolve($enemy->fresh());
    $goSteps = $svc->resolve($sequence->fresh());

    // The same two stuns: separate threats on the enemy side, one chain on yours.
    expect(array_column(array_column($enemySteps, 'dr'), 'dr_percentage'))->toBe([100, 100])
        ->and(array_column($enemySteps, 'duration'))->toBe([5.0, 4.0])
        ->and(array_column(array_column($goSteps, 'dr'), 'dr_percentage'))->toBe([100, 50]);
});

test('an enemy section is labelled for what it now holds, and new ones start as "Watch out for"', function () {
    expect(UserGuideSectionKind::Defensives->value)->toBe('defensives')
        ->and(UserGuideSectionKind::Defensives->label())->toBe('Enemy abilities')
        ->and(UserGuideSectionKind::Defensives->tracksControl())->toBeFalse();

    $user = User::factory()->create();
    $guide = enemyGuide($user);

    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])->call('addSection', 'defensives');

    expect($guide->sections()->first()->title)->toBe('Watch out for');
});

test('with an enemy team named, an enemy section draws from the whole team and can be narrowed and widened again', function () {
    $user = User::factory()->create();
    $guide = enemyGuide($user);
    $mage = enemySpec('mage', 'frost');
    foreach ([enemySpec(), $mage] as $i => $spec) {
        UserGuideMember::create(['user_guide_id' => $guide->id, 'side' => UserGuideMemberSide::Enemy, 'position' => $i, 'spec_id' => $spec->id]);
    }
    // Your own side too: the builder shows nothing past the comp picker until the guide has one.
    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => enemySpec('priest', 'discipline')->id]);
    $section = UserGuideSection::create(['user_guide_id' => $guide->id, 'kind' => UserGuideSectionKind::Defensives, 'title' => 'Watch out for', 'row' => 0, 'column' => 0]);

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])
        ->assertSee('Their team')
        ->assertSee('Narrow to one spec')
        // No totals on a threats list.
        ->assertDontSee('Available every')
        ->call('openOpponentPicker', $section->id)
        ->call('setOpponent', $mage->id);

    expect($section->fresh()->opponent_spec_id)->toBe($mage->id);
    $c->assertSee('Show their whole team');

    $c->call('clearOpponent', $section->id);
    expect($section->fresh()->opponent_spec_id)->toBeNull();
});

test('clearOpponent does nothing to a sequence section or to a section in another guide', function () {
    $user = User::factory()->create();
    $guide = enemyGuide($user);
    $mine = UserGuideSection::create(['user_guide_id' => $guide->id, 'kind' => UserGuideSectionKind::Sequence, 'title' => 'Go', 'row' => 0, 'column' => 0, 'opponent_spec_id' => enemySpec()->id]);
    $other = enemyGuide(User::factory()->create());
    $theirs = UserGuideSection::create(['user_guide_id' => $other->id, 'kind' => UserGuideSectionKind::Defensives, 'title' => 'W', 'row' => 0, 'column' => 0, 'opponent_spec_id' => enemySpec()->id]);

    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])
        ->call('clearOpponent', $mine->id)
        ->call('clearOpponent', $theirs->id);

    expect($mine->fresh()->opponent_spec_id)->not->toBeNull()
        ->and($theirs->fresh()->opponent_spec_id)->not->toBeNull();
});

// ------------------------------------------------------------------ duplicating a guide

/** A published, liked, shared guide with both sides of the roster, talent builds and steps. */
function richGuide(User $author): array
{
    $patch = enemyPatch();
    $team = enemySpec('hunter', 'beast-mastery');
    $enemy = enemySpec();

    $tree = TalentTree::create(['patch_id' => $patch->id, 'class_id' => $team->class_id, 'spec_id' => $team->id, 'type' => 'spec', 'name' => 'BM', 'external_tree_id' => 1]);
    $spell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 19574, 'name' => 'Bestial Wrath']);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 1, 'type' => 'ACTIVE', 'max_ranks' => 1]);
    $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 1, 'max_rank' => 1]);
    $pvpTalent = PvpTalent::create(['spec_id' => $team->id, 'patch_id' => $patch->id, 'spell_id' => $spell->id, 'external_pvp_talent_id' => 1]);

    $build = TalentBuild::create(['spec_id' => $team->id, 'patch_id' => $patch->id, 'name' => 'Guide build', 'share_slug' => 'orig-build']);
    TalentBuildChoice::create(['talent_build_id' => $build->id, 'talent_node_id' => $node->id, 'chosen_entry_id' => $entry->id, 'rank' => 1]);
    TalentBuildPvpChoice::create(['talent_build_id' => $build->id, 'slot' => 1, 'pvp_talent_id' => $pvpTalent->id]);

    $guide = enemyGuide($author, [
        'type' => UserGuideType::Comp,
        'summary' => 'Kill the mage in the first go.',
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
        'published_at' => now()->subDay(),
        'friends_can_edit' => true,
    ]);
    $guide->forceFill(['like_count' => 7, 'view_count' => 90])->save();
    $guide->viewers()->attach(User::factory()->create()->id);

    UserGuideMember::create(['user_guide_id' => $guide->id, 'side' => UserGuideMemberSide::Team, 'position' => 0, 'spec_id' => $team->id, 'talent_build_id' => $build->id]);
    UserGuideMember::create(['user_guide_id' => $guide->id, 'side' => UserGuideMemberSide::Enemy, 'position' => 0, 'spec_id' => $enemy->id]);
    $guide->syncCompKey();

    $go = UserGuideSection::create(['user_guide_id' => $guide->id, 'kind' => UserGuideSectionKind::Sequence, 'title' => 'The go', 'row' => 0, 'column' => 0]);
    $watch = UserGuideSection::create(['user_guide_id' => $guide->id, 'kind' => UserGuideSectionKind::Defensives, 'title' => 'Watch out for', 'row' => 0, 'column' => 1, 'opponent_spec_id' => $enemy->id]);
    $notes = UserGuideSection::create(['user_guide_id' => $guide->id, 'kind' => UserGuideSectionKind::Text, 'title' => 'Notes', 'row' => 1, 'column' => 0, 'body' => 'Trade **Wall** for Kidney.']);
    enemyStep($go, 0, 19574, $team, 'On the swap');
    enemyStep($go, 1, 408, $team);
    enemyStep($watch, 0, 408, $enemy);

    return compact('guide', 'build', 'team', 'enemy');
}

test('duplicating copies the whole plan into a new private draft, and leaves the original alone', function () {
    $author = User::factory()->create();
    ['guide' => $original, 'build' => $build, 'enemy' => $enemy] = richGuide($author);

    $c = Livewire::actingAs($author)->test(Index::class)->call('duplicate', $original->id);

    $copy = UserGuide::where('user_id', $author->id)->whereKeyNot($original->id)->sole();
    $c->assertRedirect(route('guides.edit', $copy->slug));

    // The plan came across...
    expect($copy->title)->toBe('Jungle vs RMP (copy)')
        ->and($copy->summary)->toBe('Kill the mage in the first go.')
        ->and($copy->comp_key)->toBe($original->comp_key)
        ->and($copy->members()->pluck('spec_id')->all())->toBe($original->members()->pluck('spec_id')->all())
        ->and($copy->enemies()->pluck('spec_id')->all())->toBe([$enemy->id]);

    $sections = $copy->sections()->with('blocks')->get();
    expect($sections->map(fn ($s) => [$s->kind, $s->title, $s->row, $s->column])->all())->toBe([
        [UserGuideSectionKind::Sequence, 'The go', 0, 0],
        [UserGuideSectionKind::Defensives, 'Watch out for', 0, 1],
        [UserGuideSectionKind::Text, 'Notes', 1, 0],
    ])
        ->and($sections[1]->opponent_spec_id)->toBe($enemy->id)
        ->and($sections[2]->body)->toBe('Trade **Wall** for Kidney.')
        ->and($sections[0]->blocks->map(fn ($b) => $b->externalSpellId())->all())->toBe([19574, 408])
        ->and($sections[0]->blocks[0]->note())->toBe('On the swap')
        ->and($sections->pluck('created_by_user_id')->unique()->all())->toBe([$author->id]);

    // ...everything about the original being out in the world did not.
    expect($copy->status)->toBe(UserGuideStatus::Draft)
        ->and($copy->visibility)->toBe(UserGuideVisibility::Invited)
        ->and($copy->published_at)->toBeNull()
        ->and($copy->friends_can_edit)->toBeFalse()
        ->and((int) $copy->like_count)->toBe(0)
        ->and((int) $copy->view_count)->toBe(0)
        ->and($copy->viewers()->count())->toBe(0);

    // The talent build is a copy, not the same row — editing one must not edit the other.
    $copiedBuild = $copy->members()->first()->talentBuild;
    expect($copiedBuild->id)->not->toBe($build->id)
        ->and($copiedBuild->user_id)->toBeNull()
        ->and($copiedBuild->is_default)->toBeFalse()
        ->and($copiedBuild->choices->pluck('chosen_entry_id')->all())->toBe($build->choices->pluck('chosen_entry_id')->all())
        ->and($copiedBuild->pvpChoices->pluck('pvp_talent_id')->all())->toBe($build->pvpChoices->pluck('pvp_talent_id')->all());

    $copiedBuild->choices()->delete();
    expect($build->fresh()->choices()->count())->toBe(1)
        ->and($original->fresh()->sections()->count())->toBe(3)
        ->and($original->fresh()->status)->toBe(UserGuideStatus::Published);
});

test('a reader sees an enemy section with its label and whose abilities it lists, and no totals', function () {
    $author = User::factory()->create();
    $author->forceFill(['username' => 'jungler'])->save();
    ['guide' => $guide] = richGuide($author);

    $this->get($guide->publicUrl())
        ->assertOk()
        ->assertSee('Enemy abilities')
        ->assertSee('Watch out for')
        ->assertSee('Subtlety Rogue')
        ->assertDontSee('Available every');
});

test('the copy opens in the builder for its author', function () {
    $author = User::factory()->create();
    ['guide' => $original] = richGuide($author);

    Livewire::actingAs($author)->test(Builder::class, ['guide' => $original])->call('duplicate');
    $copy = UserGuide::where('user_id', $author->id)->whereKeyNot($original->id)->sole();

    $this->actingAs($author)->get(route('guides.edit', $copy->slug))
        ->assertOk()
        ->assertSee('Jungle vs RMP (copy)');
});

test('you can only duplicate your own guides', function () {
    $author = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::create(['requester_id' => $author->id, 'addressee_id' => $friend->id, 'status' => FriendshipStatus::Accepted, 'accepted_at' => now()]);
    ['guide' => $original] = richGuide($author);

    // From My Guides, an id that isn't yours matches nothing.
    Livewire::actingAs($friend)->test(Index::class)->call('duplicate', $original->id);
    // A collaborator can edit the guide, but copying it into their own account is not theirs to do.
    Livewire::actingAs($friend)->test(Builder::class, ['guide' => $original])->call('duplicate');

    expect(UserGuide::count())->toBe(1);
});

test('a long title still fits after " (copy)" is added', function () {
    $author = User::factory()->create();
    $guide = enemyGuide($author, ['title' => str_repeat('a', 120)]);

    $copy = app(\App\Http\Services\UserGuideDuplicator::class)->duplicate($guide, $author);

    expect(mb_strlen($copy->title))->toBeLessThanOrEqual(120)
        ->and($copy->title)->toEndWith(' (copy)');
});
