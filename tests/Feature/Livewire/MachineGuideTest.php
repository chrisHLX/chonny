<?php

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Livewire\Guides\Browse;
use App\Livewire\Guides\MachineGuides;
use App\Livewire\Guides\Show;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideComment;
use App\Models\UserGuideSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Guides a model wrote, and criticism attached to one step of one.
 *
 * The two things worth guarding here are both about trust rather than mechanics: a machine draft
 * must never be presented as a player's plan, and it must never appear in the player listings —
 * see Guides\MachineGuides for why that separation is the point rather than tidiness.
 */

function machineGuideFixture(array $attrs = []): UserGuide
{
    // One machine account, reused — two guides by it is the normal case, and usernames are unique.
    $author = User::where('username', 'mindcollector')->first();

    if (! $author) {
        $author = User::factory()->create();
        $author->forceFill(['username' => 'mindcollector'])->save();
    }

    return UserGuide::create(array_merge([
        'user_id' => $author->id,
        'authored_by_model' => 'Claude Opus 5',
        'title' => 'Jungle vs Ret War',
        'slug' => 'jungle-vs-ret-war',
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
        'published_at' => now(),
    ], $attrs));
}

test('a machine guide says who drafted it and never claims a player wrote it', function () {
    $guide = machineGuideFixture();

    $html = Livewire::test(Show::class, ['username' => 'mindcollector', 'guide' => $guide])->html();

    expect($html)->toContain('Claude Opus 5')
        ->and($html)->toContain('drafted by a model')
        ->and($html)->not->toContain('Player-written guide');
});

test('machine guides are kept out of the player listing and have their own page', function () {
    $machine = machineGuideFixture();

    $player = User::factory()->create();
    $player->forceFill(['username' => 'realplayer'])->save();
    $human = UserGuide::create([
        'user_id' => $player->id,
        'title' => 'My own opener',
        'slug' => 'my-own-opener',
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
        'published_at' => now(),
    ]);

    Livewire::test(Browse::class)
        ->assertSee('My own opener')
        ->assertDontSee('Jungle vs Ret War');

    Livewire::test(MachineGuides::class)
        ->assertSee('Jungle vs Ret War')
        ->assertDontSee('My own opener');

    expect(UserGuide::machineAuthored()->pluck('id')->all())->toBe([$machine->id])
        ->and(UserGuide::humanAuthored()->pluck('id')->all())->toBe([$human->id]);
});

test('a reader can attach a note to one step, and it is not the guide-level thread', function () {
    $guide = machineGuideFixture();
    $section = UserGuideSection::create([
        'user_guide_id' => $guide->id,
        'kind' => UserGuideSectionKind::Sequence,
        'title' => 'Control the healer',
        'row' => 0, 'column' => 0,
    ]);
    $block = UserGuideBlock::create([
        'user_guide_section_id' => $section->id,
        'position' => 1,
        'block_type' => UserGuideBlockType::Spell,
        'payload' => ['external_spell_id' => 408],
    ]);

    $reader = User::factory()->create();
    $reader->forceFill(['username' => 'critic'])->save();

    Livewire::actingAs($reader)->test(Show::class, ['username' => 'mindcollector', 'guide' => $guide])
        ->call('startNote', 'block:'.$block->id)
        ->set('note', 'This does not force Pain Suppression, Disc just shields it.')
        ->call('postNote');

    $note = UserGuideComment::first();

    expect($note->user_guide_block_id)->toBe($block->id)
        // The section is recorded too, so an export can group notes under the part they are about.
        ->and($note->user_guide_section_id)->toBe($section->id);

    // The guide-level thread is only for un-anchored comments — an anchored note appears against
    // its step instead, and counting it in both places would double it.
    $component = Livewire::actingAs($reader)->test(Show::class, ['username' => 'mindcollector', 'guide' => $guide]);
    expect($component->get('comments')->count())->toBe(0)
        ->and($component->get('notesByAnchor')->keys()->all())->toBe(['block:'.$block->id]);
});

test('a note cannot be attached to a step belonging to another guide', function () {
    $guide = machineGuideFixture();

    $other = machineGuideFixture(['slug' => 'other-guide', 'title' => 'Other']);
    $otherSection = UserGuideSection::create([
        'user_guide_id' => $other->id,
        'kind' => UserGuideSectionKind::Sequence,
        'title' => 'Theirs', 'row' => 0, 'column' => 0,
    ]);
    $otherBlock = UserGuideBlock::create([
        'user_guide_section_id' => $otherSection->id,
        'position' => 1,
        'block_type' => UserGuideBlockType::Spell,
        'payload' => ['external_spell_id' => 408],
    ]);

    $reader = User::factory()->create();

    Livewire::actingAs($reader)->test(Show::class, ['username' => 'mindcollector', 'guide' => $guide])
        ->call('startNote', 'block:'.$otherBlock->id)
        ->set('note', 'tampered')
        ->call('postNote');

    expect(UserGuideComment::count())->toBe(0);
});

test('a signed-out reader is told to sign in rather than silently losing the note', function () {
    $guide = machineGuideFixture();
    $section = UserGuideSection::create([
        'user_guide_id' => $guide->id,
        'kind' => UserGuideSectionKind::Sequence,
        'title' => 'x', 'row' => 0, 'column' => 0,
    ]);

    Livewire::test(Show::class, ['username' => 'mindcollector', 'guide' => $guide])
        ->call('startNote', 'section:'.$section->id)
        ->set('note', 'wrong')
        ->call('postNote')
        ->assertSet('feedbackError', 'Sign in to add a note.');

    expect(UserGuideComment::count())->toBe(0);
});
