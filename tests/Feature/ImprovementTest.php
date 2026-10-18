<?php

namespace Tests\Feature;

use App\Http\Services\CooldownLedgerService;
use App\Http\Services\ImprovementService;
use App\Models\ArenaRound;
use App\Models\Game;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellEffect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The desktop app's Improve page (ImprovementService, written by `wow:game-cards`): each of your
 * characters measured against every other player of the same spec in your stored games. Built
 * from payloads in RoundAnalysisService's version 6 shape, so no combat log is needed.
 */
class ImprovementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What counts as a dispel comes from the spell data: a Dispel effect. Phantasm has none, so the
     * slow it strips when a Priest fades is not a dispel.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $game = Game::create(['name' => 'WoW', 'slug' => 'wow']);
        $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.1.0.test', 'is_current' => true]);
        foreach ([[527, 'Purify', 'Dispel (38)'], [32375, 'Mass Dispel', 'Dispel (38)'], [108942, 'Phantasm', 'Proc Trigger Spell (42): Phantasm']] as [$id, $name, $type]) {
            $spell = Spell::create(['patch_id' => $patch->id, 'spell_id' => $id, 'name' => $name]);
            SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'type' => $type]);
        }
    }

    private function players(): array
    {
        return [
            ['guid' => 'P-1', 'name' => 'Healz-Realm-US', 'spec' => 'Discipline Priest', 'classSlug' => 'priest', 'side' => 'us', 'healer' => true, 'logger' => true],
            ['guid' => 'P-2', 'name' => 'Hunter-Realm-US', 'spec' => 'Marksmanship Hunter', 'classSlug' => 'hunter', 'side' => 'us', 'healer' => false, 'logger' => false],
            ['guid' => 'E-1', 'name' => 'Other-Realm-US', 'spec' => 'Discipline Priest', 'classSlug' => 'priest', 'side' => 'them', 'healer' => true, 'logger' => false],
            ['guid' => 'E-2', 'name' => 'Lock-Realm-US', 'spec' => 'Affliction Warlock', 'classSlug' => 'warlock', 'side' => 'them', 'healer' => false, 'logger' => false],
        ];
    }

    /**
     * One lost 2-minute game. Both teams carry a minute of Polymorph; the other Disc Priest takes it
     * off six times, ours once. Our healer is locked out 30s, theirs 6s, and our Hunter dies while
     * our healer is locked out with the Medallion never pressed.
     */
    private function storeRound(User $user, int $i, int $version = 6, string $day = '2026-10-01', int $ourDispels = 1): void
    {
        $analysis = [
            'version' => $version,
            'won' => false,
            'mmr' => ['us' => 2000, 'them' => 2050],
            'players' => $this->players(),
            'goes' => [
                ['side' => 'us', 'from' => 10, 'to' => 40, 'kill' => false, 'killLater' => false, 'healerCcBy' => ['P-1'],
                    'links' => [['t' => 0, 'spell' => 'Psychic Scream', 'cat' => 'control', 'role' => 'healer', 'by' => 'P-1', 'on' => 'E-1']],
                    'peak' => ['damage' => 0, 'joint' => false, 'healerLocked' => 0, 'healerKicked' => false, 'healerCcBy' => [], 'abilities' => []],
                    'cover' => ['up' => 5, 'all' => 5, 'bigDown' => 0, 'down' => []]],
                ['side' => 'them', 'from' => 80, 'to' => 110, 'kill' => true, 'killLater' => true, 'healerCcBy' => [],
                    'links' => [], 'peak' => ['damage' => 0, 'joint' => false, 'healerLocked' => 0, 'healerKicked' => false, 'healerCcBy' => [], 'abilities' => []],
                    'cover' => ['up' => 2, 'all' => 5, 'bigDown' => 2, 'down' => ['Pain Suppression', 'Survival of the Fittest']]],
            ],
            'deaths' => [[
                't' => 100, 'who' => 'P-2', 'side' => 'us', 'killingBlow' => null, 'shares' => [], 'goStartedAgo' => 20, 'defensives30s' => [],
                'healer' => ['state' => 'locked', 'endedAgo' => null, 'medallionUsedAt' => []],
            ]],
            'defensives' => [
                'us' => ['spent' => 1, 'outsideTheirGoes' => 0, 'rows' => [['t' => 85, 'spell' => 'Pain Suppression', 'who' => 'P-1', 'outside' => false, 'needed' => true]]],
                'them' => ['spent' => 1, 'outsideTheirGoes' => 1, 'rows' => [['t' => 50, 'spell' => 'Pain Suppression', 'who' => 'E-1', 'outside' => true, 'needed' => false]]],
            ],
            'overlaps' => ['us' => 0, 'them' => 0, 'rows' => []],
            'kicks' => [],
            'lockout' => ['P-1' => 30, 'E-1' => 6],
            // Two minutes alive each. Ours: 600k damage (half of it Shadow Word: Pain), 3M healing and
            // absorbs, 20% idle. Theirs: 1.2M damage, all Penance, 1.5M healing, 10% idle.
            'breakdown' => [
                'P-1' => ['alive' => 120, 'idle' => 24, 'casts' => 50,
                    'damage' => [['spell' => 'Shadow Word: Pain', 'amount' => 300000, 'hits' => 40, 'over' => 0], ['spell' => 'Penance', 'amount' => 300000, 'hits' => 10, 'over' => 0]],
                    'healing' => [['spell' => 'Atonement', 'amount' => 2400000, 'hits' => 300, 'over' => 500000]],
                    'absorbs' => [['spell' => 'Power Word: Shield', 'amount' => 600000, 'hits' => 20, 'over' => 0]]],
                'E-1' => ['alive' => 120, 'idle' => 12, 'casts' => 60,
                    'damage' => [['spell' => 'Penance', 'amount' => 1200000, 'hits' => 30, 'over' => 0]],
                    'healing' => [['spell' => 'Atonement', 'amount' => 1500000, 'hits' => 200, 'over' => 0]],
                    'absorbs' => []],
            ],
        ];

        if ($version >= 6) {
            $analysis['dispels'] = array_merge(
                array_fill(0, $ourDispels, ['t' => 20, 'by' => 'P-1', 'on' => 'P-2', 'spell' => 'Purify', 'removed' => 'Polymorph']),
                array_fill(0, 6, ['t' => 30, 'by' => 'E-1', 'on' => 'E-2', 'spell' => 'Purify', 'removed' => 'Polymorph']),
                // A Mass Dispel is a long cooldown: it neither counts as a dispel nor makes its debuff "dispellable".
                [['t' => 40, 'by' => 'E-1', 'on' => 'E-2', 'spell' => 'Mass Dispel', 'removed' => 'Cyclone']],
                // Phantasm stripping a slow from our Priest as they fade: in the log, not a dispel.
                [['t' => 25, 'by' => 'P-1', 'on' => 'P-1', 'spell' => 'Phantasm', 'removed' => 'Crippling Poison']],
            );
            $analysis['debuffs'] = [
                'P-1' => [['Crippling Poison', 0, 30]],
                'P-2' => [['Polymorph', 0, 60], ['Cyclone', 60, 66]],
                'E-2' => [['Polymorph', 0, 60]],
            ];
        }

        ArenaRound::create([
            'user_id' => $user->id, 'match_id' => "m{$i}", 'lobby_id' => "m{$i}", 'roster_key' => 'x', 'sequence' => 1,
            'bracket' => '3v3', 'played_at' => sprintf('%s 14:%02d:00', $day, $i),
            'payload' => ['metadata' => ['durationInSeconds' => 120], 'analysis' => $analysis,
                // Each team took 3M.
                'throughput' => ['players' => ['P-1' => ['damageTaken' => 1000000], 'P-2' => ['damageTaken' => 2000000], 'E-1' => ['damageTaken' => 1000000], 'E-2' => ['damageTaken' => 2000000]]]],
        ]);
    }

    private function text(string $html): string
    {
        $html = preg_replace('#<(style|script)[^>]*>.*?</\1>#s', '', $html);
        // A space where each tag was: the page's numbers sit in blocks of their own.
        $html = str_replace('<', ' <', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
    }

    public function test_a_character_is_measured_against_the_other_players_of_its_spec(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 10) as $i) {
            $this->storeRound($user, $i);
        }

        $pages = app(ImprovementService::class)->build($user);

        $this->assertSame(['Healz-Realm-US'], array_keys($pages), 'only the logging character gets a page');
        $page = $this->text($pages['Healz-Realm-US']['html']);

        $this->assertStringContainsString('Healz Discipline Priest · 10 games measured (0-10;', $page);
        $this->assertStringContainsString('against 1 other Discipline Priest in them (10 games, both teams)', $page);
        // Dispels: 1 removed per minute of Polymorph against the other Disc Priest's 6.
        $this->assertStringContainsString('Dispels Behind other Discipline Priests', $page);
        $this->assertStringContainsString('You 1.00 10 games Other Discipline Priests 6.00', $page);
        $this->assertStringContainsString('Your team carried one for 30s a minute (30s for the others)', $page, 'Cyclone, only ever Mass Dispelled, is not counted');
        $this->assertStringContainsString('Left on your team most: Polymorph 60s a game', $page);
        $this->assertStringNotContainsString('Crippling Poison', $page, 'only Phantasm removed it: no dispel could have');
        // Locked out 15s a minute against 3s.
        $this->assertStringContainsString('Time locked out Behind other Discipline Priests', $page);
        $this->assertStringContainsString('You 15.0s', $page);
        $this->assertStringContainsString('Their go killed one of you: none down no goes, one down no goes, two or more 100% (10 of 10).', $page);
        $this->assertStringContainsString('A teammate died while you were locked out with your Medallion ready: 10 times in 10 games.', $page);
        $this->assertStringContainsString('Pressed with the target in danger or to break crowd control: 100% of yours, 0% of theirs', $page, 'version 9: was it needed');
        $this->assertStringContainsString('Psychic Scream 10×', $page);
        $this->assertStringContainsString('Crowd control on their healer Ahead of other Discipline Priests', $page, 'any against their none is ahead, not level');
        $this->assertLessThan(strpos($page, 'Ahead of other'), strpos($page, 'Behind other'), 'behind first');
        $this->assertStringContainsString('10 games measured (0-10; a Solo Shuffle round counts as one)', $page);

        // Damage per minute alive, and its mix beside the other Disc Priest's.
        $this->assertStringContainsString('Damage Behind other Discipline Priests', $page);
        $this->assertStringContainsString('You 300k', $page);
        $this->assertStringContainsString('Shadow Word: Pain 50% · others 0%', $page);
        $this->assertStringContainsString('Penance 50% · others 100%', $page);
        // Healing against the team's damage taken: 3M of 3M against 1.5M of 3M.
        $this->assertStringContainsString('Healing Ahead of other Discipline Priests', $page);
        $this->assertStringContainsString('You 100%', $page);
        $this->assertStringContainsString('1,500k a minute alive (750k for the others)', $page);
        $this->assertStringContainsString('Atonement 80% · others 100%', $page);
        $this->assertStringContainsString('Time not pressing anything Behind other Discipline Priests', $page);
    }

    public function test_behind_says_why_and_the_rotation_says_what_is_pressed(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 10) as $i) {
            $this->storeRound($user, $i, 16);
            $round = ArenaRound::where('match_id', "m{$i}")->first();
            $payload = $round->payload;
            // Presses and time free to act (version 10): ours 5 Penance and 20 Shadow Word: Pain in 90s
            // free; theirs 15 Penance in 114s.
            $payload['analysis']['habits'] = [
                'P-1' => ['casts' => ['Penance' => 5, 'Shadow Word: Pain' => 20], 'free' => 90, 'kicked' => []],
                'E-1' => ['casts' => ['Penance' => 15], 'free' => 114, 'kicked' => []],
            ];
            // The timeline (version 16): our priest stunned for the first 30s of the minute of Polymorph
            // on our Hunter, casting three Penance and one Purify in it; theirs free, casting six Purify.
            $payload['analysis']['timeline'] = [
                'casts' => [
                    'P-1' => [[32, 'Penance', 'E-2'], [36, 'Penance', 'E-2'], [40, 'Penance', 'E-2'], [44, 'Purify', 'P-2']],
                    'E-1' => array_map(fn ($t) => [$t, 'Purify', 'E-2'], [5, 10, 15, 20, 25, 30]),
                ],
                'hp' => ['P-1' => array_fill(0, 121, 90), 'E-1' => array_fill(0, 121, 90)],
                'control' => [['P-1', 'E-2', 'Fear', 'Disorient', 0.0, 30.0]],
                'defUp' => [],
            ];
            $round->update(['payload' => $payload]);
        }

        $page = $this->text(app(ImprovementService::class)->build($user)['Healz-Realm-US']['html']);

        // Damage: 150k a minute of Penance against 600k, and pressed 3.3 times a free minute against 7.9.
        $this->assertStringContainsString('Why yours is lower Penance: 150k a minute against 600k for them. You press it 3.3 times a free minute, they press it 7.9.', $page);
        // Dispels: locked out half the minute of Polymorph; their free time went on Purify.
        $this->assertStringContainsString('While a debuff you could dispel was on your team, you were locked out 50% of the time, they 0%.', $page);
        $this->assertStringContainsString('In that time your casts were mostly Penance (75%), Purify (25%). Dispels were 25% of your casts then, 100% of theirs.', $page);
        // The rotation: presses per minute free, Penance marked.
        $this->assertStringContainsString('Your rotation', $page);
        // The third column is the spec's middle player in the norms (the committed or live file).
        $this->assertMatchesRegularExpression('/Penance 3\.3 7\.9 \S+ you press it less/', $page);
    }

    public function test_deaths_with_a_fitting_defensive_ready_are_counted_across_games(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 16) as $i) {
            $this->storeRound($user, $i);
            // The Hunter dies each time with Pain Suppression (reaches them) and Desperate Prayer (the
            // priest's own) ready. In the first four the priest was locked out the whole go: not counted.
            $round = ArenaRound::where('match_id', "m{$i}")->first();
            $payload = $round->payload;
            $payload['analysis']['deaths'][0]['answers'] = ['from' => 80, 'lockout' => ['P-1' => ['seconds' => $i <= 4 ? 19.5 : 6, 'longest' => [85, 91], 'freeBefore' => null]], 'rows' => [
                ['who' => 'P-1', 'spell' => 'Pain Suppression', 'kind' => 'defensive', 'cd' => 180, 'state' => 'ready', 'at' => null, 'back' => null, 'tried' => []],
                ['who' => 'P-1', 'spell' => 'Desperate Prayer', 'kind' => 'defensive', 'cd' => 90, 'state' => 'ready', 'at' => null, 'back' => null, 'tried' => []],
                ['who' => 'P-1', 'spell' => 'Psychic Scream', 'kind' => 'control', 'cd' => 45, 'state' => 'ready', 'at' => null, 'back' => null, 'tried' => []],
                ['who' => 'P-2', 'spell' => 'Aspect of the Turtle', 'kind' => 'defensive', 'cd' => 180, 'state' => 'ready', 'at' => null, 'back' => null, 'tried' => []],
            ]];
            $round->update(['payload' => $payload]);
        }

        $page = $this->text(app(ImprovementService::class)->build($user)['Healz-Realm-US']['html']);

        $this->assertStringContainsString('Deaths with a fitting defensive of yours ready', $page);
        $this->assertStringContainsString('You 75% 16 games', $page, '12 of 16: the four locked-out ones are not counted');
        $this->assertStringContainsString('In 12 of your last 15.', $page);
        $this->assertStringContainsString('Ready most often: Pain Suppression 12 of 16', $page);
        $this->assertStringNotContainsString('Desperate Prayer', $page, "the priest's own heal could not have saved the Hunter");
        $this->assertStringNotContainsString('Aspect of the Turtle', $page, "the Hunter's own button is not yours");
    }

    public function test_the_page_names_one_focus_and_measures_the_last_session(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 10) as $i) {
            $this->storeRound($user, $i);   // 2026-10-01
        }
        $this->storeRound($user, 11);
        ArenaRound::where('match_id', 'm11')->update(['played_at' => '2026-10-02 09:00:00']);

        $page = $this->text(app(ImprovementService::class)->build($user)['Healz-Realm-US']['html']);

        // Their goes started with two big defensives down in all of yours and none of theirs: any
        // against the others' none is the furthest behind, so it is the one focus.
        $this->assertStringContainsString('Your focus Big defensives when their go started you 100% against 0% for other Discipline Priests', $page);
        // One session before it: too few to give a range, so no verdict on it either way.
        $this->assertStringContainsString('Last session (Fri 2 Oct, 1 game): 100% . Against 100% before it: too few sessions of 3+ games yet to say whether it stands out.', $page);
        $this->assertStringContainsString('Last session (Fri 2 Oct): 15.0s, against 15.0s before it.', $page, 'every habit carries its last session');
        $this->assertLessThan(strpos($page, 'Where you stand'), strpos($page, 'Your focus'), 'the focus comes first');
    }

    public function test_a_session_is_read_against_the_range_of_the_sessions_before_it(): void
    {
        $user = User::factory()->create();
        // Four sessions of three games: one dispel a game, then one, then two, then four.
        $n = 0;
        foreach (['2026-10-01' => 1, '2026-10-02' => 1, '2026-10-03' => 2, '2026-10-04' => 4] as $day => $dispels) {
            foreach (range(1, 3) as $i) {
                $this->storeRound($user, ++$n, day: $day, ourDispels: $dispels);
            }
        }

        $html = app(ImprovementService::class)->build($user)['Healz-Realm-US']['html'];
        $page = $this->text($html);

        // A minute of Polymorph a game: 1.00, 1.00, 2.00 and 4.00 a minute.
        $this->assertStringContainsString('Last session (Sun 4 Oct): 4.00; your sessions before it ran 1.00 to 2.00. your best session yet', $page);
        // The same every session: inside the range, so no chip on the habit.
        $this->assertStringContainsString('Last session (Sun 4 Oct): 15.0s; your sessions before it ran 15.0s to 15.0s.', $page);
        $this->assertStringNotContainsString('15.0s to 15.0s. your', $page);

        // One line per habit by session, four points each, with the others' level dashed.
        preg_match_all('/<polyline[^>]*points="([^"]+)"/', $html, $lines);
        $this->assertNotEmpty($lines[1]);
        foreach ($lines[1] as $points) {
            $this->assertCount(4, explode(' ', $points));
        }
        $this->assertStringContainsString('stroke-dasharray', $html);
        $this->assertStringContainsString('By session, 1 Oct to 4 Oct', $page, 'the focus carries the larger chart');

        // Lower is better for time locked out: a session under the range is the best yet.
        $session = new \ReflectionMethod(ImprovementService::class, 'session');
        $read = fn (array $vs, bool $more) => $session->invoke(app(ImprovementService::class), [
            'series' => array_map(fn ($v, $i) => ['day' => '2026-10-0'.($i + 1), 'v' => $v, 'n' => 3], $vs, array_keys($vs)),
            'beforeLast' => 1.0,
        ], $more, fn ($v) => (string) $v)['moved'];
        $this->assertSame('best', $read([10, 12, 14, 8], false));
        $this->assertSame('worst', $read([10, 12, 14, 8], true));
        $this->assertSame('within', $read([10, 12, 14, 11], true));
        $this->assertNull($read([10, 12, 11], true), 'two sessions before it are too few for a range');
    }

    public function test_the_focus_is_what_decides_games_first_and_never_a_healers_damage(): void
    {
        $focus = new \ReflectionMethod(ImprovementService::class, 'focus');
        $pick = fn (array $keys, bool $healer) => $focus->invoke(app(ImprovementService::class),
            array_map(fn ($k) => ['key' => $k, 'status' => 'behind'], $keys), $healer)['key'] ?? null;

        // Damage, then defensive use, then crowd control, whatever order they arrive in; dispels last.
        $this->assertSame('damage', $pick(['dispels', 'idle', 'damage'], false), "a damage dealer's damage comes first");
        $this->assertSame('ready', $pick(['dispels', 'cc', 'ready'], true), 'defensive use before crowd control');
        $this->assertSame('cc', $pick(['dispels', 'priorityKicks', 'cc'], true), 'crowd control before dispels');
        // Behind only on what does not decide games: no focus, and the page lists them as smaller gaps.
        $this->assertNull($pick(['damage', 'idle'], true), "a Holy Paladin's damage gap and idle time are not the focus");
        $this->assertNull($pick(['dispels', 'priorityKicks'], false));
        $this->assertNull($pick(['damage'], true));
        $this->assertSame('healing', $pick(['damage', 'healing'], true));
    }

    public function test_the_list_runs_in_order_of_what_decides_games_with_the_archive_beside_each(): void
    {
        $rank = new \ReflectionMethod(ImprovementService::class, 'rank');
        $service = app(ImprovementService::class);
        $this->assertLessThan($rank->invoke($service, 'dispels', true), $rank->invoke($service, 'cc', true));
        $this->assertLessThan($rank->invoke($service, 'cover', false), $rank->invoke($service, 'damage', false));
        $this->assertGreaterThan($rank->invoke($service, 'dispels', true), $rank->invoke($service, 'damage', true), "a healer's damage goes last");

        $evidence = new \ReflectionMethod(ImprovementService::class, 'evidence');
        $line = $evidence->invoke($service, 'damage', false);
        $this->assertMatchesRegularExpression("/^Damage dealers above their spec's middle on damage won \\d+% of rounds, below it \\d+%\\.$/", (string) $line);
        $this->assertNull($evidence->invoke($service, 'damage', true), 'no damage line for a healer');
        $this->assertSame('Not yet set against wins across the archive.', $evidence->invoke($service, 'dispels', true));
    }

    public function test_fewer_than_ten_games_is_a_lead_and_older_games_say_how_to_measure_them(): void
    {
        $user = User::factory()->create();
        $this->storeRound($user, 1);
        $this->storeRound($user, 2, version: 5);

        $page = $this->text(app(ImprovementService::class)->build($user)['Healz-Realm-US']['html']);

        $this->assertStringContainsString('A lead: under 10 games on one side', $page);
        $this->assertStringNotContainsString('Behind other', $page, 'two games cannot put anyone behind');
        $this->assertStringContainsString('measured from games synced since 3 Oct: 1 of your 2 so far', $page);
        $this->assertStringContainsString('wow:sync --skip-ingest --fresh', $page);
    }

    public function test_the_command_writes_a_page_per_character_and_lists_them_in_the_index(): void
    {
        $user = User::factory()->create();
        $this->storeRound($user, 1);
        $dir = storage_path('framework/testing/improve-'.uniqid());

        try {
            $this->artisan('wow:game-cards', ['--dir' => $dir])->expectsOutputToContain('1 Improve page(s) drawn')->assertSuccessful();
            $index = json_decode(file_get_contents("{$dir}/index.json"), true);
            $c = $index['characters']['Healz-Realm-US'];
            $this->assertSame(['Healz', 'Discipline Priest', 1], [$c['name'], $c['spec'], $c['games']]);
            $this->assertStringContainsString('Where you stand', file_get_contents("{$dir}/{$c['file']}"));

            // Nothing changed: not drawn again.
            $this->artisan('wow:game-cards', ['--dir' => $dir])->expectsOutputToContain('1 Improve page(s) unchanged');
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }

    public function test_the_ledger_counts_charges_and_big_answers(): void
    {
        $answers = [
            ['who' => 'A', 'spell' => 'Pain Suppression', 'cd' => 180, 'charges' => 2],
            ['who' => 'A', 'spell' => 'Desperate Prayer', 'cd' => 90, 'charges' => 1],
            ['who' => 'B', 'spell' => 'Barkskin', 'cd' => 60, 'charges' => 1],
            ['who' => 'B', 'spell' => CooldownLedgerService::MEDALLION, 'cd' => 120, 'charges' => 1],
        ];
        $presses = ['A' => ['Pain Suppression' => [10], 'Desperate Prayer' => [20]], 'B' => ['Barkskin' => [25], CooldownLedgerService::MEDALLION => [5]]];

        $at30 = CooldownLedgerService::coverage($answers, $presses, 30);
        $this->assertSame(['up' => 1, 'all' => 3, 'bigDown' => 1], array_intersect_key($at30, ['up' => 1, 'all' => 1, 'bigDown' => 1]),
            'one Pain Suppression charge left; Desperate Prayer (90s, big) and Barkskin (60s, not big) down; the Medallion is not counted');
        $this->assertSame(['Desperate Prayer', 'Barkskin'], $at30['down']);

        $presses['A']['Pain Suppression'][] = 15;
        $this->assertSame(2, CooldownLedgerService::coverage($answers, $presses, 30)['bigDown'], 'both charges spent');
        $this->assertSame(0, CooldownLedgerService::coverage($answers, $presses, 200)['bigDown'], 'all back');
    }
}
