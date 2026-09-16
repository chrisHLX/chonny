<?php

use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Livewire\Home;
use App\Livewire\Landing;
use App\Models\Game;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellChange;
use App\Models\SpellDataUpdate;
use App\Models\User;
use App\Models\UserGuide;
use Livewire\Livewire;

/**
 * The public front page at '/' (App\Livewire\Landing) and the visitor rules GuideFeed applies there:
 * public guides only, no tooltip-only data updates, at most two items per author, and popular
 * guides topping up a thin stream.
 */
function landingUser(string $username): User
{
    $user = User::factory()->create(['name' => ucfirst($username)]);
    $user->forceFill(['username' => $username])->save();

    return $user;
}

function landingGuide(User $owner, string $title, array $attrs = []): UserGuide
{
    return UserGuide::create(array_merge([
        'user_id' => $owner->id,
        'title' => $title,
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
        'published_at' => now(),
    ], $attrs));
}

function landingPatch(): Patch
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);

    return Patch::create(['game_id' => $game->id, 'build_version' => '12.1.0.69814', 'is_current' => true]);
}

/** @return array<int, array> */
function landingItems(): array
{
    return Livewire::test(Landing::class)->instance()->feed['items'];
}

test('a visitor gets the front page at the site root, not a redirect to the comp builder', function () {
    landingGuide(landingUser('writer'), 'RMD into Jungle');

    $this->get('/')
        ->assertOk()
        ->assertSee('Plan your arena games')
        ->assertSee('Build a 3v3 comp')
        ->assertSee('RMD into Jungle');

    expect(PageViewEvent::where('page', 'landing')->count())->toBe(1);
});

test('a signed-in player is sent to Home', function () {
    $this->actingAs(landingUser('me'))->get('/')->assertRedirect(route('dashboard'));
});

test('a visitor only sees public published guides', function () {
    $writer = landingUser('writer');
    landingGuide($writer, 'Public plan');
    landingGuide($writer, 'Draft plan', ['status' => UserGuideStatus::Draft, 'published_at' => null]);
    landingGuide(landingUser('other'), 'Invited plan', ['visibility' => UserGuideVisibility::Invited]);

    $titles = collect(landingItems())->where('type', 'guide')->map(fn ($i) => $i['guide']->title)->all();

    expect($titles)->toContain('Public plan')
        ->not->toContain('Draft plan')
        ->not->toContain('Invited plan');
});

test('one author fills at most two items of a visitor\'s recent feed', function () {
    $busy = landingUser('busy');
    foreach (range(1, 5) as $i) {
        $guide = landingGuide($busy, "Busy guide {$i}");
        $guide->forceFill(['updated_at' => now()->subMinutes(10 - $i)])->saveQuietly();
    }
    landingGuide(landingUser('quiet'), 'Quiet guide');

    $recent = collect(landingItems())->where('type', 'guide')->reject(fn ($i) => $i['popular']);

    expect($recent->filter(fn ($i) => $i['guide']->user_id === $busy->id))->toHaveCount(2)
        ->and($recent->map(fn ($i) => $i['guide']->title)->all())->toContain('Quiet guide');
});

test('a thin feed is topped up with popular guides, marked as popular and listed after recent ones', function () {
    $busy = landingUser('busy');
    foreach (range(1, 4) as $i) {
        landingGuide($busy, "Busy guide {$i}", ['like_count' => $i]);
    }

    $items = collect(landingItems())->where('type', 'guide')->values();
    $popular = $items->where('popular', true);

    expect($items)->toHaveCount(4)
        ->and($popular)->toHaveCount(2)
        ->and($items->take(2)->every(fn ($i) => ! $i['popular']))->toBeTrue();

    $this->get('/')->assertSee('Popular guide');
});

test('tooltip-only updates are hidden from visitors but still shown on Home', function () {
    $patch = landingPatch();
    $spell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 19434, 'name' => 'Aimed Shot']);
    $update = SpellDataUpdate::create(['patch_id' => $patch->id, 'build_version' => '12.1.0.69814', 'changed_spell_count' => 1]);
    SpellChange::create(['spell_data_update_id' => $update->id, 'spell_id' => $spell->id, 'field' => 'description', 'old_value' => 'a', 'new_value' => 'b']);

    expect(collect(landingItems())->where('type', 'data'))->toBeEmpty();

    $homeItems = Livewire::actingAs(landingUser('me'))->test(Home::class)->instance()->feed['items'];
    expect(collect($homeItems)->where('type', 'data'))->toHaveCount(1);
});

test('a real cooldown change is still shown to visitors', function () {
    $patch = landingPatch();
    $spell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 33206, 'name' => 'Pain Suppression']);
    $update = SpellDataUpdate::create(['patch_id' => $patch->id, 'build_version' => '12.1.0.69814', 'changed_spell_count' => 1]);
    SpellChange::create(['spell_data_update_id' => $update->id, 'spell_id' => $spell->id, 'field' => 'cooldown_seconds', 'old_value' => '180', 'new_value' => '150']);

    $data = collect(landingItems())->where('type', 'data');

    expect($data)->toHaveCount(1)
        ->and($data->first()['tooltips'])->toBe(0);
});

test('Home keeps every guide from one author, with no cap', function () {
    $busy = landingUser('busy');
    foreach (range(1, 4) as $i) {
        landingGuide($busy, "Busy guide {$i}");
    }

    $items = Livewire::actingAs(landingUser('me'))->test(Home::class)->instance()->feed['items'];

    expect(collect($items)->where('type', 'guide'))->toHaveCount(4);
});
