<?php

namespace Tests\Feature;

use App\Http\Services\ClassLibraryService;
use App\Models\ArenaRound;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The desktop app's Classes page (ClassLibraryService, written by `wow:game-cards`): for each spec
 * met, the player met at the highest team MMR and the one with the most Gladiator seasons, each with
 * a page of what they pressed beside the spec's median and beside you.
 */
class ClassLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
        $mage = GameClass::create(['game_id' => $game->id, 'name' => 'Mage', 'slug' => 'mage']);
        $druid = GameClass::create(['game_id' => $game->id, 'name' => 'Druid', 'slug' => 'druid']);
        Specialization::create(['class_id' => $mage->id, 'name' => 'Frost', 'slug' => 'frost', 'external_spec_id' => 64]);
        Specialization::create(['class_id' => $druid->id, 'name' => 'Feral', 'slug' => 'feral', 'external_spec_id' => 103]);
    }

    /** One stored round: you (a Feral) against a Frost Mage called $mage, with their presses. */
    private function store(User $user, string $id, string $mage, ?int $mmr, bool $won, string $bracket = '3v3', int $frostbolts = 20, array $enemy = ['Frost Mage', 64, 'mage']): void
    {
        $habits = fn (array $casts, int $kicks = 0, array $kicked = []) => ['casts' => $casts, 'free' => 120.0, 'control' => 4, 'offTarget' => 2,
            'petHits' => 0, 'petOnTarget' => 0, 'kicks' => $kicks, 'kicked' => $kicked];
        $breakdown = fn (array $damage) => ['alive' => 120.0, 'idle' => 12.0, 'casts' => 50, 'damage' => $damage, 'healing' => [], 'absorbs' => []];
        ArenaRound::create([
            'user_id' => $user->id, 'match_id' => $id, 'lobby_id' => $id, 'roster_key' => 'x', 'sequence' => 1, 'bracket' => $bracket, 'played_at' => '2026-10-07 '.substr($id, -2).':00:00',
            'payload' => ['metadata' => ['durationInSeconds' => 120], 'analysis' => [
                'version' => 10, 'won' => $won, 'mmr' => ['us' => $mmr, 'them' => $mmr],
                'players' => [
                    ['guid' => 'P-1', 'name' => 'Crawl-Realm-US', 'spec' => 'Feral Druid', 'specExternalId' => 103, 'classSlug' => 'druid', 'side' => 'us', 'healer' => false, 'logger' => true],
                    ['guid' => 'E-1', 'name' => $mage.'-Realm-US', 'spec' => $enemy[0], 'specExternalId' => $enemy[1], 'classSlug' => $enemy[2], 'side' => 'them', 'healer' => false, 'logger' => false],
                ],
                'habits' => [
                    'P-1' => $habits(['Shred' => 10, 'Rake' => 8]),
                    'E-1' => $habits(['Frostbolt' => $frostbolts, 'Icy Veins' => 1, 'Polymorph' => 4, 'Shred' => 24], 2, ['Polymorph' => 1]),
                ],
                'breakdown' => [
                    'P-1' => $breakdown([['spell' => 'Shred', 'amount' => 900000, 'hits' => 10, 'over' => 0]]),
                    'E-1' => $breakdown([['spell' => 'Frostbolt', 'amount' => 3000000, 'hits' => 20, 'over' => 0], ['spell' => 'Ice Lance', 'amount' => 1000000, 'hits' => 9, 'over' => 0]]),
                ],
                'goes' => [
                    ['side' => 'them', 'from' => 10, 'to' => 30, 'kill' => null, 'killLater' => false, 'target' => 'P-1',
                        'links' => [
                            ['t' => 0, 'spell' => 'Icy Veins', 'cat' => 'offensive', 'role' => null, 'by' => 'E-1', 'on' => null],
                            ['t' => 2, 'spell' => 'Polymorph', 'cat' => 'control', 'role' => 'healer', 'by' => 'E-1', 'on' => 'P-9'],
                        ]],
                ],
                'deaths' => [], 'kicks' => [], 'overlaps' => ['us' => 0, 'them' => 0, 'rows' => []],
                'lockout' => ['E-1' => 6.0, 'P-1' => 12.0],
                'defensives' => ['us' => ['spent' => 0, 'outsideTheirGoes' => 0, 'rows' => []], 'them' => ['spent' => 1, 'outsideTheirGoes' => 1, 'rows' => [
                    ['t' => 50, 'spell' => 'Ice Block', 'who' => 'E-1', 'outside' => true, 'beforeLockout' => false, 'hp' => 22, 'needed' => true],
                ]]],
            ]],
        ]);
    }

    private function text(string $html): string
    {
        $html = preg_replace('#<(style|script)[^>]*>.*?</\1>#s', '', $html);
        $html = preg_replace('#<tr class="tip".*?</tr>#s', '', $html);
        $html = str_replace('<', ' <', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
    }

    public function test_each_spec_lists_its_highest_rated_and_most_experienced_player(): void
    {
        $user = User::factory()->create();
        $this->store($user, 'r01', 'Low', 2100, true);
        $this->store($user, 'r02', 'High', 2400, false);
        $this->store($user, 'r03', 'Glad', null, false, 'Rated Solo Shuffle');
        $xp = [
            'Low-Realm-US' => ['found' => true, 'gladSeasons' => 0, 'exp3v3' => 2200],
            'High-Realm-US' => ['found' => true, 'gladSeasons' => 1, 'exp3v3' => 2450],
            'Glad-Realm-US' => ['found' => true, 'gladSeasons' => 9, 'rankOneSeasons' => 1, 'exp3v3' => 3000, 'bestRank' => 'Gladiator'],
        ];

        $players = app(ClassLibraryService::class)->build($user, $xp);

        $this->assertEqualsCanonicalizing(['High-Realm-US', 'Glad-Realm-US'], array_keys($players), 'one rated, one experienced; the rest are not listed');
        $this->assertSame(['mmr' => 2400, 'bracket' => '3v3'], $players['High-Realm-US']['picks']['rated']);
        $this->assertNull($players['High-Realm-US']['picks']['experienced']);
        $this->assertNull($players['Glad-Realm-US']['picks']['rated'], 'a shuffle round has no team MMR');
        $this->assertSame('Mage', $players['Glad-Realm-US']['class']);
        $this->assertSame('9× Glad, 1× R1, best 3000', $players['Glad-Realm-US']['why']);
        $this->assertSame('2400 3v3 MMR · 1× Glad, best 2450', $players['High-Realm-US']['why']);
        $this->assertSame([1, 0, 1], [$players['High-Realm-US']['games'], $players['High-Realm-US']['won'], $players['High-Realm-US']['lost']]);
    }

    public function test_a_player_page_shows_what_they_pressed(): void
    {
        $user = User::factory()->create();
        $this->store($user, 'r01', 'Glad', 2300, false, '3v3', 40);
        $this->store($user, 'r02', 'Glad', 2300, true, '3v3', 20);

        $page = $this->text(app(ClassLibraryService::class)->build($user, ['Glad-Realm-US' => ['found' => true, 'gladSeasons' => 9, 'exp3v3' => 3000]])['Glad-Realm-US']['html']);

        $this->assertStringContainsString('Glad Frost Mage · Realm-US', $page);
        $this->assertStringContainsString('Highest rated Frost Mage you met: 2300 3v3 MMR', $page);
        $this->assertStringContainsString('Most experienced Frost Mage you met', $page);
        $this->assertStringContainsString('you played them 2 rounds (1-1)', $page);
        // 60 Frostbolts in 4 minutes free: 15 a minute. You never play Frost, so "You" is blank.
        $this->assertStringContainsString('over 2 rounds, 4 min', $page);
        $this->assertMatchesRegularExpression('/Frostbolt 15 \S+ -/', $page);
        $this->assertStringContainsString('Polymorph 2×: your healer 2', $page, 'their crowd control in their goes, by whom it landed on');
        $this->assertStringContainsString('Icy Veins 100% of goes', $page);
        $this->assertStringContainsString('Ice Block 2×, at 22% health', $page);
        $this->assertStringContainsString('2 outside your goes', $page);
        $this->assertStringContainsString('Their casts your team kicked Polymorph 2×', $page);
        $this->assertStringContainsString('Damage Frostbolt 75%', $page);
    }

    public function test_a_player_of_your_own_spec_is_set_beside_you(): void
    {
        $user = User::factory()->create();
        // They pressed Shred 24 times in 2 minutes free (12 a minute); you, 10 times (5 a minute).
        $this->store($user, 'r01', 'Kitty', 2300, false, '3v3', 20, ['Feral Druid', 103, 'druid']);

        $page = $this->text(app(ClassLibraryService::class)->build($user)['Kitty-Realm-US']['html']);

        $this->assertStringContainsString('You: your own 1 round of the spec.', $page);
        $this->assertMatchesRegularExpression('/Shred 12 \S+ 5\.0/', $page);
        $this->assertStringContainsString('and you on the spec', $page);
    }

    public function test_the_command_writes_a_page_per_player_and_lists_them(): void
    {
        $user = User::factory()->create();
        $this->store($user, 'r01', 'Glad', 2300, false);
        $dir = storage_path('framework/testing/classes-'.uniqid());

        try {
            $this->artisan('wow:game-cards', ['--dir' => $dir])->expectsOutputToContain('1 player page(s) drawn')->assertSuccessful();
            $index = json_decode(file_get_contents("{$dir}/index.json"), true);
            $p = $index['classes']['Glad-Realm-US'];
            $this->assertSame(['Glad', 'Frost Mage', 'Mage'], [$p['name'], $p['spec'], $p['class']]);
            $this->assertArrayNotHasKey('html', $p, 'the index stays small');
            $this->assertMatchesRegularExpression('/^player-[0-9a-f]{10}\.html$/', $p['file'], 'a name the website serves');
            $this->assertStringContainsString('What they press', file_get_contents("{$dir}/{$p['file']}"));
            $this->artisan('wow:game-cards', ['--dir' => $dir])->expectsOutputToContain('1 player page(s) unchanged');
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }
}
