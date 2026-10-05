<?php

namespace Tests\Feature;

use App\Http\Services\CompLibraryService;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The desktop app's comp library (CompLibraryService, written by `wow:game-cards`): every enemy
 * comp in the player's stored games, grouped by its two DPS specs with any healer.
 */
class CompLibraryTest extends TestCase
{
    use RefreshDatabase;

    /** A TSG game: Arms and Unholy with $healer. Their go opens Storm Bolt then Strangulate on our healer. */
    private function storeTsg(User $user, string $id, string $at, bool $won, array $healer, string $bracket = '3v3'): void
    {
        $peak = ['damage' => 0, 'joint' => false, 'healerLocked' => 0, 'healerKicked' => false, 'healerCcBy' => [], 'abilities' => []];
        ArenaRound::create([
            'user_id' => $user->id, 'match_id' => $id, 'lobby_id' => $id, 'roster_key' => 'x', 'sequence' => 1, 'bracket' => $bracket, 'played_at' => $at,
            'payload' => ['metadata' => ['durationInSeconds' => 60], 'analysis' => [
                'version' => 7, 'won' => $won, 'mmr' => ['us' => 2000, 'them' => 2000],
                'players' => [
                    ['guid' => 'P-1', 'name' => 'Healz-Realm-US', 'spec' => 'Discipline Priest', 'classSlug' => 'priest', 'side' => 'us', 'healer' => true, 'logger' => true],
                    ['guid' => 'P-2', 'name' => 'Boom-Realm-US', 'spec' => 'Balance Druid', 'classSlug' => 'druid', 'side' => 'us', 'healer' => false, 'logger' => false],
                    ['guid' => 'E-1', 'name' => 'War-Realm-US', 'spec' => 'Arms Warrior', 'classSlug' => 'warrior', 'side' => 'them', 'healer' => false, 'logger' => false],
                    ['guid' => 'E-2', 'name' => 'Dk-Realm-US', 'spec' => 'Unholy Death Knight', 'classSlug' => 'deathknight', 'side' => 'them', 'healer' => false, 'logger' => false],
                    $healer + ['guid' => 'E-3', 'name' => 'Heal-Realm-US', 'side' => 'them', 'healer' => true, 'logger' => false],
                ],
                'goes' => [
                    ['side' => 'them', 'from' => 8, 'to' => 30, 'kill' => ! $won, 'killLater' => ! $won, 'target' => 'P-2', 'peak' => $peak, 'drained' => 0,
                        'forced' => [['t' => 12, 'spell' => 'Pain Suppression', 'who' => 'P-1']],
                        'cover' => ['up' => 4, 'all' => 5, 'bigDown' => 0, 'down' => []],
                        'links' => [
                            ['t' => 0, 'spell' => 'Avatar', 'cat' => 'offensive', 'role' => null, 'by' => 'E-1', 'on' => null],
                            ['t' => 0.5, 'spell' => 'Army of the Dead', 'cat' => 'offensive', 'role' => null, 'by' => 'E-2', 'on' => null],
                            ['t' => 2, 'spell' => 'Storm Bolt', 'cat' => 'control', 'role' => 'healer', 'by' => 'E-1', 'on' => 'P-1'],
                            ['t' => 5, 'spell' => 'Strangulate', 'cat' => 'control', 'role' => 'healer', 'by' => 'E-2', 'on' => 'P-1'],
                        ]],
                ],
                'deaths' => [$won
                    ? ['t' => 40, 'who' => 'E-1', 'side' => 'them', 'killingBlow' => null, 'shares' => [], 'goStartedAgo' => null, 'defensives30s' => [], 'healer' => ['state' => 'none', 'endedAgo' => null, 'medallionUsedAt' => []]]
                    : ['t' => 25, 'who' => 'P-2', 'side' => 'us', 'killingBlow' => ['spell' => 'Execute', 'amount' => 1, 'hpBefore' => 10], 'shares' => [], 'goStartedAgo' => 17, 'defensives30s' => [],
                        'healer' => ['state' => 'locked', 'endedAgo' => null, 'medallionUsedAt' => []],
                        'answers' => ['from' => 8, 'lockout' => [], 'rows' => [['who' => 'P-1', 'spell' => 'Desperate Prayer', 'kind' => 'defensive', 'cd' => 90, 'state' => 'ready', 'at' => null, 'back' => null, 'tried' => []]]]],
                ],
                'defensives' => ['us' => ['spent' => 0, 'outsideTheirGoes' => 0, 'rows' => []], 'them' => ['spent' => 0, 'outsideTheirGoes' => 0, 'rows' => []]],
                'overlaps' => ['us' => 0, 'them' => 0, 'rows' => []], 'kicks' => [], 'lockout' => [],
            ]],
        ]);
    }

    private function text(string $html): string
    {
        $html = preg_replace('#<(style|script)[^>]*>.*?</\1>#s', '', $html);
        $html = str_replace('<', ' <', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
    }

    public function test_a_comp_is_its_two_dps_specs_with_any_healer(): void
    {
        $user = User::factory()->create();
        $this->storeTsg($user, 'm1', '2026-10-03 11:50:00', false, ['spec' => 'Holy Priest', 'classSlug' => 'priest']);
        $this->storeTsg($user, 'm2', '2026-10-03 12:10:00', true, ['spec' => 'Restoration Druid', 'classSlug' => 'druid']);

        $comps = app(CompLibraryService::class)->build($user);

        $this->assertSame(['Arms Warrior+Unholy Death Knight'], array_keys($comps), 'two healers, one comp');
        $c = $comps['Arms Warrior+Unholy Death Knight'];
        $this->assertSame(['TSG', 2, 1, 1], [$c['nick'], $c['games'], $c['won'], $c['lost']]);
        $this->assertSame(['games' => 2, 'won' => 1, 'lost' => 1], $c['characters']['Healz-Realm-US']);

        $page = $this->text($c['html']);
        $this->assertStringContainsString('TSG Arms Warrior + Unholy Death Knight 2 games (1-1)', $page);
        $this->assertStringContainsString('healed by Holy Priest (1)', $page);
        $this->assertStringContainsString('Restoration Druid (1)', $page);
        $this->assertStringContainsString('Army of the Dead + Avatar 100% (2 of 2)', $page, 'the cooldowns they press together');
        // The crowd-control run seen in both goes, as numbered steps with whom each landed on.
        $this->assertStringContainsString('Seen in 2 goes 1 Storm Bolt your healer 2 Strangulate your healer', $page);
        // And in the summary that opens the section.
        $this->assertStringContainsString('They go once a game, the first at about 8s.', $page);
        $this->assertStringContainsString('Their go is usually Army of the Dead with Avatar (2 of 2 goes) .', $page);
        $this->assertStringContainsString('Their crowd control that repeats most: Storm Bolt on your healer, then Strangulate on your healer (2 goes) .', $page);
        $this->assertStringContainsString('Whom their goes were on Balance Druid 100% (2 of 2)', $page);
        $this->assertStringContainsString('Yours, in 1 loss Balance Druid 100% (1 of 1)', $page);
        $this->assertStringContainsString('Your healer locked out at it: 100% (1 of 1)', $page);
        $this->assertStringContainsString('Theirs, in 1 win Arms Warrior 100% (1 of 1)', $page);
        $this->assertStringContainsString('What their goes force from you 1 a go Pain Suppression 2×', $page);
        $this->assertStringContainsString('Desperate Prayer (you) 1 of 1', $page);
        $this->assertStringContainsString('Fewer than 10 games', $page);
    }

    public function test_a_comp_compares_its_less_and_more_experienced_teams(): void
    {
        $user = User::factory()->create();
        // Two games against a team with no Gladiator seasons (both won), two against one with 12 (both lost).
        $this->storeTsg($user, 'a', '2026-10-01 10:00:00', true, ['spec' => 'Holy Priest', 'classSlug' => 'priest']);
        $this->storeTsg($user, 'b', '2026-10-01 10:10:00', true, ['spec' => 'Holy Priest', 'classSlug' => 'priest']);
        $this->storeTsg($user, 'c', '2026-10-01 10:20:00', false, ['spec' => 'Restoration Druid', 'classSlug' => 'druid', 'name' => 'Glad-Realm-US']);
        $this->storeTsg($user, 'd', '2026-10-01 10:30:00', false, ['spec' => 'Restoration Druid', 'classSlug' => 'druid', 'name' => 'Glad-Realm-US']);
        $xp = [
            'War-Realm-US' => ['found' => true, 'gladSeasons' => 0], 'Dk-Realm-US' => ['found' => true, 'gladSeasons' => 0],
            'Heal-Realm-US' => ['found' => true, 'gladSeasons' => 0], 'Glad-Realm-US' => ['found' => true, 'gladSeasons' => 12],
        ];

        $page = $this->text(app(CompLibraryService::class)->build($user, $xp)['Arms Warrior+Unholy Death Knight']['html']);

        $this->assertStringContainsString('Less against more experienced teams', $page);
        $this->assertStringContainsString('Under 12 seasons 12 or more', $page);
        $this->assertStringContainsString('Your record 2-0 0-2', $page);
        $this->assertStringContainsString('Their Gladiator seasons, median 0 12', $page);
        $this->assertStringContainsString('4 games with experience on file: a lead', $page);
        $this->assertStringContainsString('Every game against them', $page);
        $this->assertStringContainsString('2000 / 2000 12', $page, 'each game with its MMR and their seasons');
        // Clicking a game opens every player's experience and the game's numbers.
        $this->assertStringContainsString('Glad Restoration Druid 12× Glad · best ?', $page);
        $this->assertStringContainsString('Healz YOU Discipline Priest not looked up', $page);
        $this->assertStringContainsString('Goes that killed, yours / theirs 0 / 1', $page);
        $this->assertStringContainsString('Difficulty Harder (12 Gladiator seasons to your 0)', $page);
    }

    public function test_solo_shuffle_never_counts_toward_a_3v3_comp(): void
    {
        $user = User::factory()->create();
        $this->storeTsg($user, 'm1', '2026-10-03 11:50:00', false, ['spec' => 'Holy Priest', 'classSlug' => 'priest']);
        $this->storeTsg($user, 's1', '2026-10-03 10:41:00', true, ['spec' => 'Holy Priest', 'classSlug' => 'priest'], 'Rated Solo Shuffle');

        $comps = app(CompLibraryService::class)->build($user);

        $this->assertSame(1, $comps['Arms Warrior+Unholy Death Knight']['games'], 'the 3v3 comp holds only the 3v3 game');
        $this->assertSame(1, $comps['shuffle:Arms Warrior+Unholy Death Knight']['games']);
        $this->assertSame('Solo Shuffle: Arms Warrior + Unholy Death Knight', $comps['shuffle:Arms Warrior+Unholy Death Knight']['name']);
    }

    public function test_the_command_writes_a_page_per_comp_and_lists_them(): void
    {
        $user = User::factory()->create();
        $this->storeTsg($user, 'm1', '2026-10-03 11:50:00', false, ['spec' => 'Holy Priest', 'classSlug' => 'priest']);
        $dir = storage_path('framework/testing/comps-'.uniqid());

        try {
            $this->artisan('wow:game-cards', ['--dir' => $dir])->expectsOutputToContain('1 comp page(s) drawn')->assertSuccessful();
            $index = json_decode(file_get_contents("{$dir}/index.json"), true);
            $c = $index['comps']['Arms Warrior+Unholy Death Knight'];
            $this->assertSame('TSG', $c['nick']);
            $this->assertArrayNotHasKey('html', $c, 'the index stays small');
            $this->assertStringContainsString('Their goes', file_get_contents("{$dir}/{$c['file']}"));
            $this->artisan('wow:game-cards', ['--dir' => $dir])->expectsOutputToContain('1 comp page(s) unchanged');
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }
}
