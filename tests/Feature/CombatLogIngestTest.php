<?php

namespace Tests\Feature;

use App\Http\Services\CombatLogIngestService;
use Tests\TestCase;

/**
 * The local combat-log ingester.
 *
 * THE REAL VERIFICATION IS NOT HERE. It is that the derivation agrees with wowarenalogs' own
 * metadata on all 16 archived matches we hold both halves of — same bracket, zone, ranked flag,
 * duration, winner, rating, result, and the same roster of GUID/spec/reaction. That check needs
 * the archive on disk, which is gitignored (CLAUDE.md rule 14), so it cannot run in CI. It is
 * reproducible by hand and the numbers are in the commit message.
 *
 * What is tested here is the parsing itself, against fixtures built in the test, so it runs
 * anywhere. Each one exists because getting it wrong produced a specific real bug.
 */
class CombatLogIngestTest extends TestCase
{
    private function line(string $time, string $body): string
    {
        return "9/1/2026 {$time}  {$body}\n";
    }

    /**
     * A minimal but structurally real match: the two ARENA_ lines, a COMBATANT_INFO per player
     * with the spec at field 24, and one event so each unit has flags.
     *
     * @return array<int, string>
     */
    private function match(string $winner = '1', string $myTeam = '1'): array
    {
        $combatant = function (string $guid, string $team, string $spec) {
            // 24 fields of stats between the team and the spec, matching the real layout.
            $filler = implode(',', array_fill(0, 22, '0'));

            return "COMBATANT_INFO,{$guid},{$team},{$filler},{$spec},[],[],[],[],[],0,0,0,0";
        };

        // The logging player carries AFFILIATION_MINE (0x1); their ally PARTY (0x2); enemies
        // hostile (0x40).
        $mineFlags = '0x511';
        $allyFlags = '0x512';
        $foeFlags = '0x548';

        return [
            $this->line('10:00:00.0000', 'ARENA_MATCH_START,1505,30,3v3,1'),
            $this->line('10:00:00.0000', $combatant('Player-1-AAA', $myTeam, '259')),
            $this->line('10:00:00.0000', $combatant('Player-1-BBB', $myTeam, '256')),
            $this->line('10:00:00.0000', $combatant('Player-1-CCC', $myTeam === '1' ? '0' : '1', '105')),
            $this->line('10:00:01.0000', "SPELL_CAST_SUCCESS,Player-1-AAA,\"Me-Realm\",{$mineFlags},0x0,Player-1-CCC,\"Foe-Realm\",{$foeFlags},0x0,408,\"Kidney Shot\",0x1"),
            $this->line('10:00:02.0000', "SPELL_CAST_SUCCESS,Player-1-BBB,\"Ally-Realm\",{$allyFlags},0x0,Player-1-CCC,\"Foe-Realm\",{$foeFlags},0x0,589,\"Pain\",0x20"),
            $this->line('10:02:00.0000', "ARENA_MATCH_END,{$winner},120,1500,1700"),
        ];
    }

    private function derive(array $lines): array
    {
        $service = app(CombatLogIngestService::class);

        return $service->deriveMetadata(
            $lines,
            trim(explode('  ', $lines[0], 2)[1]),
            trim(explode('  ', $lines[count($lines) - 1], 2)[1])
        );
    }

    /**
     * One Solo Shuffle round: its own COMBATANT_INFO block (the teams are re-dealt every round),
     * an optional Feign Death, and the real death that ends it.
     *
     * @param  string  $loserTeam  the team of the player whose real death ends the round
     * @return array<int, string>
     */
    private function shuffleRound(string $minute, string $loserTeam, string $myTeam = '0', bool $withFeign = true): array
    {
        $combatant = function (string $guid, string $team, string $spec) {
            $filler = implode(',', array_fill(0, 22, '0'));

            return "COMBATANT_INFO,{$guid},{$team},{$filler},{$spec},[],[],[],[],[],0,0,0,0";
        };

        $foeTeam = $myTeam === '1' ? '0' : '1';
        $dead = $loserTeam === $myTeam ? 'Player-1-AAA' : 'Player-1-CCC';

        $lines = [
            $this->line("{$minute}:00.0000", 'ARENA_MATCH_START,2509,41,Rated Solo Shuffle,0'),
            $this->line("{$minute}:00.0000", $combatant('Player-1-AAA', $myTeam, '259')),
            $this->line("{$minute}:00.0000", $combatant('Player-1-BBB', $myTeam, '256')),
            $this->line("{$minute}:00.0000", $combatant('Player-1-CCC', $foeTeam, '105')),
            $this->line("{$minute}:01.0000", 'SPELL_CAST_SUCCESS,Player-1-AAA,"Me-Realm",0x511,0x0,Player-1-CCC,"Foe-Realm",0x548,0x0,408,"Kidney Shot",0x1'),
            $this->line("{$minute}:02.0000", 'SPELL_CAST_SUCCESS,Player-1-BBB,"Ally-Realm",0x512,0x0,Player-1-CCC,"Foe-Realm",0x548,0x0,589,"Pain",0x20'),
        ];

        if ($withFeign) {
            // The trap: a Hunter feigning looks exactly like a death but for the trailing 1.
            $lines[] = $this->line("{$minute}:20.0000", 'UNIT_DIED,0000000000000000,nil,0x80000000,0x80000000,Player-1-BBB,"Ally-Realm",0x512,0x80000000,1');
        }

        $lines[] = $this->line("{$minute}:40.0000", "UNIT_DIED,0000000000000000,nil,0x80000000,0x80000000,{$dead},\"X-Realm\",0x512,0x80000000,0");

        return $lines;
    }

    public function test_it_reads_the_match_header_and_footer(): void
    {
        $meta = $this->derive($this->match());

        $this->assertSame('3v3', $meta['startInfo']['bracket']);
        $this->assertSame('1505', $meta['startInfo']['zoneId']);
        $this->assertTrue($meta['startInfo']['isRanked']);
        $this->assertSame(120, $meta['durationInSeconds']);
        $this->assertSame('1', $meta['winningTeamId']);
    }

    public function test_the_logging_players_team_comes_from_combatant_info_not_reaction(): void
    {
        // The regression this guards: `reaction` is friendly-or-hostile as seen by the logging
        // player, so it is 1 for their own side whichever arena team that is. Deriving the team
        // from it agreed with the archive on 4 of 16 matches and silently inverted the other 12.
        $onTeamOne = $this->derive($this->match(winner: '1', myTeam: '1'));
        $onTeamZero = $this->derive($this->match(winner: '1', myTeam: '0'));

        $this->assertSame(3, $onTeamOne['result'], 'Team 1 won, and the logging player was on team 1.');
        $this->assertSame(2, $onTeamZero['result'], 'Team 1 won, and the logging player was on team 0.');

        // Their reaction is 1 in BOTH cases — which is exactly why it cannot carry the team.
        $me = collect($onTeamZero['units'])->firstWhere('affiliation', 1);
        $this->assertSame(1, $me['reaction']);
    }

    public function test_the_rating_is_read_at_the_logging_players_team_index(): void
    {
        // ARENA_MATCH_END,...,1500,1700 — field 3 is team 0's rating, field 4 is team 1's.
        $this->assertSame(1700, $this->derive($this->match(myTeam: '1'))['playerTeamRating']);
        $this->assertSame(1500, $this->derive($this->match(myTeam: '0'))['playerTeamRating']);
    }

    public function test_it_reads_every_players_spec_and_side(): void
    {
        $meta = $this->derive($this->match());

        $players = collect($meta['units'])->filter(fn ($u) => str_starts_with($u['id'], 'Player-'));

        $this->assertCount(3, $players);
        $this->assertEqualsCanonicalizing(['259', '256', '105'], $players->pluck('spec')->all());

        // Two friendly, one hostile — the split every analysis command downstream keys off.
        $this->assertSame(2, $players->where('reaction', 1)->count());
        $this->assertSame(1, $players->where('reaction', 2)->count());
    }

    public function test_the_id_is_stable_across_runs_but_differs_between_matches(): void
    {
        // Re-ingesting a growing WoWCombatLog.txt must not duplicate what is already stored.
        $this->assertSame(
            $this->derive($this->match())['id'],
            $this->derive($this->match())['id']
        );

        $other = $this->match();
        $other[0] = $this->line('11:00:00.0000', 'ARENA_MATCH_START,1505,30,3v3,1');

        $this->assertNotSame($this->derive($this->match())['id'], $this->derive($other)['id']);
    }

    public function test_it_splits_a_log_holding_several_matches(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mclog');
        file_put_contents($path, implode('', array_merge(
            [$this->line('09:00:00.0000', 'SPELL_DAMAGE,0,"x",0x0,0x0,0,"y",0x0,0x0,1,"a",0x1')],
            $this->match(winner: '1'),
            [$this->line('10:30:00.0000', 'SPELL_DAMAGE,0,"x",0x0,0x0,0,"y",0x0,0x0,1,"a",0x1')],
            $this->match(winner: '0'),
        )));

        $found = iterator_to_array(app(CombatLogIngestService::class)->splitMatches($path));
        unlink($path);

        $this->assertCount(2, $found);
        $this->assertStringContainsString('ARENA_MATCH_END,1', $found[0]['end']);
        $this->assertStringContainsString('ARENA_MATCH_END,0', $found[1]['end']);
    }

    public function test_a_solo_shuffle_lobby_splits_into_one_match_per_round(): void
    {
        // The regression this guards: a lobby writes six ARENA_MATCH_STARTs and ONE
        // ARENA_MATCH_END. Treating a second START as "the previous match never closed" kept
        // only the final round — 326 rounds in the author's logs collapsed to 56 imports.
        $path = tempnam(sys_get_temp_dir(), 'mclog');
        file_put_contents($path, implode('', array_merge(
            $this->shuffleRound('10:00', loserTeam: '1'),
            $this->shuffleRound('10:02', loserTeam: '0'),
            $this->shuffleRound('10:04', loserTeam: '1'),
            [$this->line('10:05:00.0000', 'ARENA_MATCH_END,-1,60,1674,1667')],
        )));

        $found = iterator_to_array(app(CombatLogIngestService::class)->splitMatches($path));
        unlink($path);

        $this->assertCount(3, $found);
        $this->assertSame([1, 2, 3], array_column($found, 'sequence'));

        // Only the last round has an END; the first two closed because the next round began.
        $this->assertNull($found[0]['end']);
        $this->assertNull($found[1]['end']);
        $this->assertStringContainsString('ARENA_MATCH_END,-1', $found[2]['end']);
    }

    public function test_a_shuffle_round_result_comes_from_the_real_death_not_the_end_line(): void
    {
        // ARENA_MATCH_END's winningTeamId is meaningless for shuffle — across 56 real lobbies it
        // agreed with the final round's actual loser 19 times in 55, which is chance. Here it
        // says -1 and the round is still resolved, from the one death flagged as real.
        $service = app(CombatLogIngestService::class);

        $lost = $service->deriveMetadata(
            $round = $this->shuffleRound('10:00', loserTeam: '0', myTeam: '0'),
            trim(explode('  ', $round[0], 2)[1]),
            'ARENA_MATCH_END,-1,60,1674,1667',
            sequence: 4,
        );

        $this->assertSame('1', $lost['winningTeamId']);
        $this->assertSame(CombatLogIngestService::RESULT_LOSS, $lost['result']);
        $this->assertSame('Player-1-AAA', $lost['killedUnitId']);
        $this->assertSame(4, $lost['sequenceNumber']);

        $won = $service->deriveMetadata(
            $other = $this->shuffleRound('10:00', loserTeam: '1', myTeam: '0'),
            trim(explode('  ', $other[0], 2)[1]),
            null,
        );

        $this->assertSame('0', $won['winningTeamId']);
        $this->assertSame(CombatLogIngestService::RESULT_WIN, $won['result']);
    }

    public function test_a_feign_death_does_not_decide_a_shuffle_round(): void
    {
        // One BM Hunter in the sample lobby "died" in all six rounds and twice in three of them.
        // Without the unconsciousOnDeath filter the feign is the last death seen in this round
        // and would hand the round to the wrong team.
        $service = app(CombatLogIngestService::class);

        $round = $this->shuffleRound('10:00', loserTeam: '1', myTeam: '0');

        // Move a feign AFTER the real death — the ordering that breaks a "last death wins" rule.
        $real = array_pop($round);
        $this->assertStringContainsString(',0'."\n", $real, 'The fixture\'s last line should be the real death.');
        $round[] = $real;
        $round[] = $this->line('10:00:50.0000', 'UNIT_DIED,0000000000000000,nil,0x80000000,0x80000000,Player-1-BBB,"Ally-Realm",0x512,0x80000000,1');

        $meta = $service->deriveMetadata($round, trim(explode('  ', $round[0], 2)[1]), null);

        $this->assertSame('Player-1-CCC', $meta['killedUnitId'], 'The feigning ally must not be read as the round-ending death.');
        $this->assertSame('0', $meta['winningTeamId']);
    }

    public function test_a_shuffle_round_writes_no_rating_and_is_marked_ranked(): void
    {
        // Shuffle rating is personal, and the END line's two numbers are per-round team averages
        // of a roster that re-deals every round. A wrong number is worse than none.
        // The ranked field reads 0 on every Rated Solo Shuffle line, so the name carries it.
        $round = $this->shuffleRound('10:00', loserTeam: '1');

        $meta = app(CombatLogIngestService::class)->deriveMetadata(
            $round,
            trim(explode('  ', $round[0], 2)[1]),
            'ARENA_MATCH_END,-1,60,1674,1667',
        );

        $this->assertNull($meta['playerTeamRating']);
        $this->assertTrue($meta['startInfo']['isRanked']);
        $this->assertSame('Rated Solo Shuffle', $meta['startInfo']['bracket']);

        // Duration is the round's own span, since the END line's is the last round's alone.
        $this->assertSame(40, $meta['durationInSeconds']);
    }

    public function test_each_shuffle_round_reads_its_own_re_dealt_teams(): void
    {
        // The teams are re-dealt every round and the whole COMBATANT_INFO block is re-emitted.
        // Reading a lobby's teams once would make five of the six rounds wrong.
        $service = app(CombatLogIngestService::class);

        $first = $this->shuffleRound('10:00', loserTeam: '1', myTeam: '0');
        $second = $this->shuffleRound('10:02', loserTeam: '1', myTeam: '1');

        $a = $service->deriveMetadata($first, trim(explode('  ', $first[0], 2)[1]), null);
        $b = $service->deriveMetadata($second, trim(explode('  ', $second[0], 2)[1]), null);

        // Team 1 loses both rounds, but the logging player changed sides between them — so the
        // identical winningTeamId has to come out as a win and then a loss.
        $this->assertSame('0', $a['winningTeamId']);
        $this->assertSame('0', $b['winningTeamId']);
        $this->assertSame(CombatLogIngestService::RESULT_WIN, $a['result']);
        $this->assertSame(CombatLogIngestService::RESULT_LOSS, $b['result']);
        $this->assertNotSame($a['id'], $b['id'], 'Two rounds of one lobby must not collide on id.');
    }

    public function test_a_truncated_match_in_a_non_round_bracket_is_still_dropped(): void
    {
        // The shuffle change must not turn a genuinely half-written 3v3 into an import: its
        // duration, winner and rating all come from the END line it does not have.
        $path = tempnam(sys_get_temp_dir(), 'mclog');
        file_put_contents($path, implode('', array_merge(
            array_slice($this->match(winner: '1'), 0, -1),
            $this->match(winner: '0'),
        )));

        $found = iterator_to_array(app(CombatLogIngestService::class)->splitMatches($path));
        unlink($path);

        $this->assertCount(1, $found);
        $this->assertStringContainsString('ARENA_MATCH_END,0', $found[0]['end']);
    }

    public function test_a_match_with_no_end_is_dropped(): void
    {
        // Alt-F4 mid-game, or a log still being written. Half a match is worse than none: the
        // duration, winner and rating all come from the END line.
        $path = tempnam(sys_get_temp_dir(), 'mclog');
        $truncated = array_slice($this->match(), 0, -1);
        file_put_contents($path, implode('', $truncated));

        $found = iterator_to_array(app(CombatLogIngestService::class)->splitMatches($path));
        unlink($path);

        $this->assertCount(0, $found);
    }
}
