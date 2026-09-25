<?php

namespace Tests\Feature;

use App\Http\Services\CombatantThroughputService;
use App\Http\Services\LobbyReviewService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Game Review: the throughput measurement, and the page that reads its artifact.
 *
 * The measurement tests are built from fixture log lines with the REAL field counts, because the
 * field counts are the whole basis of the parse — every offset is read from the end of the line,
 * so a fixture with the wrong length would pass while the real thing failed. The counts used here
 * (36 / 42 / 38 / 22 / 19) were measured across a whole lobby of a real 12.1.0 log and are fixed
 * per event type; see CombatantThroughputService's docblock.
 */
class GameReviewTest extends TestCase
{
    // The page tests need a schema — not for the review, which is a file, but because the shared
    // layout's nav composer queries `categories` and a schemaless run 500s before the component
    // is ever reached. The measurement tests never touch the database; that is the point of
    // CombatantThroughputService taking plain log lines.
    use RefreshDatabase;

    private const TEST_REVIEW_ID = 'ffffffffffffffffffffffffffffffff';

    /**
     * Builds a log line with a given event, leading fields and trailing fields, padded in the
     * middle to the exact real field count — which is what the parser anchors on.
     */
    private function line(string $event, array $lead, array $tail, int $total, string $ts = '9/25/2026 10:00:00.0000'): string
    {
        $pad = $total - 1 - count($lead) - count($tail);
        $this->assertGreaterThanOrEqual(0, $pad, "Fixture for {$event} exceeds its real field count.");

        return $ts.'  '.implode(',', array_merge([$event], $lead, array_fill(0, $pad, '0'), $tail));
    }

    private function heal(string $caster, int $amount, int $overheal): string
    {
        return $this->line(
            'SPELL_HEAL',
            [$caster, '"Caster"', '0x511', '0x0', 'Player-1-TGT', '"Target"', '0x512', '0x0', '47750', '"Penance"', '0x2'],
            [(string) $amount, (string) $amount, (string) $overheal, '0', 'nil'],
            36
        );
    }

    /** The 22-field form: a spell was absorbed, so the incoming hit carries spellId/name/school. */
    private function absorbSpell(string $shieldCaster, int $absorbed): string
    {
        return $this->line(
            'SPELL_ABSORBED',
            ['Player-1-FOE', '"Foe"', '0x548', '0x0', 'Player-1-TGT', '"Target"', '0x512', '0x0', '1', '"Hit"', '0x1'],
            [$shieldCaster, '"Caster"', '0x511', '0x0', '17', '"Power Word: Shield"', '0x2', (string) $absorbed, '999999', 'nil'],
            22
        );
    }

    /** The 19-field form: a melee swing was absorbed, so there is no spell block for the hit. */
    private function absorbSwing(string $shieldCaster, int $absorbed): string
    {
        return $this->line(
            'SPELL_ABSORBED',
            ['Player-1-FOE', '"Foe"', '0x548', '0x0', 'Player-1-TGT', '"Target"', '0x512', '0x0'],
            [$shieldCaster, '"Caster"', '0x511', '0x0', '17', '"Power Word: Shield"', '0x2', (string) $absorbed, '999999', 'nil'],
            19
        );
    }

    private function spellDamage(string $src, string $dst, int $amount): string
    {
        return $this->line(
            'SPELL_DAMAGE',
            [$src, '"Src"', '0x511', '0x0', $dst, '"Dst"', '0x548', '0x0', '589', '"Pain"', '0x20'],
            [(string) $amount, (string) $amount, '-1', '32', '0', '0', '0', 'nil', 'nil', 'nil', 'ST'],
            42
        );
    }

    private function swing(string $event, string $src, string $dst, int $amount, string $ts): string
    {
        return $this->line(
            $event,
            [$src, '"Src"', '0x511', '0x0', $dst, '"Dst"', '0x548', '0x0'],
            [(string) $amount, (string) $amount, '-1', '1', '0', '0', '0', 'nil', 'nil', 'nil'],
            38,
            $ts
        );
    }

    public function test_effective_healing_subtracts_overheal_and_overheal_is_kept_separately(): void
    {
        $r = app(CombatantThroughputService::class)->measure([
            $this->heal('Player-1-AAA', 1000, 400),
            $this->heal('Player-1-AAA', 500, 500),   // fully overhealed: contributes nothing
        ]);

        $this->assertSame(600, $r['players']['Player-1-AAA']['healingEffective']);
        $this->assertSame(900, $r['players']['Player-1-AAA']['healingOverheal']);
        $this->assertSame(0, $r['unparsed']);
    }

    public function test_absorbs_are_credited_to_the_shield_caster_in_both_line_lengths(): void
    {
        // The regression this guards: SPELL_ABSORBED is 22 fields when a spell was absorbed and
        // 19 when a swing was. A fixed index for the shield's caster reads the wrong field on the
        // short form — 1,656 of 20,440 events in one real lobby — and silently loses those
        // absorbs. Reading from the end is what makes one path handle both.
        $r = app(CombatantThroughputService::class)->measure([
            $this->absorbSpell('Player-1-AAA', 5000),
            $this->absorbSwing('Player-1-AAA', 250),
        ]);

        $this->assertSame(5250, $r['players']['Player-1-AAA']['absorbDone']);
    }

    public function test_a_melee_hit_reported_as_both_swing_events_is_counted_once(): void
    {
        // Measured on a real lobby: 1,540 SWING_DAMAGE and 3,864 SWING_DAMAGE_LANDED, of which
        // 836 are the same hit twice. Summing both over-counts; taking one loses the rest.
        $ts = '9/25/2026 10:00:01.5000';

        $r = app(CombatantThroughputService::class)->measure([
            $this->swing('SWING_DAMAGE', 'Player-1-AAA', 'Player-1-BBB', 700, $ts),
            $this->swing('SWING_DAMAGE_LANDED', 'Player-1-AAA', 'Player-1-BBB', 700, $ts),
            $this->swing('SWING_DAMAGE_LANDED', 'Player-1-AAA', 'Player-1-BBB', 300, '9/25/2026 10:00:02.0000'),
        ]);

        $this->assertSame(1000, $r['players']['Player-1-AAA']['damageDone']);
        $this->assertSame(1000, $r['players']['Player-1-BBB']['damageTaken']);
    }

    public function test_pet_damage_is_credited_to_the_summoner_and_otherwise_reported_as_unattributed(): void
    {
        $summon = '9/25/2026 10:00:00.0000  SPELL_SUMMON,Player-1-AAA,"Owner",0x511,0x0,Pet-0-1,"Pet",0x1112,0x0,1,"Summon",0x1';

        $credited = app(CombatantThroughputService::class)->measure([
            $summon,
            $this->spellDamage('Pet-0-1', 'Player-1-BBB', 900),
        ]);

        $this->assertSame(900, $credited['players']['Player-1-AAA']['damageDone']);
        $this->assertSame(0, $credited['unattributedPetDamage']);

        // A pet already out before the log starts has no summon line and cannot be attributed.
        // It is reported, not silently dropped and not guessed onto somebody.
        $orphan = app(CombatantThroughputService::class)->measure([
            $this->spellDamage('Pet-0-9', 'Player-1-BBB', 400),
        ]);

        $this->assertSame(400, $orphan['unattributedPetDamage']);
        $this->assertArrayNotHasKey('Pet-0-9', $orphan['players']);
    }

    public function test_a_feign_death_is_not_counted_as_a_death(): void
    {
        $r = app(CombatantThroughputService::class)->measure([
            '9/25/2026 10:00:00.0000  UNIT_DIED,0000000000000000,nil,0x80000000,0x80000000,Player-1-AAA,"Hunter",0x512,0x80000000,1',
            '9/25/2026 10:00:05.0000  UNIT_DIED,0000000000000000,nil,0x80000000,0x80000000,Player-1-BBB,"Mage",0x512,0x80000000,0',
        ]);

        $this->assertSame(0, $r['players']['Player-1-AAA']['deaths']);
        $this->assertSame(1, $r['players']['Player-1-AAA']['feigns']);
        $this->assertSame(1, $r['players']['Player-1-BBB']['deaths']);
    }

    public function test_an_unreadable_amount_is_counted_not_treated_as_zero(): void
    {
        // A future client format change should show up as a number in the command's output, not
        // as a quiet undercount that looks like a bad game.
        $broken = str_replace(',1000,1000,400,', ',nope,nope,nope,', $this->heal('Player-1-AAA', 1000, 400));

        $r = app(CombatantThroughputService::class)->measure([$broken]);

        $this->assertSame(1, $r['unparsed']);
        $this->assertSame([], $r['players']);
    }

    // ------------------------------------------------------------------ the page

    /**
     * Stores a review row for a user, the way an upload or `wow:review-lobby --user` would.
     */
    private function storeFixtureReview(User $user): void
    {
        $totals = fn (int $heal, int $absorb) => [
            'healingEffective' => $heal, 'healingOverheal' => 0, 'absorbDone' => $absorb,
            'damageDone' => 0, 'damageTaken' => 0, 'deaths' => 0, 'feigns' => 0,
        ];

        $side = fn (string $name, bool $isYou, array $t) => [
            'guid' => 'Player-1-'.strtoupper(substr($name, 0, 3)), 'name' => $name, 'isYou' => $isYou,
            'totals' => $t, 'perRound' => [1 => $t], 'roundsPlayed' => 1, 'buildChangedMidGame' => false,
        ];

        $me = $side('Skylake-Frostmourne', true, $totals(100, 50));
        $them = $side('Marky-Magtheridon', false, $totals(90, 60));

        app(LobbyReviewService::class)->store($user, [
            'id' => self::TEST_REVIEW_ID,
            'bracket' => 'Rated Solo Shuffle',
            'playedAt' => '2026-09-25T17:29:00+00:00',
            'record' => ['won' => 5, 'lost' => 1],
            'rounds' => [
                ['sequence' => 1, 'matchId' => 'x', 'durationSeconds' => 167, 'result' => 'won',
                    'winningTeamId' => '0', 'killedUnitId' => null, 'killedName' => 'Someone-Realm',
                    'unattributedPetDamage' => 0, 'unparsedEvents' => 0],
            ],
            'players' => [
                $me + ['spec' => ['externalId' => 256, 'label' => 'Discipline Priest']],
                $them + ['spec' => ['externalId' => 256, 'label' => 'Discipline Priest']],
            ],
            'mirrors' => [[
                'specLabel' => 'Discipline Priest', 'specExternalId' => 256, 'involvesYou' => true,
                'a' => $me, 'b' => $them,
                'roundsOpposed' => 1, 'roundsTogether' => 0,
                'primaryMetric' => 'healingAndAbsorbs', 'primaryMetricLabel' => 'Effective healing + absorbs',
                'primaryDeltaPercent' => 0.0,
                'talentDiff' => ['onlyA' => [['name' => 'Lenience', 'rank' => 1]],
                    'onlyB' => [['name' => 'Weal and Woe', 'rank' => 1]],
                    'differentRank' => [], 'unavailable' => false],
                'pvpTalentDiff' => ['shared' => ['Phase Shift'], 'onlyA' => ['Inner Light'], 'onlyB' => ['Purification']],
                'statDiff' => ['unavailable' => false, 'rows' => [['stat' => 'mastery', 'a' => 832, 'b' => 639]]],
                'gearDiff' => ['unavailable' => false, 'a' => ['items' => 16, 'median' => 344, 'max' => 344, 'min' => 1],
                    'b' => ['items' => 15, 'median' => 344, 'max' => 344, 'min' => 331], 'medianDelta' => 0],
            ]],
            'limitations' => ['Throughput is not adjusted for pressure.'],
            'generatedAt' => '2026-09-25T18:00:00+00:00',
        ]);
    }

    public function test_the_page_renders_over_http_with_its_layout(): void
    {
        // Livewire::test() renders a component WITHOUT its layout, so a missing ->layout() call
        // passes every Livewire assertion while the real URL 500s. That is exactly how the
        // Matchup Lab shipped its first green run (2026-09-23).
        $user = User::factory()->create();
        $this->storeFixtureReview($user);
        $this->actingAs($user);

        $this->get(route('game-review'))->assertOk();
        $this->get(route('game-review', ['id' => self::TEST_REVIEW_ID]))->assertOk();
    }

    public function test_the_page_is_not_public(): void
    {
        $this->storeFixtureReview(User::factory()->create());

        $this->get(route('game-review'))->assertRedirect(route('login'));
        $this->get(route('game-review', ['id' => self::TEST_REVIEW_ID]))->assertRedirect(route('login'));
    }

    public function test_one_player_cannot_see_another_players_review(): void
    {
        // The whole reason reviews moved out of committed files and into owned rows. A review names
        // five other players with their talents and their gear; it belongs to whoever uploaded it.
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $this->storeFixtureReview($owner);

        $this->assertNotEmpty(app(LobbyReviewService::class)->index($owner));
        $this->assertSame([], app(LobbyReviewService::class)->index($stranger));
        $this->assertNull(app(LobbyReviewService::class)->load($stranger, self::TEST_REVIEW_ID));

        $this->actingAs($stranger)
            ->get(route('game-review', ['id' => self::TEST_REVIEW_ID]))
            ->assertOk()
            ->assertDontSee('Skylake')
            ->assertDontSee('Lenience');
    }

    public function test_the_page_shows_a_mirror_comparison_for_its_owner(): void
    {
        $user = User::factory()->create();
        $this->storeFixtureReview($user);

        Livewire::actingAs($user)
            ->test(\App\Livewire\GameReview::class, ['id' => self::TEST_REVIEW_ID])
            ->assertSee('Discipline Priest mirror')
            ->assertSee('Lenience')
            ->assertSee('Weal and Woe')
            ->assertSee('Inner Light')
            ->assertSee('Purification')
            ->assertSee('What this cannot tell you')
            // The upload control is an included partial with an inline script; if the include or
            // the script ever fails to compile it renders as nothing rather than erroring.
            ->assertSee('Upload matches')
            ->assertSee('window.arenaUpload', false);
    }

    public function test_the_page_works_with_the_raw_archive_completely_absent(): void
    {
        // Rule 14: the archive is gitignored, so anything a page reads from it is silently broken
        // for every real user while looking perfect in dev. Reviews are rows precisely so this
        // cannot happen, and pointing the archive at an empty directory proves it.
        $user = User::factory()->create();
        $this->storeFixtureReview($user);

        config(['arena_logs.archive_path' => sys_get_temp_dir().'/mc-archive-absent-'.uniqid()]);

        $this->actingAs($user)
            ->get(route('game-review', ['id' => self::TEST_REVIEW_ID]))
            ->assertOk()
            ->assertSee('Discipline Priest mirror');
    }

    public function test_an_unknown_review_id_falls_back_instead_of_erroring(): void
    {
        $user = User::factory()->create();
        $this->storeFixtureReview($user);

        Livewire::actingAs($user)
            ->test(\App\Livewire\GameReview::class, ['id' => 'not-a-real-id'])
            ->assertSet('reviewId', fn ($id) => $id !== 'not-a-real-id');
    }

    public function test_the_honest_limits_are_not_empty(): void
    {
        // Rule 33's reasoning: limits that live in one method cannot be trimmed a line at a time
        // by a layout change, but they can still be emptied — so the emptiness is asserted.
        $this->assertNotEmpty(app(LobbyReviewService::class)->limitations());
    }

    // ------------------------------------------------------------------ upload

    public function test_uploading_a_round_stores_it_and_assembles_a_review(): void
    {
        $user = User::factory()->create();

        $round = implode("\n", $this->shuffleRoundLog());

        $result = app(\App\Http\Services\ArenaReviewIngestService::class)->ingestRound($user, $round);

        $this->assertSame('stored', $result['status'], json_encode($result));
        $this->assertDatabaseCount('arena_rounds', 1);

        $review = app(\App\Http\Services\ArenaReviewIngestService::class)
            ->assembleLobby($user, $result['lobbyId']);

        $this->assertNotNull($review);
        $this->assertSame($user->id, $review->user_id);
        $this->assertSame('Rated Solo Shuffle', $review->bracket);
        $this->assertSame(1, $review->rounds);
    }

    public function test_uploading_the_same_round_twice_does_not_duplicate_it(): void
    {
        // A player re-uploading a growing log must not get two of everything. The match id is
        // derived from the round's own start instant, arena and roster, which is what makes
        // "point it at the same file again" the intended workflow.
        $user = User::factory()->create();
        $round = implode("\n", $this->shuffleRoundLog());
        $ingest = app(\App\Http\Services\ArenaReviewIngestService::class);

        $ingest->ingestRound($user, $round);
        $ingest->ingestRound($user, $round);

        $this->assertDatabaseCount('arena_rounds', 1);
    }

    public function test_uploaded_rounds_are_grouped_into_one_lobby_and_renumbered(): void
    {
        // The regression this guards, caught by uploading a real lobby: an uploaded round arrives
        // alone, so it derives a lobby id of itself and a sequence of 1. Six rounds became six
        // separate one-round games, each reading 1-0. The server groups them by roster and time and
        // numbers them by when they were played, because the client is not told to know.
        $user = User::factory()->create();
        $ingest = app(\App\Http\Services\ArenaReviewIngestService::class);

        $lobbyIds = [];

        // Three rounds of one lobby: same six players, same arena, minutes apart. Deliberately
        // uploaded out of order, which is what a parallel upload does.
        foreach (['17:33:00', '17:29:00', '17:31:00'] as $at) {
            $lines = $this->shuffleRoundLog();
            $lines = array_map(fn ($l) => str_replace('17:29:00', $at, $l), $lines);
            $result = $ingest->ingestRound($user, implode("\n", $lines));

            $this->assertSame('stored', $result['status'], json_encode($result));
            $lobbyIds[] = $result['lobbyId'];
        }

        $this->assertCount(1, array_unique($lobbyIds), 'All three rounds belong to one lobby.');
        $this->assertDatabaseCount('arena_rounds', 3);

        $review = $ingest->assembleLobby($user, $lobbyIds[0]);

        $this->assertSame(3, $review->rounds);
        $this->assertSame([1, 2, 3], array_column($review->payload['rounds'], 'sequence'));

        // Earliest start is round one, whatever order it was uploaded in.
        $this->assertSame(
            ['17:29', '17:31', '17:33'],
            collect(\App\Models\ArenaRound::where('user_id', $user->id)->orderBy('sequence')->get())
                ->map(fn ($r) => $r->played_at->format('H:i'))->all()
        );
    }

    public function test_a_second_lobby_with_the_same_roster_much_later_is_kept_separate(): void
    {
        // Without a time bound, grouping on the roster alone would weld two genuinely separate
        // lobbies into one twelve-round game. Real lobbies run about fifteen minutes.
        $user = User::factory()->create();
        $ingest = app(\App\Http\Services\ArenaReviewIngestService::class);

        $first = $ingest->ingestRound($user, implode("\n", $this->shuffleRoundLog()));

        $later = array_map(
            fn ($l) => str_replace(['17:29:0', '17:31:0'], ['22:29:0', '22:31:0'], $l),
            $this->shuffleRoundLog()
        );
        $second = $ingest->ingestRound($user, implode("\n", $later));

        $this->assertSame('stored', $second['status'], json_encode($second));
        $this->assertNotSame($first['lobbyId'], $second['lobbyId']);
    }

    public function test_a_non_shuffle_round_is_skipped(): void
    {
        $user = User::factory()->create();

        $lines = $this->shuffleRoundLog();
        $lines[0] = str_replace('Rated Solo Shuffle', '3v3', $lines[0]);

        $result = app(\App\Http\Services\ArenaReviewIngestService::class)
            ->ingestRound($user, implode("\n", $lines));

        $this->assertSame('skipped', $result['status']);
        $this->assertDatabaseCount('arena_rounds', 0);
    }

    public function test_uploading_requires_a_signed_in_user(): void
    {
        $this->post(route('game-review.upload-round'))->assertRedirect(route('login'));
        $this->post(route('game-review.assemble'))->assertRedirect(route('login'));
    }

    /**
     * A minimal but structurally real Solo Shuffle round: the bracket line, a COMBATANT_INFO per
     * player with the spec at field 24 and the bracketed talent/pvp/gear groups the extractor
     * needs, one cast so every unit has flags, and the real death that ends the round.
     *
     * @return array<int, string>
     */
    private function shuffleRoundLog(): array
    {
        $combatant = function (string $guid, string $team, string $spec) {
            $stats = implode(',', array_fill(0, 22, '0'));

            return "COMBATANT_INFO,{$guid},{$team},{$stats},{$spec},"
                .'[(82567,103692,1),(82580,103705,1)],(0,408557,236499,355897),'
                .'[(271555,344,(),(13452),()),(240952,331,(),(),())],[],0,0,0,0';
        };

        // 22 stat fields sit between the team and the spec id, so the spec lands at index 24
        // counting the GUID as 0 — the offset CombatLogIngestService documents.
        return [
            '9/25/2026 17:29:00.0000  ARENA_MATCH_START,2563,42,Rated Solo Shuffle,0',
            '9/25/2026 17:29:00.0000  '.$combatant('Player-1-AAA', '0', '256'),
            '9/25/2026 17:29:00.0000  '.$combatant('Player-1-BBB', '0', '259'),
            '9/25/2026 17:29:00.0000  '.$combatant('Player-1-CCC', '1', '105'),
            '9/25/2026 17:29:01.0000  SPELL_CAST_SUCCESS,Player-1-AAA,"Me-Realm",0x511,0x0,Player-1-CCC,"Foe-Realm",0x548,0x0,47750,"Penance",0x2',
            '9/25/2026 17:29:02.0000  SPELL_CAST_SUCCESS,Player-1-BBB,"Ally-Realm",0x512,0x0,Player-1-CCC,"Foe-Realm",0x548,0x0,408,"Kidney Shot",0x1',
            '9/25/2026 17:31:00.0000  UNIT_DIED,0000000000000000,nil,0x80000000,0x80000000,Player-1-CCC,"Foe-Realm",0x548,0x80000000,0',
            '9/25/2026 17:31:01.0000  ARENA_MATCH_END,-1,121,1674,1667',
        ];
    }
}
