<?php

use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Livewire\Guides\Browse;
use App\Livewire\Guilds\Index as GuildIndex;
use App\Livewire\Guilds\Show as GuildShow;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Guild;
use App\Models\Specialization;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Guilds, ratings, comments and the two places guides are now discovered.
 *
 * What is asserted is the access boundary and the ranking, because those are the parts where a
 * mistake is not cosmetic: a guild guide leaking to a non-member, or a self-rated guide topping
 * the comp listing, are both quiet failures nobody would report as a bug.
 */
function discoveryFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);

    return [
        'author' => User::factory()->create(['username' => 'author']),
        'other' => User::factory()->create(['username' => 'other']),
        'class' => $class,
        'specs' => [
            Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety']),
            Specialization::create(['class_id' => $class->id, 'name' => 'Outlaw', 'slug' => 'outlaw']),
            Specialization::create(['class_id' => $class->id, 'name' => 'Assassination', 'slug' => 'assassination']),
        ],
    ];
}

function discoveryGuide(User $author, array $specs, array $attrs = []): UserGuide
{
    $guide = UserGuide::create(array_merge([
        'user_id' => $author->id,
        'type' => UserGuideType::Comp,
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
        'title' => 'Guide '.Str::random(6),
    ], $attrs));

    foreach ($specs as $i => $spec) {
        UserGuideMember::create([
            'user_guide_id' => $guide->id,
            'side' => 'team',
            'position' => $i,
            'spec_id' => $spec->id,
        ]);
    }

    $guide->syncCompKey();

    return $guide->fresh();
}

function showComponent(User $as, UserGuide $guide)
{
    return Livewire::actingAs($as)->test(App\Livewire\Guides\Show::class, [
        'username' => $guide->user->username,
        'guide' => $guide,
    ]);
}

test('creating a guild makes the creator a member of it', function () {
    $f = discoveryFixture();

    Livewire::actingAs($f['author'])->test(GuildIndex::class)->set('name', 'Test Guild')->call('create');

    $guild = Guild::first();

    // A guild whose creator is not in it is a broken state nothing else expects.
    expect($guild)->not->toBeNull()
        ->and($guild->hasMember($f['author']))->toBeTrue()
        ->and($guild->isOwnedBy($f['author']))->toBeTrue()
        ->and($guild->slug)->toBe('test-guild');
});

test('joining is idempotent and the owner cannot leave', function () {
    $f = discoveryFixture();
    $guild = Guild::create(['owner_id' => $f['author']->id, 'name' => 'G']);
    $guild->join($f['author']);

    // The invite is a URL, so it will be clicked twice.
    $guild->join($f['other']);
    $guild->join($f['other']);
    expect($guild->members()->count())->toBe(2);

    // A guild with no owner has nobody who can remove members or delete it.
    expect($guild->leave($f['author']))->toBeFalse()
        ->and($guild->leave($f['other']))->toBeTrue()
        ->and($guild->fresh()->members()->count())->toBe(1);
});

test('a guild guide is readable by members only, and never listed publicly', function () {
    $f = discoveryFixture();
    $guild = Guild::create(['owner_id' => $f['author']->id, 'name' => 'G']);
    $guild->join($f['author']);

    $guide = discoveryGuide($f['author'], $f['specs'], [
        'visibility' => UserGuideVisibility::Guild,
        'guild_id' => $guild->id,
    ]);

    expect($guide->isReadableBy($f['other']))->toBeFalse()
        ->and($guide->isReadableBy(null))->toBeFalse()
        ->and($guide->isReadableBy($f['author']))->toBeTrue();

    $guild->join($f['other']);
    expect($guide->fresh()->isReadableBy($f['other']))->toBeTrue();

    // Absent from public listings entirely, not shown-and-locked, which would leak the title.
    expect(UserGuide::listed()->count())->toBe(0)
        ->and(UserGuide::forComp(collect($f['specs'])->pluck('id')->all())->count())->toBe(0);
});

test('deleting a guild keeps its guides but stops them being readable by the guild', function () {
    $f = discoveryFixture();
    $guild = Guild::create(['owner_id' => $f['author']->id, 'name' => 'G']);
    $guild->join($f['author']);
    $guild->join($f['other']);

    $guide = discoveryGuide($f['author'], $f['specs'], [
        'visibility' => UserGuideVisibility::Guild,
        'guild_id' => $guild->id,
    ]);

    $guild->delete();
    $guide->refresh();

    // Somebody's written work must never disappear because a group they were in disbanded.
    expect(UserGuide::whereKey($guide->id)->exists())->toBeTrue()
        ->and($guide->guild_id)->toBeNull()
        ->and($guide->isReadableBy($f['author']))->toBeTrue()
        ->and($guide->isReadableBy($f['other']))->toBeFalse();
});

test('a non-member sees no guides on a guild page', function () {
    $f = discoveryFixture();
    $guild = Guild::create(['owner_id' => $f['author']->id, 'name' => 'G']);
    $guild->join($f['author']);
    discoveryGuide($f['author'], $f['specs'], [
        'title' => 'Members Only Plan',
        'visibility' => UserGuideVisibility::Guild,
        'guild_id' => $guild->id,
    ]);

    $outsider = Livewire::actingAs($f['other'])->test(GuildShow::class, ['guild' => $guild]);
    expect($outsider->get('guides'))->toBeEmpty()
        ->and($outsider->html())->not->toContain('Members Only Plan');

    $guild->join($f['other']);
    $member = Livewire::actingAs($f['other'])->test(GuildShow::class, ['guild' => $guild->fresh()]);
    expect($member->html())->toContain('Members Only Plan');
});

test('the comp key is order-independent and built from the author own side only', function () {
    $f = discoveryFixture();
    [$a, $b, $c] = $f['specs'];

    $guide = discoveryGuide($f['author'], [$c, $a, $b]);

    // A comp is a set, not an ordering.
    expect($guide->comp_key)->toBe(UserGuide::compKeyFor([$a->id, $b->id, $c->id]))
        ->and($guide->comp_key)->toBe(UserGuide::compKeyFor([$b->id, $c->id, $a->id]));

    // An enemy is not your comp — a guide is filed under what it teaches.
    UserGuideMember::create([
        'user_guide_id' => $guide->id,
        'side' => 'enemy',
        'position' => 0,
        'spec_id' => $a->id,
    ]);
    $guide->syncCompKey();

    expect($guide->fresh()->comp_key)->toBe(UserGuide::compKeyFor([$a->id, $b->id, $c->id]));
});

test('the comp listing ranks by rating and puts unrated guides last', function () {
    $f = discoveryFixture();

    $best = discoveryGuide($f['author'], $f['specs'], ['title' => 'Best']);
    $mid = discoveryGuide($f['author'], $f['specs'], ['title' => 'Mid']);
    discoveryGuide($f['author'], $f['specs'], ['title' => 'Unrated']);

    $best->forceFill(['rating_avg' => 4.8, 'rating_count' => 10])->save();
    $mid->forceFill(['rating_avg' => 2.2, 'rating_count' => 10])->save();

    // "Nobody has said" is weaker evidence than a low score, but it must not outrank a guide
    // people actually liked.
    expect(UserGuide::forComp(collect($f['specs'])->pluck('id')->all())->pluck('title')->all())
        ->toBe(['Best', 'Mid', 'Unrated']);
});

test('a rating is one per person and updates rather than stacking', function () {
    $f = discoveryFixture();
    $guide = discoveryGuide($f['author'], $f['specs']);

    showComponent($f['other'], $guide->fresh())->call('rate', 5);
    expect($guide->fresh()->rating_count)->toBe(1)
        ->and((float) $guide->fresh()->rating_avg)->toBe(5.0);

    // Re-rating moves your score; it does not add a second vote.
    showComponent($f['other'], $guide->fresh())->call('rate', 1);
    expect($guide->fresh()->rating_count)->toBe(1)
        ->and((float) $guide->fresh()->rating_avg)->toBe(1.0);
});

test('an author cannot rate their own guide', function () {
    $f = discoveryFixture();
    $guide = discoveryGuide($f['author'], $f['specs']);

    // Self-rating is the cheapest way to game which guides other people are shown.
    $c = showComponent($f['author'], $guide)->call('rate', 5);

    expect($guide->fresh()->rating_count)->toBe(0)
        ->and($c->get('feedbackError'))->not->toBeNull();
});

test('comments render as plain text and can be removed by their author or the guide author', function () {
    $f = discoveryFixture();
    $guide = discoveryGuide($f['author'], $f['specs']);

    showComponent($f['other'], $guide)
        ->set('comment', '<script>alert(1)</script> still works')
        ->call('postComment');

    $comment = $guide->comments()->first();
    expect($comment)->not->toBeNull()
        ->and($comment->user_id)->toBe($f['other']->id);

    // Escaped at render, never interpreted as markup — this is written by anyone.
    expect(showComponent($f['other'], $guide->fresh())->html())
        ->not->toContain('<script>alert(1)</script>');

    // The guide's author moderates their own page.
    showComponent($f['author'], $guide->fresh())->call('deleteComment', $comment->id);
    expect($guide->comments()->count())->toBe(0);
});

test('browse lists only public published guides', function () {
    $f = discoveryFixture();
    discoveryGuide($f['author'], $f['specs'], ['title' => 'Public One']);
    discoveryGuide($f['author'], $f['specs'], ['title' => 'Private One', 'visibility' => UserGuideVisibility::Invited]);
    discoveryGuide($f['author'], $f['specs'], ['title' => 'Draft One', 'status' => UserGuideStatus::Draft]);

    $html = Livewire::test(Browse::class)->html();

    expect($html)->toContain('Public One')
        ->and($html)->not->toContain('Private One')
        ->and($html)->not->toContain('Draft One');
});

test('browse search and class filter narrow the listing', function () {
    $f = discoveryFixture();
    discoveryGuide($f['author'], $f['specs'], ['title' => 'Kidney opener']);
    discoveryGuide($f['author'], $f['specs'], ['title' => 'Something else']);

    $c = Livewire::test(Browse::class)->set('search', 'Kidney');
    expect($c->html())->toContain('Kidney opener')
        ->and($c->html())->not->toContain('Something else');

    // The class filter matches the author's own comp, never the enemy team.
    expect(Livewire::test(Browse::class)->set('classSlug', 'rogue')->html())->toContain('Kidney opener');
    expect(Livewire::test(Browse::class)->set('classSlug', 'nope')->html())->not->toContain('Kidney opener');
});
