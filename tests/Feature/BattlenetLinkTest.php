<?php

use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Http\Services\BattlenetCharacterSyncService;
use App\Http\Services\CharacterTalentResolver;
use App\Jobs\SyncBattlenetCharacter;
use App\Livewire\Guides\Builder;
use App\Livewire\Guides\Show;
use App\Models\BattlenetAccount;
use App\Models\BattlenetCharacter;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Battle.net linking, character sync, and signing a guide with a character.
 *
 * Every Blizzard response here is a synthetic, trimmed copy of a shape read off the live API on
 * 2026-09-12 — the field names and nesting are real, the characters are not. What is asserted is the
 * boundary, because that is where a mistake is quiet: a callback accepted without its state, one
 * account's characters landing on another, or a guide signed with somebody else's character.
 */
beforeEach(function () {
    config([
        'services.battlenet.client_id' => 'test-client',
        'services.battlenet.client_secret' => 'test-secret',
        'services.battlenet.regions' => ['us', 'eu'],
    ]);
});

function bnetWorld(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '1.0.0-test', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety', 'external_spec_id' => 261]);

    return compact('game', 'patch', 'class', 'spec');
}

/**
 * A user token, a /userinfo, and a character list — the three calls a link makes.
 *
 * The list is read from the container at request time rather than captured: a second Http::fake()
 * call STACKS behind the first instead of replacing it, so a captured list would keep answering
 * with whatever the first call was given.
 */
function fakeBattlenetLink(int $accountId, array $usCharacters): void
{
    app()->instance('test.bnet.list', ['account' => $accountId, 'characters' => $usCharacters]);

    Http::fake(function (Request $request) {
        $url = $request->url();
        $fake = app('test.bnet.list');

        return match (true) {
            str_contains($url, 'oauth.battle.net/token') => Http::response(['access_token' => 'user-token']),
            str_contains($url, 'oauth.battle.net/userinfo') => Http::response(['id' => $fake['account'], 'battletag' => 'Tester#1234']),
            str_contains($url, 'us.api.blizzard.com/profile/user/wow') => Http::response([
                'wow_accounts' => [['id' => 1, 'characters' => $fake['characters']]],
            ]),
            default => Http::response(['code' => 404], 404),
        };
    });
}

function listCharacter(int $id, string $name, int $level): array
{
    return [
        'name' => $name, 'id' => $id, 'level' => $level,
        'realm' => ['name' => 'Test Realm', 'id' => 1, 'slug' => 'test-realm'],
        'playable_class' => ['id' => 4], 'playable_race' => ['name' => 'Human'], 'faction' => ['name' => 'Alliance'],
    ];
}

// ------------------------------------------------------------------ the OAuth round trip

test('linking sends the player to Blizzard with a state it remembers', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('battlenet.redirect'));

    $state = session('battlenet_oauth_state');
    expect($state)->toBeString()->toHaveLength(40);

    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://oauth.battle.net/authorize?')
        ->toContain('client_id=test-client')
        ->toContain('scope=openid%20wow.profile')
        ->toContain('state='.$state);
});

test('a callback whose state does not match links nothing', function () {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['battlenet_oauth_state' => 'expected'])
        ->get(route('battlenet.callback', ['state' => 'forged', 'code' => 'abc']))
        ->assertRedirect(route('characters.index'))
        ->assertSessionHas('battlenet_error');

    expect(BattlenetAccount::count())->toBe(0);
    Http::assertNothingSent();
});

test('cancelling on Blizzard\'s consent screen links nothing', function () {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['battlenet_oauth_state' => 'expected'])
        ->get(route('battlenet.callback', ['state' => 'expected', 'error' => 'access_denied']))
        ->assertSessionHas('battlenet_error');

    expect(BattlenetAccount::count())->toBe(0);
});

test('a successful link stores the account and its characters, and queues detail syncs only for high-level ones', function () {
    bnetWorld();
    Queue::fake();
    $user = User::factory()->create();
    fakeBattlenetLink(555, [listCharacter(10, 'Mainchar', 90), listCharacter(11, 'Lowalt', 12)]);

    $this->actingAs($user)
        ->withSession(['battlenet_oauth_state' => 'st'])
        ->get(route('battlenet.callback', ['state' => 'st', 'code' => 'the-code']))
        ->assertRedirect(route('characters.index'))
        ->assertSessionHas('battlenet_status');

    $account = $user->fresh()->battlenetAccount;
    expect($account->battlenet_id)->toBe(555)
        ->and($account->battletag)->toBe('Tester#1234')
        ->and($account->characters()->pluck('name')->sort()->values()->all())->toBe(['Lowalt', 'Mainchar']);

    $main = BattlenetCharacter::where('name', 'Mainchar')->first();
    expect($main->region)->toBe('us')->and($main->gameClass->slug)->toBe('rogue');

    Queue::assertPushed(SyncBattlenetCharacter::class, 1);
    Queue::assertPushed(SyncBattlenetCharacter::class, fn ($job) => $job->characterId === $main->id);
});

test('a Battle.net account already linked to someone else cannot be claimed', function () {
    bnetWorld();
    Queue::fake();
    $owner = User::factory()->create();
    BattlenetAccount::create(['user_id' => $owner->id, 'battlenet_id' => 555, 'battletag' => 'Owner#1']);

    $intruder = User::factory()->create();
    fakeBattlenetLink(555, [listCharacter(10, 'Mainchar', 90)]);

    $this->actingAs($intruder)
        ->withSession(['battlenet_oauth_state' => 'st'])
        ->get(route('battlenet.callback', ['state' => 'st', 'code' => 'c']))
        ->assertSessionHas('battlenet_error');

    expect($intruder->fresh()->battlenetAccount)->toBeNull()
        ->and(BattlenetAccount::where('battlenet_id', 555)->value('user_id'))->toBe($owner->id)
        ->and(BattlenetCharacter::count())->toBe(0);
});

test('re-linking drops characters no longer on the account, and a guide signed with one keeps the guide', function () {
    bnetWorld();
    Queue::fake();
    $user = User::factory()->create();
    fakeBattlenetLink(555, [listCharacter(10, 'Keeper', 90), listCharacter(11, 'Deleted', 90)]);
    $this->actingAs($user)->withSession(['battlenet_oauth_state' => 's'])
        ->get(route('battlenet.callback', ['state' => 's', 'code' => 'c']));

    $gone = BattlenetCharacter::where('name', 'Deleted')->first();
    $guide = UserGuide::create(['user_id' => $user->id, 'title' => 'G', 'battlenet_character_id' => $gone->id]);

    fakeBattlenetLink(555, [listCharacter(10, 'Keeper', 90)]);
    $this->actingAs($user)->withSession(['battlenet_oauth_state' => 's2'])
        ->get(route('battlenet.callback', ['state' => 's2', 'code' => 'c']));

    expect(BattlenetCharacter::pluck('name')->all())->toBe(['Keeper'])
        ->and($guide->fresh())->not->toBeNull()
        ->and($guide->fresh()->battlenet_character_id)->toBeNull();
});

test('unlinking removes the account and its characters but not the guides signed with them', function () {
    bnetWorld();
    $user = User::factory()->create();
    $account = BattlenetAccount::create(['user_id' => $user->id, 'battlenet_id' => 1, 'battletag' => 'T#1']);
    $char = BattlenetCharacter::create(['battlenet_account_id' => $account->id, 'region' => 'us', 'blizzard_character_id' => 1,
        'name' => 'Signer', 'realm_slug' => 'r', 'realm_name' => 'R', 'level' => 90]);
    $guide = UserGuide::create(['user_id' => $user->id, 'title' => 'G', 'battlenet_character_id' => $char->id]);

    $this->actingAs($user)->delete(route('battlenet.unlink'))->assertRedirect(route('characters.index'));

    expect(BattlenetAccount::count())->toBe(0)
        ->and(BattlenetCharacter::count())->toBe(0)
        ->and($guide->fresh()->battlenet_character_id)->toBeNull();
});

// ------------------------------------------------------------------ character detail sync

function syncFixtureCharacter(): BattlenetCharacter
{
    $user = User::factory()->create();
    $account = BattlenetAccount::create(['user_id' => $user->id, 'battlenet_id' => 9, 'battletag' => 'S#1']);

    return BattlenetCharacter::create(['battlenet_account_id' => $account->id, 'region' => 'us', 'blizzard_character_id' => 99,
        'name' => 'Syncme', 'realm_slug' => 'test-realm', 'realm_name' => 'Test Realm', 'level' => 90]);
}

test('a detail sync stores exp, the best rank title, current ratings, talents and self-hosted gear icons', function () {
    bnetWorld();
    Storage::fake('public');
    $character = syncFixtureCharacter();

    $base = 'us.api.blizzard.com/profile/wow/character/test-realm/syncme';
    Http::fake([
        'oauth.battle.net/token' => Http::response(['access_token' => 'app-token']),
        'us.api.blizzard.com/data/wow/pvp-season/index*' => Http::response(['current_season' => ['id' => 42]]),
        'us.api.blizzard.com/data/wow/media/item/500*' => Http::response(['assets' => [['key' => 'icon', 'value' => 'https://render.worldofwarcraft.com/us/icons/56/helm_icon.jpg']]]),
        'render.worldofwarcraft.com/*' => Http::response('jpeg-bytes'),
        "{$base}/achievements/statistics*" => Http::response(['categories' => [[
            'name' => 'Player vs. Player', 'statistics' => [],
            'sub_categories' => [['name' => 'Rated Arenas', 'statistics' => [
                ['id' => 595, 'name' => 'Highest 3v3 personal rating', 'quantity' => 2236],
                ['id' => 370, 'name' => 'Highest 2v2 personal rating', 'quantity' => 2149],
                ['id' => 838, 'name' => 'Arenas played', 'quantity' => 400],
                ['id' => 837, 'name' => 'Arenas won', 'quantity' => 210],
            ]]],
        ]]]),
        "{$base}/achievements*" => Http::response(['achievements' => [
            ['id' => 1, 'achievement' => ['name' => 'Legend of the Past'], 'completed_timestamp' => 9],
            ['id' => 2, 'achievement' => ['name' => 'Rival I: Midnight Season 1'], 'completed_timestamp' => 5],
            ['id' => 3, 'achievement' => ['name' => 'Rival II: Midnight Season 1'], 'completed_timestamp' => 6],
            ['id' => 4, 'achievement' => ['name' => 'Duelist: Midnight Season 2']],
        ]]),
        "{$base}/pvp-summary*" => Http::response(['brackets' => [
            ['href' => "https://{$base}/pvp-bracket/3v3?namespace=profile-us"],
            ['href' => "https://{$base}/pvp-bracket/2v2?namespace=profile-us"],
        ]]),
        "{$base}/pvp-bracket/3v3*" => Http::response(['bracket' => ['type' => 'ARENA_3v3'], 'rating' => 1850, 'season' => ['id' => 42],
            'season_match_statistics' => ['played' => 50, 'won' => 30, 'lost' => 20]]),
        "{$base}/pvp-bracket/2v2*" => Http::response(['bracket' => ['type' => 'ARENA_2v2'], 'rating' => 1600, 'season' => ['id' => 41],
            'season_match_statistics' => ['played' => 10, 'won' => 5, 'lost' => 5]]),
        "{$base}/specializations*" => Http::response([
            'active_specialization' => ['id' => 261],
            'specializations' => [['specialization' => ['id' => 261, 'name' => 'Subtlety'], 'loadouts' => [[
                'is_active' => true, 'talent_loadout_code' => 'CODE',
                'selected_class_talents' => [['id' => 100, 'rank' => 1, 'tooltip' => ['talent' => ['id' => 1000, 'name' => 'Kidney Shot'],
                    'spell_tooltip' => ['spell' => ['id' => 408]]]]],
                'selected_spec_talents' => [], 'selected_hero_talents' => [],
                'selected_hero_talent_tree' => ['name' => 'Trickster'],
            ]], 'pvp_talent_slots' => [['selected' => ['talent' => ['id' => 77, 'name' => 'Smoke Bomb'], 'spell_tooltip' => ['spell' => ['id' => 212182]]], 'slot_number' => 2]]]],
        ]),
        "{$base}/equipment*" => Http::response(['equipped_items' => [
            ['item' => ['id' => 500], 'slot' => ['type' => 'HEAD', 'name' => 'Head'], 'quality' => ['type' => 'EPIC'], 'name' => 'Test Helm',
                'level' => ['value' => 292], 'enchantments' => [['display_string' => 'Enchanted: Helm Rune |A:Professions-ChatIcon-Quality-12-Tier2:20:20|a']],
                'sockets' => [['item' => ['name' => 'Test Gem']]]],
            ['item' => ['id' => 501], 'slot' => ['type' => 'TABARD', 'name' => 'Tabard'], 'quality' => ['type' => 'COMMON'], 'name' => 'Guild Tabard'],
        ]]),
        $base.'*' => Http::response(['name' => 'Syncme', 'level' => 90, 'character_class' => ['id' => 4], 'active_spec' => ['id' => 261],
            'race' => ['name' => 'Human'], 'faction' => ['name' => 'Alliance'], 'equipped_item_level' => 280, 'achievement_points' => 12000,
            'last_login_timestamp' => 1788420534000]),
    ]);

    expect(app(BattlenetCharacterSyncService::class)->syncDetails($character))->toBeTrue();

    $c = $character->fresh();
    expect($c->exp_3v3)->toBe(2236)
        ->and($c->exp_2v2)->toBe(2149)
        ->and($c->bestExp())->toBe(['rating' => 2236, 'bracket' => '3v3'])
        ->and($c->arenas_played)->toBe(400)
        // Rival II beats Rival I; "Legend of the Past" is not the Legend title; an incomplete
        // Duelist is not earned.
        ->and($c->pvp_rank_title)->toBe('Rival II: Midnight Season 1')
        ->and($c->rankTitleShort())->toBe('Rival II')
        ->and($c->item_level)->toBe(280)
        ->and($c->specialization->external_spec_id)->toBe(261)
        ->and($c->sync_error)->toBeNull();

    // Only the current season's rating is "current".
    expect(collect($c->currentRatings())->pluck('label')->all())->toBe(['3v3']);

    // Tabard dropped; Blizzard's inline atlas markup stripped; the icon is self-hosted.
    expect($c->equipment)->toHaveCount(1)
        ->and($c->equipment[0]['enchantments'])->toBe(['Helm Rune'])
        ->and($c->equipment[0]['gems'])->toBe(['Test Gem'])
        ->and($c->equipment[0]['icon'])->toBe('helm_icon.jpg');
    Storage::disk('public')->assertExists('item-icons/helm_icon.jpg');

    expect($c->activeTalents()['picks'][0])->toMatchArray(['node' => 100, 'talent' => 1000, 'spell' => 408, 'tree' => 'class'])
        ->and($c->activeTalents()['pvp'][0]['pvp_talent'])->toBe(77);
});

test('a character with no public profile records why instead of failing silently', function () {
    $character = syncFixtureCharacter();
    Http::fake([
        'oauth.battle.net/token' => Http::response(['access_token' => 'app-token']),
        '*' => Http::response(['code' => 404], 404),
    ]);

    app(BattlenetCharacterSyncService::class)->syncDetails($character);

    expect($character->fresh()->sync_error)->toContain('no public profile')
        ->and($character->fresh()->synced_at)->not->toBeNull();
});

test('an API failure is written to the character, never thrown into the queue', function () {
    $character = syncFixtureCharacter();
    Http::fake([
        'oauth.battle.net/token' => Http::response(['access_token' => 'app-token']),
        '*' => Http::response(['code' => 500], 500),
    ]);

    expect(app(BattlenetCharacterSyncService::class)->syncDetails($character))->toBeFalse()
        ->and($character->fresh()->sync_error)->toContain('500');
});

test('tooltip markup is stripped to the text the game shows', function () {
    expect(BattlenetCharacterSyncService::cleanDisplayString('+23 |cFF00FF00Primary|r Stat |A:Quality-Tier2:20:20|a'))
        ->toBe('+23 Primary Stat');
});

// ------------------------------------------------------------------ talent resolution

/** Class + spec + two hero trees, with the id collisions and structural node real data has. */
function talentWorld(array $w): array
{
    $mk = fn ($type, $name, $spec = null) => TalentTree::create(['patch_id' => $w['patch']->id, 'class_id' => $w['class']->id,
        'spec_id' => $spec, 'type' => $type, 'name' => $name, 'external_tree_id' => random_int(1, 99999)]);
    $classTree = $mk('class', 'Rogue Class');
    $specTree = $mk('spec', 'Subtlety', $w['spec']->id);
    $trickster = $mk('hero', 'Trickster');
    $deathstalker = $mk('hero', 'Deathstalker');
    $trickster->specializations()->attach($w['spec']->id);
    $deathstalker->specializations()->attach($w['spec']->id);

    $spell = fn ($id, $name) => Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => $id, 'name' => $name]);
    $node = fn ($tree, $ext, $type = 'ACTIVE') => TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => $ext, 'type' => $type, 'max_ranks' => 1]);
    $entry = fn ($node, $spell, $talentId) => TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 1, 'max_rank' => 1, 'external_talent_id' => $talentId]);

    $classNode = $node($classTree, 100);
    $classEntry = $entry($classNode, $spell(408, 'Kidney Shot'), 1000);

    $choice = $node($specTree, 200, 'CHOICE');
    $entry($choice, $spell(5001, 'Option A'), 2001);
    $optionB = $entry($choice, $spell(5002, 'Option B'), 2002);

    // Same external node id in BOTH hero trees — only the selected tree's copy may match.
    $trickNode = $node($trickster, 300);
    $trickEntry = $entry($trickNode, $spell(6001, 'Trickster Talent'), 3001);
    $entry($node($deathstalker, 300), $spell(6002, 'Deathstalker Talent'), 3002);

    // The hero-tree selector: in the tree, no entries.
    $node($classTree, 400, 'SUBTREE');

    $pvpSpell = $spell(212182, 'Smoke Bomb');
    $pvp = PvpTalent::create(['spec_id' => $w['spec']->id, 'patch_id' => $w['patch']->id, 'spell_id' => $pvpSpell->id, 'external_pvp_talent_id' => 77]);

    return compact('classNode', 'classEntry', 'choice', 'optionB', 'trickNode', 'trickEntry', 'pvp');
}

function talentSnapshot(): array
{
    return [
        'spec_external_id' => 261, 'spec_name' => 'Subtlety', 'active' => true, 'hero_tree' => 'Trickster', 'loadout_code' => 'X',
        'picks' => [
            ['node' => 100, 'talent' => 1000, 'spell' => 408, 'rank' => 1, 'name' => 'Kidney Shot', 'tree' => 'class'],
            ['node' => 200, 'talent' => 2002, 'spell' => 5002, 'rank' => 1, 'name' => 'Option B', 'tree' => 'spec'],
            ['node' => 300, 'talent' => 3001, 'spell' => 6001, 'rank' => 1, 'name' => 'Trickster Talent', 'tree' => 'hero'],
            ['node' => 400, 'talent' => null, 'spell' => null, 'rank' => 1, 'name' => null, 'tree' => 'class'],
            ['node' => 999, 'talent' => 9999, 'spell' => 9999, 'rank' => 1, 'name' => 'Next Patch Talent', 'tree' => 'spec'],
        ],
        'pvp' => [['pvp_talent' => 77, 'spell' => 212182, 'name' => 'Smoke Bomb']],
    ];
}

test('talent picks resolve onto the current patch by tree, choice and hero tree, and unknown ones are named', function () {
    $w = bnetWorld();
    $t = talentWorld($w);

    $view = app(CharacterTalentResolver::class)->resolve(talentSnapshot());

    expect($view['spec']->id)->toBe($w['spec']->id)
        ->and($view['chosenEntries'])->toBe([
            $t['classNode']->id => $t['classEntry']->id,
            $t['choice']->id => $t['optionB']->id,   // the entry actually chosen, not the first
            $t['trickNode']->id => $t['trickEntry']->id, // the selected hero tree's copy
        ])
        ->and($view['unresolved'])->toBe(['Next Patch Talent']) // the selector node is not a talent
        ->and($view['totalCount'])->toBe(4)
        ->and($view['pvpTalentIds'])->toBe([$t['pvp']->id]);
});

// ------------------------------------------------------------------ characters and guides

function ownedCharacter(User $user, array $attrs = []): BattlenetCharacter
{
    $account = $user->battlenetAccount ?? BattlenetAccount::create(['user_id' => $user->id, 'battlenet_id' => random_int(1, 999999), 'battletag' => 'T#'.$user->id]);

    return BattlenetCharacter::create(array_merge([
        'battlenet_account_id' => $account->id, 'region' => 'us', 'blizzard_character_id' => random_int(1, 999999),
        'name' => 'Signer', 'realm_slug' => 'test-realm', 'realm_name' => 'Test Realm', 'level' => 90,
        'exp_3v3' => 2236, 'synced_at' => now(),
    ], $attrs));
}

test('a character page is only for its owner', function () {
    bnetWorld();
    $owner = User::factory()->create();
    $character = ownedCharacter($owner);

    $this->actingAs($owner)->get(route('characters.show', $character->id))->assertOk()->assertSee('Signer');
    $this->actingAs(User::factory()->create())->get(route('characters.show', $character->id))->assertNotFound();
});

test('the characters page renders linked and unlinked', function () {
    bnetWorld();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('characters.index'))->assertOk()->assertSee('Link Battle.net');

    ownedCharacter($user, ['pvp_rank_title' => 'Rival II: Midnight Season 1', 'pvp_rank_tier' => 6]);
    $this->actingAs($user)->get(route('characters.index'))->assertOk()
        ->assertSee('Signer')->assertSee('2236')->assertSee('Rival II');
});

test('a guide can be signed with your own character and never with someone else\'s', function () {
    bnetWorld();
    $author = User::factory()->create();
    $mine = ownedCharacter($author);
    $theirs = ownedCharacter(User::factory()->create(), ['name' => 'Stranger']);
    $guide = UserGuide::create(['user_id' => $author->id, 'title' => 'Signed guide']);

    $builder = Livewire::actingAs($author)->test(Builder::class, ['guide' => $guide]);

    $builder->call('setAuthorCharacter', $theirs->id);
    expect($guide->fresh()->battlenet_character_id)->toBeNull();

    $builder->call('setAuthorCharacter', $mine->id);
    expect($guide->fresh()->battlenet_character_id)->toBe($mine->id);

    $builder->call('setAuthorCharacter', null);
    expect($guide->fresh()->battlenet_character_id)->toBeNull();
});

test('a reader sees the signing character\'s exp, and gear on request', function () {
    bnetWorld();
    $author = User::factory()->create(['username' => 'writer']);
    $character = ownedCharacter($author, ['equipment' => [[
        'slot' => 'HEAD', 'slot_name' => 'Head', 'item_id' => 1, 'name' => 'Gladiator Helm', 'quality' => 'EPIC',
        'level' => 292, 'enchantments' => [], 'gems' => [], 'set' => null, 'icon' => null,
    ]]]);
    $guide = UserGuide::create(['user_id' => $author->id, 'title' => 'Public', 'type' => UserGuideType::Comp,
        'status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Public, 'battlenet_character_id' => $character->id]);

    Livewire::actingAs(User::factory()->create())
        ->test(Show::class, ['username' => 'writer', 'guide' => $guide])
        ->assertSee('Written as')
        ->assertSee('Signer')
        ->assertSee('2236 exp')
        ->assertDontSee('Gladiator Helm')
        ->call('toggleAuthorBuild')
        ->assertSee('Gladiator Helm');
});

test('a signed guide is credited to the character and realm, an unsigned one to the username', function () {
    bnetWorld();
    $author = User::factory()->create(['username' => 'writer']);
    $character = ownedCharacter($author, ['name' => 'Signer', 'realm_name' => 'Bleeding Hollow']);

    $signed = UserGuide::create(['user_id' => $author->id, 'title' => 'A', 'battlenet_character_id' => $character->id]);
    $unsigned = UserGuide::create(['user_id' => $author->id, 'title' => 'B']);

    expect($signed->authorLabel())->toBe('Signer-BleedingHollow')
        ->and($unsigned->authorLabel())->toBe('writer');
});

test('an unsigned guide shows no character at all', function () {
    bnetWorld();
    $author = User::factory()->create(['username' => 'writer']);
    ownedCharacter($author);
    $guide = UserGuide::create(['user_id' => $author->id, 'title' => 'Public', 'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public]);

    Livewire::test(Show::class, ['username' => 'writer', 'guide' => $guide])
        ->assertDontSee('Written as')
        ->assertDontSee('Signer');
});

test('a comp slot can take the signing character\'s real talent build', function () {
    $w = bnetWorld();
    $t = talentWorld($w);
    $author = User::factory()->create();
    $character = ownedCharacter($author, ['talents' => [talentSnapshot()]]);
    $guide = UserGuide::create(['user_id' => $author->id, 'title' => 'G', 'battlenet_character_id' => $character->id]);
    $member = UserGuideMember::create(['user_guide_id' => $guide->id, 'side' => 'team', 'position' => 0, 'spec_id' => $w['spec']->id]);

    Livewire::actingAs($author)->test(Builder::class, ['guide' => $guide])
        ->assertSee('Use Signer')
        ->call('useCharacterTalents', 0);

    $build = $member->fresh()->talentBuild;
    expect($build)->not->toBeNull()
        ->and($build->user_id)->toBeNull() // a guide build — never anyone's personal build
        ->and($build->choices()->pluck('chosen_entry_id', 'talent_node_id')->all())->toEqual([
            $t['classNode']->id => $t['classEntry']->id,
            $t['choice']->id => $t['optionB']->id,
            $t['trickNode']->id => $t['trickEntry']->id,
        ])
        ->and($build->pvpChoices()->pluck('pvp_talent_id')->all())->toBe([$t['pvp']->id]);
});
