<?php

namespace App\Http\Services;

use App\Models\Specialization;
use Illuminate\Support\Facades\File;

/**
 * Turns a raw WoW combat log into the archive's own match files — the local replacement for
 * fetching from wowarenalogs.com.
 *
 * WHY THIS EXISTS. That service gated match search behind a Battle.net sign-in on 2026-09-23
 * (before that it was off entirely, see CLAUDE.md rule 12). Rather than depend on somebody
 * else's API and somebody else's policy, this reads the log WoW itself writes. Everything their
 * metadata carried turns out to be in the log already:
 *
 *   ARENA_MATCH_START,1504,42,3v3,1     -> zoneId, bracket, isRanked
 *   ARENA_MATCH_END,0,371,1660,1846     -> winningTeamId, duration, the player's team rating
 *   COMBATANT_INFO,<guid>,1,...,259,... -> team, and the spec id at field 24
 *
 * SHAPE-COMPATIBLE ON PURPOSE. The files written here are byte-for-byte the same shape as the
 * ones `ArenaLogService::storeMatch()` wrote, so every consumer downstream — CC chains, burst
 * windows, playstyle, CC targeting, `wow:refresh-match-derived` and all its steps — works
 * unchanged and cannot tell the difference. That compatibility is the whole design constraint;
 * a new shape would mean rewriting nine analysis commands.
 *
 * THE FIELD MAPPINGS WERE VERIFIED, NOT GUESSED. Decoded against a real archived match
 * (036170003f1ea12f41e7018c14e3a945) whose wowarenalogs metadata we already hold, so each
 * derivation below is checked against their ground truth for the same log:
 *
 *   reaction 1 <- unit flag 0x10 (friendly)
 *   reaction 2 <- unit flag 0x40 (hostile)
 *   affiliation <- flags & 0xF (1 mine, 2 party, 4 raid, 8 outsider)
 *
 * `affiliation === 1` is the character who wrote the log, which is what makes win/loss
 * derivable at all: their team versus `winningTeamId`.
 *
 * **`reaction` IS NOT THE ARENA TEAM ID.** It is friendly-or-hostile *as seen by the logging
 * player*, so reaction 1 means "on my side" and says nothing about whether that side is arena
 * team 0 or team 1. An earlier version of this class treated the two as the same on the strength
 * of one match where they happened to coincide; checked across all 16 archived matches, they
 * disagree in 12 of them. The team id only ever comes from COMBATANT_INFO field 2.
 *
 * Both remaining derivations follow from that, and both were fitted to the archive rather than
 * assumed:
 *
 *   playerTeamRating <- ARENA_MATCH_END field (3 + myTeam)    16/16 against their metadata
 *   result            <- 2 when the logging player's team lost, 3 when it won
 *                        (10 losses and 6 wins in the archive, no exceptions)
 *
 * SOLO SHUFFLE IS SIX MATCHES IN ONE LOBBY, AND THE LOG SAYS SO ONLY IF YOU LOOK.
 * Measured over the 21 real logs on the author's machine (326 shuffle rounds in 56 lobbies):
 *
 *   - A lobby writes **one ARENA_MATCH_START per round** and a **single ARENA_MATCH_END** at
 *     the very end. Six STARTs, one END. Treating a second START as "the previous match never
 *     closed" — right for 2v2/3v3, where starts and ends ran 142 to 141 — silently discarded
 *     five rounds in six here: 326 rounds collapsed to 56 imports, 83% of the games thrown away
 *     without a warning line.
 *   - Every round re-emits the whole COMBATANT_INFO block, and **the team ids are reshuffled
 *     each time**. A round's teams can only be read from that round's own block, which is what
 *     makes a round — not a lobby — the unit that maps onto the existing metadata shape.
 *   - **ARENA_MATCH_END's winningTeamId is meaningless for shuffle.** Across the 56 lobbies it
 *     reads -1 twenty times, 0 twenty-two times and 1 fourteen times, and checked against the
 *     final round's actual loser it agrees 19 times in 55 — chance. Anything derived from it,
 *     including the `result` this class used to write for a shuffle, was a coin flip.
 *   - The winner comes from the deaths instead. **UNIT_DIED's trailing field is
 *     `unconsciousOnDeath`**: 1 for a Hunter's Feign Death, 0 for a real one. Filter on it and
 *     **324 of the 326 rounds hold exactly one real death** — the round-ending one. The loser is
 *     that player's team for that round, the winner is the other. (Without the filter the rule
 *     falls apart: one BM Hunter in the sample lobby "dies" in all six rounds, twice in three of
 *     them.) The 2 rounds with two real deaths are simultaneous kills; the last one is taken.
 *   - **playerTeamRating is null for a shuffle round, deliberately.** Shuffle rating is personal
 *     and the END line carries two numbers that are per-round team averages of a roster that
 *     reshuffles every round. Nothing here can turn those into the player's rating, so this
 *     writes no number rather than a plausible one.
 *   - **isRanked is 0 on every `Rated Solo Shuffle` START line**, where 2v2/3v3 write 1. The
 *     bracket name is the trustworthy half, so a "Rated " prefix sets the flag.
 */
class CombatLogIngestService
{
    /** WoW writes `M/D/YYYY HH:MM:SS.ssss` with no zone. See timestampMs(). */
    private const TIMESTAMP_FORMAT = '/^(\d{1,2})\/(\d{1,2})\/(\d{4}) (\d{1,2}):(\d{2}):(\d{2})\.(\d+)/';

    /** Unit-flag bits, from the combat log's own COMBATLOG_OBJECT_* constants. */
    private const FLAG_AFFILIATION_MASK = 0xF;

    private const FLAG_REACTION_FRIENDLY = 0x10;

    private const FLAG_REACTION_HOSTILE = 0x40;

    /**
     * COMBATANT_INFO field index of the spec id, counting the GUID as 0. Confirmed against the
     * archive: field 24 reads 259 for a unit the fetched metadata calls spec "259".
     */
    private const COMBATANT_SPEC_INDEX = 24;

    /** COMBATANT_INFO's arena team id, counting the event name as field 0. */
    private const COMBATANT_TEAM_FIELD = 2;

    /** wowarenalogs' own `result` enum, as observed across the whole archive. */
    public const RESULT_LOSS = 2;

    public const RESULT_WIN = 3;

    /** Only these are worth keeping — everything else in a log is levelling and open world. */
    public const WANTED_BRACKETS = ['2v2', '3v3', '5v5', 'Rated Solo Shuffle'];

    /**
     * A Solo Shuffle lobby is every pairing of six players, so it is always exactly this many
     * rounds — 54 of the 55 complete lobbies measured, the other cut short by a log that began
     * mid-lobby, and none longer. It is used only to notice that a lobby lost its ARENA_MATCH_END
     * and that the next START therefore begins a new one, so the round counter restarts.
     */
    private const ROUNDS_PER_LOBBY = 6;

    /** @var array<string, int>|null spec external id => class id, memoised per run */
    private ?array $classBySpec = null;

    /**
     * Brackets that play several rounds under one ARENA_MATCH_START..END, matched on substring
     * so an unranked or renamed variant is caught too. See the class docblock.
     */
    public function isRoundBased(string $bracket): bool
    {
        return str_contains($bracket, 'Solo Shuffle');
    }

    /** The bracket named by an ARENA_MATCH_START body. */
    private function bracketOf(string $startBody): string
    {
        return explode(',', $startBody)[3] ?? '';
    }

    /**
     * Splits a combat log into arena matches.
     *
     * Streams line by line rather than reading the file: a season of logging is routinely
     * hundreds of megabytes and this runs on a laptop. Lines outside a match are discarded as
     * they are read, so peak memory is one match.
     *
     * A match is ARENA_MATCH_START .. ARENA_MATCH_END. A START with no END (the player alt-F4'd,
     * or the log was still being written) is dropped rather than half-imported.
     *
     * EXCEPT IN SOLO SHUFFLE, where a START with no END is the normal case: a lobby writes six
     * of them and one END. There a new START closes the round in progress and yields it, with
     * `end` null and `sequence` saying which round it was. The drop rule still applies to every
     * other bracket, so a genuinely truncated 3v3 is still discarded — the two cases are told
     * apart by the bracket on the buffered START, never by whether an END turned up.
     *
     * @return \Generator<int, array{lines: array<int, string>, start: string, end: ?string, sequence: int}>
     */
    public function splitMatches(string $path): \Generator
    {
        $handle = str_ends_with(strtolower($path), '.gz')
            ? gzopen($path, 'rb')
            : fopen($path, 'rb');

        if (! $handle) {
            throw new \RuntimeException("Could not open {$path}");
        }

        $current = null;
        $startLine = null;
        $sequence = 0;
        $lobbyFirstLine = null;

        try {
            while (($line = $this->readLine($handle, $path)) !== false) {
                $body = $this->body($line);

                if (str_starts_with($body, 'ARENA_MATCH_START,')) {
                    $bufferedIsRound = $startLine !== null && $this->isRoundBased($this->bracketOf($startLine));

                    // In a round-based bracket this START is the next round beginning, so the
                    // buffered one is a complete round. Anywhere else it means the buffered match
                    // never closed, and half a match is worse than none.
                    if ($current !== null && $bufferedIsRound) {
                        yield [
                            'lines' => $current, 'start' => $startLine, 'end' => null,
                            'sequence' => $sequence, 'lobbyFirstLine' => $lobbyFirstLine,
                        ];
                    }

                    $continuesLobby = $bufferedIsRound
                        && $this->isRoundBased($this->bracketOf($body))
                        && $sequence < self::ROUNDS_PER_LOBBY;

                    $sequence = $continuesLobby ? $sequence + 1 : 1;
                    $current = [$line];
                    $startLine = $body;
                    // Every round of one lobby shares this, which is what makes the six of them
                    // groupable after the fact. A lobby that starts mid-log keys off the first
                    // round actually seen, which is stable for a given file.
                    $lobbyFirstLine = $continuesLobby ? $lobbyFirstLine : $line;

                    continue;
                }

                if ($current === null) {
                    continue;
                }

                $current[] = $line;

                if (str_starts_with($body, 'ARENA_MATCH_END,')) {
                    yield [
                        'lines' => $current, 'start' => $startLine, 'end' => $body,
                        'sequence' => $sequence, 'lobbyFirstLine' => $lobbyFirstLine,
                    ];
                    $current = null;
                    $startLine = null;
                    $sequence = 0;
                    $lobbyFirstLine = null;
                }
            }
        } finally {
            str_ends_with(strtolower($path), '.gz') ? gzclose($handle) : fclose($handle);
        }
    }

    /**
     * Builds the metadata for one split match, in the exact shape the archive already holds.
     *
     * A Solo Shuffle round is one of these too — same shape, same keys, six players, so nothing
     * downstream needs to learn what a round is. What differs is where three of the fields come
     * from, and the class docblock says why for each.
     *
     * @param  array<int, string>  $lines
     * @param  string|null  $endLine  null for a shuffle round that ended because the next one began
     * @param  string|null  $lobbyFirstLine  the first ARENA_MATCH_START line of this round's lobby,
     *                                       timestamp included; null means this round IS the first
     */
    public function deriveMetadata(
        array $lines,
        string $startLine,
        ?string $endLine = null,
        int $sequence = 1,
        ?string $lobbyFirstLine = null,
    ): array {
        $start = explode(',', $startLine);
        $end = $endLine === null ? [] : explode(',', $endLine);

        $zoneId = $start[1] ?? '';
        $bracket = $start[3] ?? '';
        $roundBased = $this->isRoundBased($bracket);

        // The ranked field reads 0 on every Rated Solo Shuffle line, so the bracket name is the
        // half worth believing.
        $isRanked = ($start[4] ?? '0') === '1' || str_starts_with($bracket, 'Rated');

        [$units, $teamByGuid] = $this->deriveUnits($lines);

        // The character who wrote the log is the only one flagged AFFILIATION_MINE. Everything
        // relative — did we win, what was our rating — hangs off finding them, so when they are
        // absent both stay null rather than being guessed at.
        $myGuid = null;
        foreach ($units as $unit) {
            if (($unit['affiliation'] ?? null) === 1 && str_starts_with($unit['id'], 'Player-')) {
                $myGuid = $unit['id'];

                break;
            }
        }

        // The team id comes from COMBATANT_INFO, never from `reaction` — see the class docblock.
        $myTeam = $myGuid !== null ? ($teamByGuid[$myGuid] ?? null) : null;

        $startMs = $this->timestampMs($lines[0]);
        $endMs = $this->timestampMs($lines[count($lines) - 1]);

        $killedUnitId = null;

        if ($roundBased) {
            // The END line's winningTeamId is noise here and its ratings belong to a roster that
            // reshuffles every round, so neither is read. The round-ending death carries the
            // result instead, and the rating is left unwritten rather than invented.
            [$killedUnitId, $losingTeam] = $this->roundLoser($lines, $teamByGuid);

            $winningTeamId = $losingTeam === null ? '' : ($losingTeam === '0' ? '1' : '0');
            $duration = $startMs !== null && $endMs !== null ? (int) round(($endMs - $startMs) / 1000) : 0;
            $playerTeamRating = null;
        } else {
            $winningTeamId = $end[1] ?? '';
            $duration = (int) ($end[2] ?? 0);

            // Both teams' ratings are on the END line; ours is the one at our own team's index.
            $playerTeamRating = $myTeam !== null && isset($end[3 + (int) $myTeam])
                ? (int) $end[3 + (int) $myTeam]
                : null;
        }

        $result = null;
        if ($myTeam !== null && $winningTeamId !== '') {
            $result = $myTeam === $winningTeamId ? self::RESULT_WIN : self::RESULT_LOSS;
        }

        return [
            '__typename' => 'ArenaMatchDataStub',
            'id' => $this->matchId($startMs, $zoneId, $units),
            'wowVersion' => 'retail',
            // No remote object: the log lives next to this file, in the archive's own raw/ dir.
            'logObjectUrl' => null,
            'result' => $result,
            'winningTeamId' => $winningTeamId,
            'playerTeamRating' => $playerTeamRating,
            // Named after wowarenalogs' ShuffleRoundStub, which modelled a round the same way.
            // Null on a bracket that plays one game per lobby, so the shape never varies.
            'killedUnitId' => $killedUnitId,
            'sequenceNumber' => $roundBased ? $sequence : null,
            // The six rounds of one lobby share this. It is the id round one would get, so for
            // round one it equals `id` — one concept, not two. Null where a lobby is one game.
            'lobbyId' => $roundBased
                ? $this->matchId($this->timestampMs($lobbyFirstLine ?? $lines[0]), $zoneId, $units)
                : null,
            'durationInSeconds' => $duration,
            'startTime' => $startMs,
            'endTime' => $endMs,
            'startInfo' => [
                'bracket' => $bracket,
                'zoneId' => $zoneId,
                'isRanked' => $isRanked,
            ],
            'units' => array_values($units),
        ];
    }

    /**
     * The death that ended a Solo Shuffle round, and the team it cost the round.
     *
     * A round ends on one real death, so this is the whole result. The catch is that UNIT_DIED
     * also fires for a Hunter's Feign Death — in the sample lobby one BM Hunter "dies" in all six
     * rounds and twice in three of them, which is enough to invert half the results if taken at
     * face value. The trailing `unconsciousOnDeath` field tells them apart, and with it 324 of
     * the 326 shuffle rounds measured hold exactly one real death.
     *
     * The field is read as the LAST one rather than at a fixed index: Blizzard has appended to
     * UNIT_DIED before (recapID), and an off-by-one here would silently read a raid-flag mask as
     * a boolean. A line too short to carry it at all is counted as a real death, which is how
     * this behaves on a pre-flag log.
     *
     * Simultaneous kills — 2 of the 326 — take the later death, since that is the one the round
     * ended on.
     *
     * @param  array<int, string>  $lines
     * @param  array<string, string>  $teamByGuid
     * @return array{0: ?string, 1: ?string} the GUID that died, and its arena team id
     */
    private function roundLoser(array $lines, array $teamByGuid): array
    {
        $guid = null;

        foreach ($lines as $line) {
            $body = $this->body($line);

            if (! str_starts_with($body, 'UNIT_DIED,')) {
                continue;
            }

            $fields = str_getcsv($body);
            $dead = $fields[5] ?? null;

            if (! $dead || ! str_starts_with($dead, 'Player-')) {
                continue;
            }

            if (count($fields) > 9 && (string) end($fields) !== '0') {
                continue;
            }

            $guid = $dead;
        }

        return [$guid, $guid === null ? null : ($teamByGuid[$guid] ?? null)];
    }

    /**
     * Every unit in the match, keyed by GUID.
     *
     * Two passes over one array: COMBATANT_INFO gives the players and their specs (it is the only
     * place a spec id appears at all), and the ordinary events give every unit's name and flags —
     * including pets, which several analysis commands need in order to attribute a pet's damage
     * back to its owner's spec.
     *
     * @param  array<int, string>  $lines
     * @return array{0: array<string, array>, 1: array<string, string>} units, then GUID => arena team id
     */
    private function deriveUnits(array $lines): array
    {
        $specByGuid = [];
        $teamByGuid = [];
        $units = [];

        foreach ($lines as $line) {
            $body = $this->body($line);

            if (str_starts_with($body, 'COMBATANT_INFO,')) {
                $fields = explode(',', $body);
                // $fields[0] is the event name, so the GUID is 1 and the spec sits one further
                // along than its index counted from the GUID.
                $guid = $fields[1] ?? null;
                $spec = $fields[self::COMBATANT_SPEC_INDEX + 1] ?? null;

                if ($guid && $spec !== null && ctype_digit($spec)) {
                    $specByGuid[$guid] = $spec;
                }

                $team = $fields[self::COMBATANT_TEAM_FIELD] ?? null;
                if ($guid && ($team === '0' || $team === '1')) {
                    $teamByGuid[$guid] = $team;
                }

                continue;
            }

            // Ordinary event: source then destination, each GUID / "name" / flags / raidFlags.
            $fields = str_getcsv($body);

            foreach ([[1, 2, 3], [5, 6, 7]] as [$guidAt, $nameAt, $flagsAt]) {
                $guid = $fields[$guidAt] ?? null;
                $name = $fields[$nameAt] ?? null;
                $flags = $fields[$flagsAt] ?? null;

                if (! $guid || $guid === '0000000000000000' || $flags === null || ! str_starts_with((string) $flags, '0x')) {
                    continue;
                }

                $decoded = hexdec(substr($flags, 2));
                $reaction = match (true) {
                    (bool) ($decoded & self::FLAG_REACTION_FRIENDLY) => 1,
                    (bool) ($decoded & self::FLAG_REACTION_HOSTILE) => 2,
                    default => null,
                };

                if ($reaction === null) {
                    continue;
                }

                // First sighting wins. A unit's flags can change mid-match (mind control, a pet
                // changing owner), and the opening state is the one the roster should record.
                if (! isset($units[$guid])) {
                    $units[$guid] = [
                        'id' => $guid,
                        'name' => $name !== 'nil' ? (string) $name : 'nil',
                        'spec' => '0',
                        'class' => 0,
                        'reaction' => $reaction,
                        'affiliation' => $decoded & self::FLAG_AFFILIATION_MASK,
                    ];
                }
            }
        }

        foreach ($specByGuid as $guid => $spec) {
            if (! isset($units[$guid])) {
                // A player who appears in COMBATANT_INFO but never in an event — possible if they
                // did nothing at all. Keep them: a roster missing a player is worse than one with
                // an unknown reaction.
                $units[$guid] = [
                    'id' => $guid,
                    'name' => 'nil',
                    'spec' => $spec,
                    'class' => 0,
                    'reaction' => null,
                    'affiliation' => null,
                ];

                continue;
            }

            $units[$guid]['spec'] = $spec;
            $units[$guid]['class'] = $this->classForSpec($spec);
        }

        return [$units, $teamByGuid];
    }

    /**
     * Our own class id for a spec, looked up in our database.
     *
     * NOT wowarenalogs' `class` value, which is some enum of their own — in the archived match
     * a Discipline Priest (spec 256) carries class 6, which is not Blizzard's numbering either.
     * Nothing downstream reads this field, so a correct local id beats a copy of a number whose
     * meaning nobody here knows.
     */
    private function classForSpec(string $spec): int
    {
        if ($this->classBySpec === null) {
            // Degrades to 0 rather than throwing. Parsing a log is otherwise a pure function of
            // the file, and nothing downstream reads `class` — so a database that is missing,
            // unmigrated or mid-import should not be able to stop somebody importing their
            // games. It also keeps the parser testable without a schema.
            try {
                $this->classBySpec = Specialization::query()
                    ->whereNotNull('external_spec_id')
                    ->pluck('class_id', 'external_spec_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            } catch (\Throwable) {
                $this->classBySpec = [];
            }
        }

        return $this->classBySpec[$spec] ?? 0;
    }

    /**
     * A stable id for a match, so re-ingesting the same log is a no-op rather than a duplicate.
     *
     * Built from the start instant, the arena, and the roster — the three things that cannot
     * coincide for two different matches. Deliberately NOT a hash of the whole log: the same
     * match re-exported with one more trailing line would hash differently and import twice.
     *
     * 32 hex characters to match the id length the archive already uses, so nothing downstream
     * has to care where a match came from.
     *
     * @param  array<string, array>  $units
     */
    private function matchId(?int $startMs, string $zoneId, array $units): string
    {
        $players = array_values(array_filter(array_keys($units), fn ($guid) => str_starts_with($guid, 'Player-')));
        sort($players);

        return substr(md5(($startMs ?? 0).'|'.$zoneId.'|'.implode(',', $players)), 0, 32);
    }

    /**
     * Unix milliseconds for a log line.
     *
     * WoW stamps lines in the player's LOCAL time with no zone, so this interprets them in the
     * app's timezone. That makes the value correct for ordering and duration and approximate as
     * an absolute instant — which is all anything downstream uses it for. Stated rather than
     * silently assumed, because a fetched match's startTime came from wowarenalogs already in
     * UTC and the two are therefore not strictly comparable.
     */
    public function timestampMs(string $line): ?int
    {
        if (! preg_match(self::TIMESTAMP_FORMAT, $line, $m)) {
            return null;
        }

        [, $month, $day, $year, $hour, $minute, $second, $fraction] = $m;

        $stamp = mktime((int) $hour, (int) $minute, (int) $second, (int) $month, (int) $day, (int) $year);

        if ($stamp === false) {
            return null;
        }

        // The fractional part is written with a variable number of digits; normalise to ms.
        $ms = (int) round((float) ('0.'.$fraction) * 1000);

        return $stamp * 1000 + $ms;
    }

    /**
     * Writes one match's two files, in the archive's own layout. Returns null when the match is
     * already present, so a re-run over a growing WoWCombatLog.txt only does new work.
     *
     * @param  array<int, string>  $lines
     */
    public function store(array $metadata, array $lines, ArenaLogService $archive, bool $overwrite = false): ?array
    {
        $matchId = $metadata['id'];
        $rawPath = $archive->rawLogPath($matchId);
        $metaPath = $archive->metadataPath($matchId);

        if (! $overwrite && File::exists($metaPath)) {
            return null;
        }

        File::ensureDirectoryExists(dirname($rawPath));
        File::ensureDirectoryExists(dirname($metaPath));

        $raw = implode('', $lines);
        $compressed = gzencode($raw, 9);
        File::put($rawPath, $compressed);

        $metadata['fetchedAt'] = now()->toIso8601String();
        $metadata['sourceUrl'] = null;
        // So a later reader can tell a locally-ingested match from a fetched one without
        // guessing from the null logObjectUrl.
        $metadata['source'] = 'local-combatlog';

        File::put(
            $metaPath,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        return ['rawBytes' => strlen($raw), 'compressedBytes' => strlen($compressed)];
    }

    /** The part of a log line after the timestamp. */
    private function body(string $line): string
    {
        $split = explode('  ', $line, 2);

        return trim($split[1] ?? $split[0]);
    }

    /** @param resource $handle */
    private function readLine($handle, string $path): string|false
    {
        return str_ends_with(strtolower($path), '.gz') ? gzgets($handle) : fgets($handle);
    }
}
