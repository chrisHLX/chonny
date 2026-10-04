<?php

namespace Tests\Feature;

use App\Http\Services\GameCardService;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The desktop app's per-game card (GameCardService, `wow:game-cards`), built from stored analysis
 * payloads in RoundAnalysisService's shape, so no combat log is needed. The card is HTML; these
 * read its text with the tags taken out and runs of whitespace closed up.
 */
class GameCardTest extends TestCase
{
    use RefreshDatabase;

    private function storeRound(User $user, string $matchId, string $lobby, string $bracket, string $playedAt, bool $won, array $players, array $extra = []): void
    {
        $dead = collect($players)->first(fn ($p) => $p['side'] === ($won ? 'them' : 'us') && ! $p['healer']);

        ArenaRound::create([
            'user_id' => $user->id, 'match_id' => $matchId, 'lobby_id' => $lobby, 'roster_key' => 'x', 'sequence' => 1,
            'bracket' => $bracket, 'played_at' => $playedAt,
            'payload' => ['metadata' => ['durationInSeconds' => 95], 'analysis' => [
                'version' => 4,
                'won' => $won,
                'mmr' => ['us' => 2000, 'them' => 2100],
                'players' => $players,
                'goes' => [],
                'deaths' => [[
                    't' => 92, 'who' => $dead['guid'], 'side' => $won ? 'them' : 'us',
                    'killingBlow' => ['spell' => 'Unstable Affliction', 'amount' => 38797, 'hpBefore' => 3],
                    'shares' => ['Lock-Realm-US' => 69, 'Hunt-Realm-US' => 31], 'goStartedAgo' => 16.7, 'defensives30s' => [],
                    'healer' => ['state' => $won ? 'none' : 'locked', 'endedAgo' => null, 'medallionUsedAt' => [36]],
                ]],
                'defensives' => [
                    'us' => ['spent' => 2, 'outsideTheirGoes' => 1, 'rows' => [['t' => 5, 'spell' => 'Camouflage', 'who' => 'P-2', 'outside' => true]]],
                    'them' => ['spent' => 3, 'outsideTheirGoes' => 0, 'rows' => []],
                ],
                'overlaps' => ['us' => 0, 'them' => 0, 'rows' => []],
                'kicks' => [],
                'lockout' => ['P-1' => 18.7],
            ] + $extra],
        ]);
    }

    /** A card's words, without markup. */
    private function text(array $built, string $lobby): string
    {
        $html = $built['games'][$lobby]['html'];
        $html = preg_replace('#<(style|script)[^>]*>.*?</\1>#s', '', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
    }

    private function threes(): array
    {
        return [
            ['guid' => 'P-1', 'name' => 'Healz-Realm-US', 'spec' => 'Discipline Priest', 'side' => 'us', 'healer' => true, 'logger' => true],
            ['guid' => 'P-2', 'name' => 'Hunter-Realm-US', 'spec' => 'Marksmanship Hunter', 'side' => 'us', 'healer' => false, 'logger' => false],
            ['guid' => 'E-1', 'name' => 'Lock-Realm-US', 'spec' => 'Affliction Warlock', 'side' => 'them', 'healer' => false, 'logger' => false],
            ['guid' => 'E-2', 'name' => 'Druid-Realm-US', 'spec' => 'Restoration Druid', 'side' => 'them', 'healer' => true, 'logger' => false],
        ];
    }

    public function test_a_lost_game_says_how_the_death_happened_and_what_to_look_at(): void
    {
        $user = User::factory()->create();
        $this->storeRound($user, 'm1', 'm1', '3v3', '2026-10-01 14:43:00', false, $this->threes());
        Cache::put('player_experience:v1:'.md5(mb_strtolower('Lock-Realm-US')), ['found' => true, 'exp3v3' => 2588, 'gladSeasons' => 10, 'rankOneSeasons' => 0, 'legendSeasons' => 5, 'bestRank' => 'Gladiator'], 60);

        $card = $this->text(app(GameCardService::class)->build($user), 'm1');

        $this->assertStringContainsString('LOST 3v3', $card);
        $this->assertStringContainsString('1:32 Hunter died yours', $card);
        $this->assertStringContainsString('Killing blow Unstable Affliction', $card);
        $this->assertStringContainsString('Damage in the last 10s: Lock 69%', $card);
        $this->assertStringContainsString('Hunt 31%', $card);
        $this->assertStringContainsString('Your healer locked out at the death Medallion used at 0:36', $card);
        $this->assertStringContainsString("Discipline Priest · healer · CC'd 18.7s", $card);
        $this->assertStringContainsString('10× Glad 2588 Gladiator', $card);
        $this->assertStringContainsString('not looked up yet', $card, 'experience nobody has looked up is said, not shown as zero');
        $this->assertStringContainsString('Marksmanship Hunter Camouflage while they were not in a go', $card);
        $this->assertStringContainsString('an estimate, not a verdict', $card);
    }

    public function test_experience_seen_on_an_earlier_run_outlives_its_cache(): void
    {
        $user = User::factory()->create();
        $this->storeRound($user, 'm1', 'm1', '3v3', '2026-10-01 14:43:00', true, $this->threes());
        $remembered = ['Druid-Realm-US' => ['found' => true, 'exp3v3' => 2065, 'gladSeasons' => 1, 'rankOneSeasons' => 0, 'legendSeasons' => 2, 'bestRank' => 'Gladiator']];

        $built = app(GameCardService::class)->build($user, $remembered);

        $card = $this->text($built, 'm1');
        $this->assertStringContainsString('Druid Restoration Druid · healer', $card);
        $this->assertStringContainsString('1× Glad 2065 Gladiator', $card);
        $this->assertArrayHasKey('Druid-Realm-US', $built['experience'], 'kept for the next run');
        $this->assertStringNotContainsString('Worth a look', $card, 'a win is not put through the loss rules');
    }

    public function test_a_shuffle_numbers_its_rounds_by_time_and_flags_only_your_buttons(): void
    {
        $user = User::factory()->create();
        // Stored out of order, all with sequence 1, as wow:sync writes them.
        $this->storeRound($user, 'r2', 'lobby', 'Rated Solo Shuffle', '2026-10-01 10:05:00', false, $this->threes());
        $this->storeRound($user, 'r1', 'lobby', 'Rated Solo Shuffle', '2026-10-01 10:01:00', true, $this->threes());

        $card = $this->text(app(GameCardService::class)->build($user), 'lobby');

        $this->assertStringContainsString('1-1 Rated Solo Shuffle', $card);
        $this->assertLessThan(strpos($card, 'Round 2 LOST'), strpos($card, 'Round 1 WON'));
        $this->assertStringNotContainsString('Camouflage', $card, "a teammate's button is not yours to fix in a shuffle");
    }

    public function test_notes_go_on_the_round_they_were_written_for(): void
    {
        $user = User::factory()->create();
        $this->storeRound($user, 'm1', 'm1', '3v3', '2026-10-01 14:43:00', false, $this->threes());
        $this->storeRound($user, 'm2', 'm2', '3v3', '2026-10-01 14:50:00', true, $this->threes());

        $built = app(GameCardService::class)->build($user, [], [
            ['at' => '2026-10-01 14:43:30', 'text' => 'they trained me from the gates', 'kind' => 'note'],
            ['at' => '2026-10-01 14:45:10', 'text' => 'should have trinketed the first fear', 'kind' => 'note'],
            ['at' => '2026-10-01 14:49:40', 'text' => 'stay near the pillar', 'kind' => 'note', 'roundStart' => '2026-10-01 14:50:00'],
            ['at' => '2026-10-01 14:50:40', 'text' => '', 'kind' => 'mark'],
            ['at' => '2026-10-01 13:00:00', 'text' => 'from a game we never synced', 'kind' => 'note'],
        ]);

        $first = $this->text($built, 'm1');
        $second = $this->text($built, 'm2');
        $this->assertStringContainsString('0:30 they trained me from the gates', $first);
        $this->assertStringContainsString('should have trinketed the first fear', $first, 'a note written after the game still belongs to it');
        $this->assertStringContainsString('before stay near the pillar', $second, 'a prep-room note goes to the round the app stamped it with');
        $this->assertStringContainsString('0:40 Marked', $second);
        $this->assertStringNotContainsString('never synced', $first.$second);
        $this->assertSame(2, $built['games']['m1']['notes']);
    }

    public function test_notes_written_in_the_app_go_on_that_game_or_the_next_one(): void
    {
        $user = User::factory()->create();
        $this->storeRound($user, 'm1', 'm1', '3v3', '2026-10-01 14:43:00', false, $this->threes());
        $this->storeRound($user, 'm2', 'm2', '3v3', '2026-10-01 14:50:00', true, $this->threes());

        $built = app(GameCardService::class)->build($user, [], [
            // Written an hour later against the first game: it goes there, not by its time.
            ['at' => '2026-10-01 15:55:00', 'text' => 'kept Pain Sup for the second go', 'kind' => 'note', 'game' => 'm1'],
            // Written between the two games for the next one.
            ['at' => '2026-10-01 14:46:00', 'text' => 'pillar the hunter', 'kind' => 'next'],
            // Written after the last game: waiting, on no card yet.
            ['at' => '2026-10-01 16:00:00', 'text' => 'call no pain sup', 'kind' => 'next'],
            ['at' => '2026-10-01 15:00:00', 'text' => 'for a game that is not here', 'kind' => 'note', 'game' => 'nope'],
        ]);

        $first = $this->text($built, 'm1');
        $second = $this->text($built, 'm2');
        $this->assertStringContainsString('Notes on this game kept Pain Sup for the second go', $first);
        $this->assertStringContainsString('Before it pillar the hunter', $second);
        $this->assertStringNotContainsString('pillar the hunter', $first, 'a next-game note goes on the game after it was written');
        $this->assertStringNotContainsString('call no pain sup', $first.$second, 'no game has started since: it waits');
        $this->assertStringNotContainsString('not here', $first.$second);
        $this->assertSame(1, $built['games']['m1']['notes']);
    }

    public function test_the_card_shows_damage_and_healing_by_ability_and_the_checks(): void
    {
        $user = User::factory()->create();
        $this->storeRound($user, 'm1', 'm1', '3v3', '2026-10-01 14:43:00', false, $this->threes(), [
            'version' => 5,
            'breakdown' => [
                'P-1' => ['alive' => 92, 'idle' => 18.4, 'casts' => 40,
                    'damage' => [['spell' => 'Shadow Word: Pain', 'amount' => 600000, 'hits' => 80, 'over' => 0], ['spell' => 'Penance', 'amount' => 400000, 'hits' => 20, 'over' => 0]],
                    'healing' => [['spell' => 'Atonement', 'amount' => 2000000, 'hits' => 500, 'over' => 2000000]],
                    'absorbs' => [['spell' => 'Power Word: Shield', 'amount' => 900000, 'hits' => 30, 'over' => 0]]],
                'P-2' => ['alive' => 92, 'idle' => 2, 'casts' => 70,
                    'damage' => [['spell' => 'Aimed Shot', 'amount' => 3000000, 'hits' => 25, 'over' => 0], ['spell' => 'Other', 'amount' => 100000, 'hits' => 9, 'over' => 0], ['spell' => 'pet: Bite', 'amount' => 200000, 'hits' => 40, 'over' => 0]],
                    'healing' => [], 'absorbs' => []],
            ],
            'checks' => [
                ['kind' => 'cooldown-ready', 'who' => 'P-2', 'spell' => 'Trueshot', 'ready' => 190, 'cooldown' => 120, 'presses' => 1, 'lost' => 1],
                ['kind' => 'on-immune', 'who' => 'P-1', 'spell' => 'Pain Suppression', 'on' => 'P-2', 'immunity' => 'Aspect of the Turtle', 't' => 56.4],
            ],
        ]);

        $card = $this->text(app(GameCardService::class)->build($user), 'm1');

        $this->assertStringContainsString('HealzYOU 1.00M 2.00M 900k 20%', $card, 'damage, healing, absorbs and idle share of time alive');
        $this->assertStringContainsString('Shadow Word: Pain 600k 60% 80 hits', $card);
        $this->assertStringContainsString('Atonement 2.00M 100% 500 hits · 50% over', $card);
        $this->assertStringContainsString('pet: Bite 200k', $card);
        $this->assertLessThan(strpos($card, 'Other 100k'), strpos($card, 'pet: Bite'), 'Other comes last whatever its size');
        $this->assertStringContainsString('Hunter Trueshot sat ready for 3:10 in all: at least 1 whole use lost (pressed 1 time, 120s cooldown)', $card);
        $this->assertStringContainsString('HealzYOU Pain Suppression on Hunter at 0:56, who was already in Aspect of the Turtle: it removed nothing', $card);
    }

    public function test_a_loss_shows_what_the_team_had_for_their_go_and_how_hard_it_was(): void
    {
        $user = User::factory()->create();
        $players = $this->threes();
        $peak = ['damage' => 0, 'joint' => false, 'healerLocked' => 0, 'healerKicked' => false, 'healerCcBy' => [], 'abilities' => []];
        ArenaRound::create([
            'user_id' => $user->id, 'match_id' => 'm1', 'lobby_id' => 'm1', 'roster_key' => 'x', 'sequence' => 1,
            'bracket' => '3v3', 'played_at' => '2026-10-03 11:50:00',
            'payload' => ['metadata' => ['durationInSeconds' => 41], 'analysis' => [
                'version' => 7, 'won' => false, 'mmr' => ['us' => 1950, 'them' => 2136],
                'players' => $players,
                'goes' => [['side' => 'them', 'from' => 7.2, 'to' => 30, 'good' => true, 'kill' => true, 'killLater' => true, 'chain' => '', 'peak' => $peak,
                    'links' => [
                        ['t' => 0, 'spell' => 'Bladestorm', 'cat' => 'offensive', 'role' => null, 'by' => 'E-1', 'on' => null],
                        ['t' => 8.6, 'spell' => 'Holy Word: Chastise', 'cat' => 'control', 'role' => 'healer', 'by' => 'E-2', 'on' => 'P-1'],
                    ]]],
                'deaths' => [[
                    't' => 22.8, 'who' => 'P-2', 'side' => 'us', 'killingBlow' => ['spell' => 'Execute', 'amount' => 1, 'hpBefore' => 11],
                    'shares' => [], 'goStartedAgo' => 15.6, 'defensives30s' => [],
                    'healer' => ['state' => 'locked', 'endedAgo' => null, 'medallionUsedAt' => [14.1]],
                    'answers' => ['from' => 7.2, 'lockout' => ['P-1' => ['seconds' => 8.6, 'longest' => [15.8, 24.7], 'freeBefore' => [14.1, 15.8]]], 'rows' => [
                        ['who' => 'P-1', 'spell' => 'Pain Suppression', 'kind' => 'defensive', 'cd' => 180, 'state' => 'ready', 'at' => null, 'back' => null,
                            'tried' => [['t' => 19.5, 'why' => "Can't do that while fleeing"]]],
                        ['who' => 'P-2', 'spell' => 'Roar of Sacrifice', 'kind' => 'defensive', 'cd' => 120, 'state' => 'ready', 'at' => null, 'back' => null, 'tried' => []],
                        ['who' => 'P-2', 'spell' => 'Aspect of the Turtle', 'kind' => 'defensive', 'cd' => 135, 'state' => 'ready', 'at' => null, 'back' => null, 'tried' => []],
                        ['who' => 'P-1', 'spell' => "Gladiator's Medallion", 'kind' => 'trinket', 'cd' => 120, 'state' => 'pressed', 'at' => 14.1, 'back' => null, 'tried' => []],
                        ['who' => 'P-2', 'spell' => 'Camouflage', 'kind' => 'defensive', 'cd' => 60, 'state' => 'down', 'at' => null, 'back' => 60.7, 'tried' => []],
                    ]],
                ]],
                'defensives' => ['us' => ['spent' => 1, 'outsideTheirGoes' => 1, 'rows' => [['t' => 5, 'spell' => 'Barkskin', 'who' => 'P-2', 'outside' => true, 'beforeLockout' => true]]],
                    'them' => ['spent' => 0, 'outsideTheirGoes' => 0, 'rows' => []]],
                'overlaps' => ['us' => 0, 'them' => 0, 'rows' => []], 'kicks' => [], 'lockout' => ['P-1' => 8.6],
            ]],
        ]);

        $card = $this->text(app(GameCardService::class)->build($user), 'm1');

        $this->assertStringContainsString('Harder Their MMR 186 above yours, 0 Gladiator seasons to your 0', $card);
        $this->assertStringContainsString('What your team had for their go from 0:07 to the death', $card);
        $this->assertStringContainsString('Their offensive cooldowns: Bladestorm Lock', $card);
        $this->assertStringContainsString('Their crowd control on you: Holy Word: Chastise on Healz, 0:15', $card);
        $this->assertStringContainsString('Healz locked out 8.6s of it, the longest stretch 8.9s from 0:15. Free 0:14 to 0:15 just before it', $card);
        // The dying Hunter's own defensives first, then the healer's, with the refused press.
        $this->assertStringContainsString('Ready, never pressed', $card);
        $this->assertLessThan(strpos($card, 'Pain Suppression Healz'), strpos($card, 'Roar of Sacrifice Hunter'));
        $this->assertStringContainsString('Pain Suppression Healz tried 0:19 (while fleeing)', $card);
        $this->assertStringContainsString("Pressed in their go Gladiator's Medallion Healz 0:14", $card);
        $this->assertStringContainsString('On cooldown when it began Camouflage Hunter back at 1:00', $card);
        $this->assertStringNotContainsString('Barkskin while they were not in a go', $card, 'pressed just before a lockout is not a fault');
    }

    public function test_a_game_measured_before_the_breakdown_says_how_to_get_it(): void
    {
        $user = User::factory()->create();
        $this->storeRound($user, 'm1', 'm1', '3v3', '2026-10-01 14:43:00', false, $this->threes());

        $card = $this->text(app(GameCardService::class)->build($user), 'm1');

        $this->assertStringContainsString('Not measured for this game yet', $card);
        $this->assertStringContainsString('Not checked for this game yet', $card);
    }

    public function test_the_command_writes_a_card_per_game_and_redraws_only_what_changed(): void
    {
        $user = User::factory()->create();
        $this->storeRound($user, 'm1', 'm1', '3v3', '2026-10-01 14:43:00', false, $this->threes());
        $this->storeRound($user, 'm2', 'm2', '3v3', '2026-10-01 14:50:00', true, $this->threes());
        $dir = storage_path('framework/testing/game-cards-'.uniqid());
        $notes = $dir.'-notes.json';

        try {
            $this->artisan('wow:game-cards', ['--dir' => $dir])->expectsOutputToContain('2 drawn, 0 unchanged')->assertSuccessful();
            $index = json_decode(file_get_contents("{$dir}/index.json"), true);
            $this->assertSame('3v3', $index['games']['m1']['bracket']);
            $this->assertArrayNotHasKey('html', $index['games']['m1'], 'the index stays small; the card is its own file');
            $this->assertStringContainsString('<html', file_get_contents("{$dir}/m1.html"));

            $this->artisan('wow:game-cards', ['--dir' => $dir])->expectsOutputToContain('0 drawn, 2 unchanged');

            // A note on one game redraws that game only.
            file_put_contents($notes, json_encode(['notes' => [['at' => '2026-10-01 14:43:30', 'text' => 'wall early', 'kind' => 'note']]]));
            $this->artisan('wow:game-cards', ['--dir' => $dir, '--notes' => $notes])->expectsOutputToContain('1 drawn, 1 unchanged');
            $this->assertStringContainsString('wall early', file_get_contents("{$dir}/m1.html"));

            // A card whose file has gone is drawn again; --fresh redraws them all.
            unlink("{$dir}/m2.html");
            $this->artisan('wow:game-cards', ['--dir' => $dir, '--notes' => $notes])->expectsOutputToContain('1 drawn, 1 unchanged');
            $this->artisan('wow:game-cards', ['--dir' => $dir, '--notes' => $notes, '--fresh' => true])->expectsOutputToContain('2 drawn, 0 unchanged');

            // A game no longer synced loses its card.
            ArenaRound::where('match_id', 'm2')->delete();
            $this->artisan('wow:game-cards', ['--dir' => $dir, '--notes' => $notes])->assertSuccessful();
            $this->assertFileDoesNotExist("{$dir}/m2.html");
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
            @unlink($notes);
        }
    }
}
