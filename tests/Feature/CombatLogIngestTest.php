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
