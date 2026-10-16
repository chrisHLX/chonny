<?php

namespace Tests\Feature;

use App\Http\Controllers\SitemapController;
use App\Livewire\Admin\SearchPages;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use App\Models\User;
use App\Support\SpecPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** The spec guides written for search (/wow/pvp), their numbers (SpecPages) and their tracking. */
class SpecPvpPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SpecPages::fake(null);
        parent::tearDown();
    }

    private function specs(): array
    {
        $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
        $paladin = GameClass::create(['game_id' => $game->id, 'name' => 'Paladin', 'slug' => 'paladin']);
        $priest = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);

        return [
            'ret' => Specialization::create(['class_id' => $paladin->id, 'name' => 'Retribution', 'slug' => 'retribution', 'external_spec_id' => 70]),
            'disc' => Specialization::create(['class_id' => $priest->id, 'name' => 'Discipline', 'slug' => 'discipline', 'external_spec_id' => 256]),
            'shadow' => Specialization::create(['class_id' => $priest->id, 'name' => 'Shadow', 'slug' => 'shadow', 'external_spec_id' => 258]),
        ];
    }

    /** A 3v3 round: Ret, Disc and Fire Mage against Shadow, Disc and Assassination; the Ret is the go's target. */
    private function round(int $i, bool $usWon): array
    {
        $p = fn ($spec, $side, array $talents = [], array $extra = []) => $extra + [
            'spec' => $spec, 'side' => $side, 'healer' => $spec === 256, 'logger' => false, 'won' => ($side === 'us') === $usWon,
            'pvpTalents' => $talents, 'kicks' => 0, 'died' => false, 'diedFirst' => false,
        ];

        return [
            'id' => "game{$i}", 'bracket' => '3v3', 'playedAt' => '2026-10-01', 'mmr' => ['us' => 2000 + $i, 'them' => 2010],
            'players' => [
                'ret' => $p(70, 'us', $i % 2 ? ['Hallowed Ground', 'Blessing of Sanctuary'] : ['Hallowed Ground'], ['kicks' => $i % 3 === 0 ? 1 : 0, 'died' => $i === 1, 'diedFirst' => $i === 1]),
                'disc' => $p(256, 'us', ['Ultimate Radiance']),
                'mage' => $p(63, 'us'),
                'shadow' => $p(258, 'them'),
                'disc2' => $p(256, 'them'),
                'rogue' => $p(259, 'them'),
            ],
            'goes' => [[
                'target' => 'ret',
                'answers' => [
                    ['spell' => 'Divine Protection', 'who' => 'ret', 'onTarget' => true],
                    ['spell' => 'Pain Suppression', 'who' => 'disc', 'onTarget' => true],
                ],
            ]],
        ];
    }

    public function test_the_numbers_are_counted_from_every_player_in_every_game(): void
    {
        $this->specs();
        $games = collect(range(1, 10))->map(fn ($i) => $this->round($i, $i <= 6));

        $data = SpecPages::build($games, '2026-08-01');
        $ret = $data['specs']['70'];

        $this->assertSame(10, $ret['rounds']);
        $this->assertSame(1.0, $ret['pvpTalents']['Hallowed Ground']);
        $this->assertSame(0.5, $ret['pvpTalents']['Blessing of Sanctuary']);
        $this->assertSame(0.3, $ret['interruptRounds']);
        $this->assertSame(0.1, $ret['diedFirst']);
        $this->assertSame(10, $ret['defensives']['goes']);
        $this->assertSame(1.0, $ret['defensives']['self']['Divine Protection']);
        $this->assertSame(1.0, $ret['defensives']['team']['Pain Suppression']);
        $this->assertSame([['specs' => [63, 70, 256], 'games' => 10, 'won' => 6]], $ret['comps']);
        // A Shadow Priest seen in 10 rounds gets numbers too: every player counts, not only the uploader.
        $this->assertArrayHasKey('258', $data['specs']);
    }

    public function test_a_spec_guide_reads_like_a_guide_and_is_counted_as_one(): void
    {
        $specs = $this->specs();
        SpecPages::fake(SpecPages::build(collect(range(1, 10))->map(fn ($i) => $this->round($i, $i <= 6)), '2026-08-01'));

        $html = $this->get('/wow/pvp/paladin/retribution')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Retribution Paladin PvP Guide &amp; Talents (Midnight', $html);
        $this->assertStringContainsString('Hallowed Ground', $html);
        $this->assertStringContainsString('Divine Protection', $html);
        $this->assertStringContainsString('BreadcrumbList', $html);
        $this->assertStringContainsString(route('app'), $html);
        $this->assertSame(1, PageViewEvent::where('page', 'spec_pvp')->where('spec_id', $specs['ret']->id)->count());

        $this->get('/wow/pvp')->assertOk()->assertSee('Retribution Paladin PvP guide')->assertSee('Shadow Priest PvP guide');
        $this->get('/wow/pvp/paladin/holy')->assertNotFound();     // no such spec
        $this->get('/wow/pvp/priest/holy')->assertNotFound();
    }

    public function test_a_spec_guide_says_what_to_kick_and_how_its_players_kick(): void
    {
        $this->specs();
        \App\Support\KickPriorities::fake(
            ['source' => 'Skill Capped', 'read' => '2026-10-16', 'patch' => '12.1 (Midnight Season 2)', 'specs' => ['paladin/retribution' => [
                'url' => 'https://example.test/ret', 'high' => ['Searing Glare', 'Blinding Light'], 'medium' => [], 'low' => ['Flash of Light'],
            ]]],
            ['games' => 597, 'specs' => ['paladin/retribution' => ['listed' => [
                'Searing Glare' => ['tier' => 'high', 'ids' => [410126], 'begun' => 170, 'done' => 109, 'stopped' => 18],
                'Blinding Light' => ['tier' => 'high', 'ids' => [115750], 'begun' => 0, 'done' => 90, 'stopped' => 0],
                'Flash of Light' => ['tier' => 'low', 'ids' => [19750], 'begun' => 34, 'done' => 19, 'stopped' => 1],
            ]]]],
        );
        // Every Ret met 4 high-priority casts with the kick ready and stopped 1 (version 15).
        $games = collect(range(1, 10))->map(function ($i) {
            $g = $this->round($i, true);
            $g['players']['ret']['priority'] = ['chances' => 5, 'mine' => 1, 'mate' => 1, 'landed' => 2, 'high' => ['chances' => 4, 'mine' => 1, 'landed' => 2]];

            return $g;
        });
        SpecPages::fake(SpecPages::build($games, '2026-08-01'));

        $html = $this->get('/wow/pvp/paladin/retribution')->assertOk()->getContent();
        $this->assertStringContainsString('What to kick on a Retribution Paladin', $html);
        $this->assertStringContainsString('stopped 18 of 170 casts', $html);
        $this->assertStringContainsString('Never stopped in 90 casts', $html);
        $this->assertStringContainsString('https://example.test/ret', $html);
        $this->assertStringContainsString('Retribution Paladins as kickers', $html);
        $this->assertStringContainsString('met 40 high-priority enemy casts', $html);

        // Under 20 chances, the kicker line says nothing rather than a number from a handful.
        $few = $games->map(function ($g) {
            $g['players']['ret']['priority']['high'] = ['chances' => 1, 'mine' => 0, 'landed' => 1];

            return $g;
        });
        SpecPages::fake(SpecPages::build($few, '2026-08-01'));
        $html = $this->get('/wow/pvp/paladin/retribution')->assertOk()->getContent();
        $this->assertStringContainsString('What to kick on a Retribution Paladin', $html);
        $this->assertStringNotContainsString('as kickers', $html);
        \App\Support\KickPriorities::flush();
    }

    public function test_the_sitemap_lists_the_spec_guides(): void
    {
        $this->specs();
        SpecPages::fake(SpecPages::build(collect(range(1, 10))->map(fn ($i) => $this->round($i, true)), '2026-08-01'));
        Cache::forget(SitemapController::CACHE_KEY);

        $this->get('/sitemap.xml')->assertOk()
            ->assertSee(route('spec-pvp.index'), false)
            ->assertSee(route('spec-pvp.show', ['paladin', 'retribution']), false);
    }

    public function test_the_admin_page_shows_readers_from_search_and_what_they_did_next(): void
    {
        $specs = $this->specs();
        $view = fn (string $page, ?string $session, array $extra = []) => PageViewEvent::forceCreate([
            'page' => $page, 'spec_id' => $extra['spec_id'] ?? null, 'session_id' => $session, 'is_bot' => $extra['bot'] ?? false,
            'referrer_host' => $extra['from'] ?? null, 'confirmed_at' => ($extra['confirmed'] ?? true) ? now() : null,
            'created_at' => $extra['at'] ?? now(),
        ]);
        $view('spec_pvp', 's1', ['spec_id' => $specs['ret']->id, 'from' => 'www.google.com', 'at' => now()->subMinutes(5)]);
        $view('app_page', 's1', ['at' => now()->subMinutes(4)]);
        $view('desktop_app', 's1', ['confirmed' => false, 'at' => now()->subMinutes(3)]);   // a file never confirms
        $view('spec_pvp', 's2', ['spec_id' => $specs['ret']->id, 'from' => 'mindcollector.com']);
        $view('spec_pvp', null, ['spec_id' => $specs['ret']->id, 'bot' => true, 'confirmed' => false]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->get('/admin/search-pages')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin/search-pages')->assertForbidden();

        $page = Livewire::actingAs($admin)->test(SearchPages::class);
        $total = $page->viewData('total');
        $this->assertSame(2, $total['readers']);
        $this->assertSame(1, $total['fromSearch']);
        $this->assertSame(1, $total['crawler']);
        $this->assertSame(['app_page' => 1, 'desktop_app' => 1, 'app_connect' => 0], $total['then']);
        $this->assertSame(['google.com' => 1], $page->viewData('engines')->all());
    }
}
