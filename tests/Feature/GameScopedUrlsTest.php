<?php

use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;

/**
 * Game-scoped URLs (/wow/...) and permanent redirects from where those pages used to live.
 *
 * WHY THESE ASSERTIONS. The route names did not change, so every internal link kept working the
 * moment the paths moved and nothing in the app would have told us if a redirect were missing —
 * the only thing that breaks is somebody else's link, which is exactly the class of breakage no
 * amount of clicking around finds. So this asserts the PATHS, not the names, in both directions.
 *
 * A 301 is asserted rather than "any redirect": a 302 tells a search engine the move is temporary
 * and to keep the old URL indexed, which would leave the site permanently split across two sets of
 * addresses.
 */
function seedMinimalWowData(): void
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    Specialization::create(['class_id' => $class->id, 'name' => 'Discipline', 'slug' => 'discipline']);
}

/**
 * Two of these (class-guide, pvp-guides) answer their bare index with a redirect to a default
 * spec — designed behaviour, not a move artefact. What matters for this change is that the
 * redirect stays inside /wow, which it only does because those components build it from a route
 * NAME; a hardcoded path would have quietly sent visitors back to the old URL, where the catch-all
 * redirect would bounce them forward again on every single load.
 */
test('every game-scoped page is served under /wow', function (string $path) {
    seedMinimalWowData();

    $response = $this->get($path);

    if ($response->isRedirect()) {
        // Location may be absolute, so compare the path rather than the whole header.
        $target = parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
        expect($target)->toStartWith('/wow/');

        $response = $this->followingRedirects()->get($path);
    }

    $response->assertOk();
})->with([
    '/wow/comps',
    '/wow/spells',
    '/wow/spell-finder',
    '/wow/spell-counters',
    '/wow/top-damage-rotations',
    '/wow/class-guide',
    '/wow/cc-chains',
    '/wow/claudes-guides',
    '/wow/burst-guides',
    '/wow/pvp-guides',
    '/wow/cc-review',
    '/wow/cc-immunity-review',
]);

test('the old path for every moved page permanently redirects to its new home', function (string $old, string $new) {
    $this->get($old)
        ->assertStatus(301)
        ->assertRedirect($new);
})->with([
    // Renamed as well as moved — /wow/wow-comps would be a 404, and this is the most-linked URL
    // on the site, so it gets its own assertion rather than riding a generic prefix rule.
    ['/wow-comps', '/wow/comps'],
    ['/spells', '/wow/spells'],
    ['/spell-finder', '/wow/spell-finder'],
    ['/spell-counters', '/wow/spell-counters'],
    ['/top-damage-rotations', '/wow/top-damage-rotations'],
    ['/class-guide', '/wow/class-guide'],
    ['/cc-chains', '/wow/cc-chains'],
    ['/claudes-guides', '/wow/claudes-guides'],
    ['/burst-guides', '/wow/burst-guides'],
    ['/pvp-guides', '/wow/pvp-guides'],
    ['/cc-review', '/wow/cc-review'],
    ['/cc-immunity-review', '/wow/cc-immunity-review'],
]);

test('a deep old link keeps its parameters instead of dropping to an index', function (string $old, string $new) {
    $this->get($old)->assertStatus(301)->assertRedirect($new);
})->with([
    ['/class-guide/priest/discipline', '/wow/class-guide/priest/discipline'],
    ['/pvp-guides/priest/discipline', '/wow/pvp-guides/priest/discipline'],
    ['/claudes-guides/priest/discipline', '/wow/claudes-guides/priest/discipline'],
    ['/top-damage-rotations/rogue/subtlety/15/talents', '/wow/top-damage-rotations/rogue/subtlety/15/talents'],
    ['/spell/12345', '/wow/spell/12345'],
]);

test('the site root is the front page, which links to the comp builder rather than redirecting', function () {
    // A 301 to /wow/comps until 2026-09-16; see LandingPageTest.
    $this->get('/')->assertOk()->assertSee(route('wow-comps'), false);
});

test('the pre-existing /claudes-counters link still resolves, in one hop not two', function () {
    $this->get('/claudes-counters')->assertStatus(301)->assertRedirect('/wow/spell-counters');
});

test('route names still build the paths every internal link depends on', function () {
    expect(route('wow-comps', absolute: false))->toBe('/wow/comps')
        ->and(route('spells.explore', absolute: false))->toBe('/wow/spells')
        ->and(route('burst-guides', absolute: false))->toBe('/wow/burst-guides')
        ->and(route('claudes-counters', absolute: false))->toBe('/wow/spell-counters')
        ->and(route('pvp-guides', absolute: false))->toBe('/wow/pvp-guides')
        ->and(route('spell.show', ['spellId' => 42], absolute: false))->toBe('/wow/spell/42')
        // Not game-scoped and must not become so: a guide is addressed by its author.
        ->and(route('guides.browse', absolute: false))->toBe('/browse-guides');
});

/**
 * The sitemap is the one file nothing else validates, and a stale path in it is invisible until a
 * crawler reports it. It must never list a path that was MOVED (those now 301 and would advertise
 * the wrong canonical URL) — a page's own index-to-default-spec redirect is fine and is followed.
 */
test('the sitemap lists no path that has been moved away', function () {
    $xml = file_get_contents(public_path('sitemap.xml'));

    preg_match_all('#<loc>https://mindcollector\.com(/[^<]*)</loc>#', $xml, $m);
    expect($m[1])->not->toBeEmpty();

    seedMinimalWowData();

    foreach ($m[1] as $path) {
        $direct = $this->get($path);

        // A 301 here means the sitemap is pointing at an address we have retired.
        expect($direct->getStatusCode())->not->toBe(301, "sitemap lists {$path}, which permanently redirects");

        $status = $this->followingRedirects()->get($path)->getStatusCode();
        expect($status)->toBe(200, "sitemap lists {$path} which returned {$status}");
    }
});
