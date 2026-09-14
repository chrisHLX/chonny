<?php

use App\Enums\FriendshipStatus;
use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Http\Services\SpellChangeRecorder;
use App\Livewire\Home;
use App\Models\Friendship;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Guild;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellChange;
use App\Models\SpellDataUpdate;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideSection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The Home feed: which guides a player sees (the same rule as UserGuide::isReadableBy(), as a
 * query), how an item reads, and game-data updates recorded by SpellChangeRecorder.
 */
function feedUser(string $username): User
{
    $user = User::factory()->create(['name' => ucfirst($username)]);
    $user->forceFill(['username' => $username])->save();

    return $user;
}

function feedGuide(User $owner, string $title, array $attrs = []): UserGuide
{
    $guide = UserGuide::create(array_merge([
        'user_id' => $owner->id,
        'title' => $title,
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
        'published_at' => now(),
    ], $attrs));

    UserGuideSection::create([
        'user_guide_id' => $guide->id,
        'kind' => UserGuideSectionKind::Sequence,
        'title' => 'The opener',
        'row' => 0,
        'column' => 0,
    ]);

    return $guide;
}

function feedWorld(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.7.68453', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);

    return ['patch' => $patch, 'class' => $class];
}

function feedTitles(User $viewer, string $scope = 'everyone'): array
{
    $component = Livewire::actingAs($viewer)->test(Home::class);
    if ($scope !== 'everyone') {
        $component->call('setFeedScope', $scope);
    }

    return collect($component->instance()->feed['items'])
        ->where('type', 'guide')
        ->map(fn ($i) => $i['guide']->title)
        ->values()
        ->all();
}

// ------------------------------------------------------------------ who sees what

test('Everyone shows public guides, your own, and ones shared with your guild or with you — never drafts', function () {
    [$me, $author, $mate] = [feedUser('me'), feedUser('author'), feedUser('mate')];
    $guild = Guild::create(['owner_id' => $mate->id, 'name' => 'Team']);
    $guild->join($mate);
    $guild->join($me);
    $otherGuild = Guild::create(['owner_id' => $author->id, 'name' => 'Elsewhere']);
    $otherGuild->join($author);

    feedGuide($author, 'Public plan');
    feedGuide($author, 'Draft plan', ['status' => UserGuideStatus::Draft, 'published_at' => null]);
    feedGuide($author, 'Private plan', ['visibility' => UserGuideVisibility::Invited]);
    feedGuide($author, 'Other guild plan', ['visibility' => UserGuideVisibility::Guild, 'guild_id' => $otherGuild->id]);
    feedGuide($mate, 'Our guild plan', ['visibility' => UserGuideVisibility::Guild, 'guild_id' => $guild->id]);
    feedGuide($author, 'Shared with me', ['visibility' => UserGuideVisibility::Invited])->viewers()->attach($me->id);
    feedGuide($me, 'My published plan', ['visibility' => UserGuideVisibility::Invited]);

    $titles = feedTitles($me);

    expect($titles)->toContain('Public plan', 'Our guild plan', 'Shared with me', 'My published plan')
        ->not->toContain('Draft plan', 'Private plan', 'Other guild plan');
});

test('Friends & guilds shows only the people you play with', function () {
    [$me, $friend, $stranger] = [feedUser('me'), feedUser('friend'), feedUser('stranger')];
    Friendship::create([
        'requester_id' => $me->id, 'addressee_id' => $friend->id,
        'status' => FriendshipStatus::Accepted, 'accepted_at' => now(),
    ]);

    feedGuide($friend, 'Friend plan');
    feedGuide($stranger, 'Stranger plan');
    feedGuide($me, 'My plan');

    expect(feedTitles($me, 'circle'))->toBe(['Friend plan']);
});

test('an unknown feed scope is ignored', function () {
    Livewire::actingAs(feedUser('me'))->test(Home::class)
        ->call('setFeedScope', 'everything-please')
        ->assertSet('feedScope', 'everyone');
});

// ------------------------------------------------------------------ analytics

test('feed tab switches and "Show more" are counted, re-clicks are not, and the admin can see them', function () {
    $me = feedUser('me');

    Livewire::actingAs($me)->test(Home::class)
        ->call('setFeedScope', 'everyone')   // already on it — not counted
        ->call('setFeedScope', 'circle')
        ->call('setFeedScope', 'circle')     // re-click — not counted
        ->call('loadMore');

    expect(\App\Models\PageViewEvent::where('page', 'home_feed')->where('slot', 'circle')->count())->toBe(1)
        ->and(\App\Models\PageViewEvent::where('page', 'home_feed')->where('slot', 'everyone')->count())->toBe(0)
        ->and(\App\Models\PageViewEvent::where('page', 'home_feed')->where('slot', 'more')->count())->toBe(1);

    $admin = feedUser('admin');
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)->get(route('admin.page-usage'))
        ->assertOk()
        ->assertSee('Home feed &amp; support', false)
        ->assertSee('Home (signed-in landing)');

    $this->actingAs($me)->get(route('admin.page-usage'))->assertForbidden();
});

test('Buy me a coffee is hidden until configured, then counted and redirected', function () {
    config(['services.buymeacoffee.url' => null]);
    $this->get(route('guides.browse'))->assertDontSee('Buy me a coffee');
    $this->get(route('support'))->assertNotFound();

    config(['services.buymeacoffee.url' => 'https://www.buymeacoffee.com/example']);
    $this->get(route('guides.browse'))->assertSee('Buy me a coffee');
    $this->get(route('support'))->assertRedirect('https://www.buymeacoffee.com/example');

    expect(\App\Models\PageViewEvent::where('page', 'support_click')->count())->toBe(1);
});

// ------------------------------------------------------------------ how an item reads

test('a feed item says who published it and previews the plan as ability icons', function () {
    $w = feedWorld();
    Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 408, 'name' => 'Kidney Shot']);
    Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 118, 'name' => 'Polymorph']);

    $author = feedUser('author');
    $guide = feedGuide($author, 'RMP opener');
    $section = $guide->sections()->first();
    foreach ([408, 118] as $position => $id) {
        UserGuideBlock::create([
            'user_guide_section_id' => $section->id,
            'position' => $position,
            'block_type' => UserGuideBlockType::Spell,
            'payload' => ['external_spell_id' => $id, 'source_spec_id' => null],
        ]);
    }

    $this->actingAs(feedUser('reader'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('RMP opener')
        ->assertSee('published')
        ->assertSee('The opener')
        // Test spells have no icon file, so <x-spell-icon> renders its placeholder — which still
        // carries the title, in order.
        ->assertSeeInOrder(['title="Kidney Shot"', 'title="Polymorph"'], false);
});

test('a guide edited well after it was published reads as updated, credited to whoever edited it', function () {
    [$author, $bob] = [feedUser('author'), feedUser('bob')];
    $guide = feedGuide($author, 'Team plan', ['published_at' => now()->subDays(2)]);
    $guide->forceFill(['last_edited_by_user_id' => $bob->id, 'updated_at' => now()])->saveQuietly();

    $item = collect(Livewire::actingAs(feedUser('reader'))->test(Home::class)->instance()->feed['items'])->first();

    expect($item['verb'])->toBe('updated')
        ->and($item['actor']->id)->toBe($bob->id)
        ->and($item['byCollaborator'])->toBeTrue();
});

// ------------------------------------------------------------------ game-data updates

test('a game-data update appears in Everyone with its numeric changes, and not in Friends & guilds', function () {
    $w = feedWorld();
    $spell = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 33206, 'name' => 'Pain Suppression']);
    $update = SpellDataUpdate::create(['patch_id' => $w['patch']->id, 'build_version' => '12.0.7.68453', 'changed_spell_count' => 1]);
    SpellChange::create(['spell_data_update_id' => $update->id, 'spell_id' => $spell->id, 'field' => 'cooldown_seconds', 'old_value' => '180', 'new_value' => '150']);

    $me = feedUser('me');

    $this->actingAs($me)->get(route('dashboard'))
        ->assertSee('Game data update')
        ->assertSee('1 ability changed')
        ->assertSee('Pain Suppression')
        ->assertSee('180s')
        ->assertSee('150s');

    $circle = Livewire::actingAs($me)->test(Home::class)->call('setFeedScope', 'circle')->instance()->feed['items'];
    expect(collect($circle)->where('type', 'data'))->toBeEmpty();
});

test('a run that rewrote more than a hundred tooltips and nothing else is not shown as a patch', function () {
    $w = feedWorld();
    $update = SpellDataUpdate::create(['patch_id' => $w['patch']->id, 'changed_spell_count' => 101]);
    foreach (range(1, 101) as $i) {
        $spell = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 90000 + $i, 'name' => "Spell {$i}"]);
        SpellChange::create(['spell_data_update_id' => $update->id, 'spell_id' => $spell->id, 'field' => 'description', 'old_value' => 'a', 'new_value' => 'b']);
    }

    $items = Livewire::actingAs(feedUser('me'))->test(Home::class)->instance()->feed['items'];

    expect(collect($items)->where('type', 'data'))->toBeEmpty();
});

// ------------------------------------------------------------------ SpellChangeRecorder

test('the recorder keeps real changes to pressable spells and nothing else', function () {
    $w = feedWorld();
    $visible = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 1, 'name' => 'Kidney Shot', 'cooldown_seconds' => 30]);
    $hidden = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 2, 'name' => 'Hidden aura', 'cooldown_seconds' => 30]);
    $sameValue = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 3, 'name' => 'Cheap Shot', 'cooldown_seconds' => 20]);
    foreach ([$visible, $sameValue] as $s) {
        DB::table('spell_class_availability')->insert([
            'spell_id' => $s->id, 'class_id' => $w['class']->id, 'spec_id' => null,
            'source' => 'verified_override', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $recorder = new SpellChangeRecorder;

    $visible->fill(['cooldown_seconds' => 20]);
    $recorder->capture($visible);
    $visible->save();

    $hidden->fill(['cooldown_seconds' => 10]);
    $recorder->capture($hidden);

    // "20.00" vs 20 is the same cooldown, not a change.
    $sameValue->fill(['cooldown_seconds' => '20.00']);
    $recorder->capture($sameValue);

    // A spell being created is not a change.
    $recorder->capture(new Spell(['patch_id' => $w['patch']->id, 'spell_id' => 4, 'name' => 'New']));

    $update = $recorder->flush($w['patch']->id, '12.0.7.68453');

    expect($update)->not->toBeNull()
        ->and($update->changed_spell_count)->toBe(1)
        ->and($update->changes)->toHaveCount(1)
        ->and($update->changes->first()->spell_id)->toBe($visible->id)
        ->and($update->changes->first()->old_value)->toBe('30')
        ->and($update->changes->first()->new_value)->toBe('20');
});

test('the recorder writes nothing when only hidden records changed', function () {
    $w = feedWorld();
    $hidden = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 2, 'name' => 'Hidden aura', 'cooldown_seconds' => 30]);

    $recorder = new SpellChangeRecorder;
    $hidden->fill(['cooldown_seconds' => 10]);
    $recorder->capture($hidden);

    expect($recorder->flush($w['patch']->id, 'x'))->toBeNull()
        ->and(SpellDataUpdate::count())->toBe(0);
});
