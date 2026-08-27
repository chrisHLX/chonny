<?php

namespace App\Http\Services;

use App\Models\Spell;
use App\Models\SpellRelationship;
use App\Models\Specialization;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Talks to wowarenalogs.com's public, unauthenticated GraphQL API (see arena-log-api.md at
 * the repo root for the full reverse-engineered schema/format reference) to fetch individual
 * arena match logs and to search for matches by comp composition. Everything this service
 * does is read-only against a third-party service and write-only against
 * data/arena-logs/ — it never touches the app database.
 *
 * Four responsibilities, kept in one service because they share the same fetch/store
 * mechanics and are small:
 *   - fetchMatch()/storeMatch() — pull one known match ID, save raw log (gzipped) + metadata.
 *   - extractCastSpellsByPlayer()/mergeSpellUsage() — parse an already-stored match's raw log
 *     for what each real player actually cast and accumulate it into
 *     data/arena-logs/spell-usage/{classSlug}/{specSlug}.txt, feeding
 *     wow:extract-arena-spells/wow:diff-arena-spells/wow:discover-spec-spells (see those
 *     commands' docblocks — this is a match-history-based alternative to the addon-based
 *     spellbook_snapshots verification pipeline, with different coverage tradeoffs, not a
 *     strict replacement).
 *   - pullBestWinForComp()  — given a set of spec IDs, search for the highest-rated recent
 *     WIN by that exact comp and fetch+store it, but only if it's better than whatever this
 *     project already has on file for that comp (data/arena-logs/comp-index.json tracks the
 *     current best per comp signature) — so re-running this for the same comp is a no-op
 *     unless a genuinely higher-rated win has since appeared.
 *   - pullHighestRatedMatchForSpec() — the single-spec counterpart, no win/comp requirement,
 *     backing wow:discover-spec-spells's "surface untagged spells" pipeline. Tracked in a
 *     separate data/arena-logs/spec-index.json manifest (not comp-index.json — different
 *     query shape, different manifest key space).
 *
 * `lhsShouldBeWinner` + `units { info { teamId } } ` were verified empirically before this
 * was built (not assumed from the field name) — confirmed a real match where the queried
 * comp's units all carried `info.teamId` equal to `winningTeamId` and the opposing team's
 * specs did not match the query at all. searchCompWins() re-verifies this on every result
 * anyway (comparing the winning team's actual resolved spec set against the requested one)
 * rather than trusting the API's own filter blindly — matching this project's standing
 * "verify, don't just trust the query" discipline used throughout the rest of the WoW data
 * pipeline.
 */
class ArenaLogService
{
    private const API_URL = 'https://wowarenalogs.com/api/graphql';

    private const MATCH_FIELDS = <<<'GQL'
        __typename
        ... on ArenaMatchDataStub {
          id
          wowVersion
          logObjectUrl
          result
          winningTeamId
          playerTeamRating
          durationInSeconds
          startTime
          endTime
          startInfo { bracket zoneId isRanked }
          units { id name spec class reaction affiliation }
        }
        ... on ShuffleRoundStub {
          id
          wowVersion
          logObjectUrl
          result
          winningTeamId
          playerTeamRating
          killedUnitId
          sequenceNumber
          durationInSeconds
          startTime
          endTime
          startInfo { bracket zoneId isRanked }
          units { id name spec class reaction affiliation }
        }
        GQL;

    public function fetchMatch(string $matchId): ?array
    {
        $query = '
            query($matchId: String!) {
              matchById(matchId: $matchId) {
                '.self::MATCH_FIELDS.'
              }
            }
        ';

        $resp = Http::timeout(30)->post(self::API_URL, [
            'query' => $query,
            'variables' => ['matchId' => $matchId],
        ]);

        if (!$resp->successful()) {
            return null;
        }

        $match = $resp->json('data.matchById');

        return (!$match || empty($match['logObjectUrl'])) ? null : $match;
    }

    /**
     * Full path to a match's raw combat log — routed through the configurable archive root
     * (config('arena_logs.archive_path'), see that config file's docblock) rather than a
     * hardcoded base_path(), so the bulk archive can live outside this project entirely.
     *
     * Falls back to {archive_path}/season-current/raw/... when the flat top-level path doesn't
     * exist — added 2026-08-27, confirmed real gap: wow-arena-archive (the sibling project this
     * archive_path normally points at) organizes newly-pulled matches under its own
     * season-current/ subdirectory, which nothing in this codebase previously knew about —
     * every existing wow:* command was silently blind to any match living only there. Purely
     * additive: only ever checked when the primary path is missing, so this can only make more
     * matches resolvable, never change resolution for anything that already worked.
     */
    public function rawLogPath(string $matchId): string
    {
        $primary = config('arena_logs.archive_path')."/raw/{$matchId}.log.gz";

        if (!File::exists($primary)) {
            $fallback = config('arena_logs.archive_path')."/season-current/raw/{$matchId}.log.gz";

            if (File::exists($fallback)) {
                return $fallback;
            }
        }

        return $primary;
    }

    /**
     * Full path to a match's metadata JSON — same configurable archive root and same
     * season-current/ fallback as rawLogPath() (see that method's docblock).
     */
    public function metadataPath(string $matchId): string
    {
        $primary = config('arena_logs.archive_path')."/metadata/{$matchId}.json";

        if (!File::exists($primary)) {
            $fallback = config('arena_logs.archive_path')."/season-current/metadata/{$matchId}.json";

            if (File::exists($fallback)) {
                return $fallback;
            }
        }

        return $primary;
    }

    /**
     * Downloads the raw log for an already-fetched $match (from fetchMatch()) and writes
     * both output files. Returns byte-size info for reporting; throws nothing — caller
     * checks the returned array for 'error'.
     */
    public function storeMatch(string $matchId, array $match): array
    {
        $logResp = Http::timeout(30)->get($match['logObjectUrl']);

        if (!$logResp->successful()) {
            return ['error' => "Failed to download raw log: HTTP {$logResp->status()}"];
        }

        $rawPath = $this->rawLogPath($matchId);
        $metaPath = $this->metadataPath($matchId);
        File::ensureDirectoryExists(dirname($rawPath));
        File::ensureDirectoryExists(dirname($metaPath));

        $rawBody = $logResp->body();
        $compressed = gzencode($rawBody, 9);
        File::put($rawPath, $compressed);

        $metadata = $match;
        $metadata['fetchedAt'] = now()->toIso8601String();
        $metadata['sourceUrl'] = "https://wowarenalogs.com/match?id={$matchId}";

        File::put(
            $metaPath,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        return ['rawBytes' => strlen($rawBody), 'compressedBytes' => strlen($compressed)];
    }

    /**
     * Searches recent matches for the exact given comp (spec ID set) winning, verifies each
     * result against its own unit/teamId data (not just trusting lhsShouldBeWinner), and
     * returns verified candidates sorted by rating descending.
     *
     * REAL BUG, found and fixed 2026-08-14 (not a limitation of the API — a bug in this
     * method): compQueryString must be built with the SAME sort order the site's own
     * frontend uses to build its search index — `Array.prototype.sort()` with no comparator,
     * which JavaScript defaults to LEXICOGRAPHIC STRING sort, not numeric. The original
     * version of this method used PHP's numeric `sort()`, which only happens to agree with
     * string sort when every spec ID has the same digit count (e.g. 105/258/259, all
     * 3-digit — which is exactly why an early manual test of this looked like it worked).
     * Any comp mixing digit-counts (e.g. Frost Mage=64 with two 3-digit specs) sorts
     * differently — `sort([64,256,261])` gives "64_256_261" (numeric), but the site's real
     * index is keyed on "256_261_64" (string) — a different, silently-empty query, not an
     * error. Confirmed directly: a real, user-found RMP win
     * (6c2599072c903aa64aacc7effa000006, 2327 rating) was invisible under the numeric sort
     * and appeared immediately — first page, verified via direct matchById lookup — once the
     * query string was corrected to string-sort order. `sortSpecIdsForQuery()` below builds
     * the string-sorted query key; the numerically-sorted `$specIds` passed in is left
     * untouched everywhere else (manifest keys, the winning-team verification comparison) —
     * only the outgoing compQueryString needs the JS-compatible order.
     *
     * Separately confirmed the same day: `count` is silently capped at 50 server-side no
     * matter how high a value is requested (count=200/500/1000 all return exactly the same
     * 50 results as count=50) — `offset` is the real way to page deeper, not a larger
     * `count`. This method still only requests one page (the highest-rated recent result is
     * usually near the front anyway); a caller wanting to page deeper should call this
     * repeatedly with increasing `offset` — not built here since nothing has needed it yet.
     *
     * Given both of the above, "returns []" is still expected/correct for a comp that
     * genuinely hasn't been played recently — just confirm the compQueryString is right
     * before concluding that (see sortSpecIdsForQuery()'s docblock for how to check).
     *
     * @param  int[]  $specIds  Blizzard specialization IDs (e.g. our specializations.external_spec_id)
     * @return array<int, array{matchId: string, rating: int, specIds: int[]}>
     */
    public function searchCompWins(array $specIds, string $bracket = '3v3', int $count = 50, int $offset = 0): array
    {
        $compQueryString = $this->sortSpecIdsForQuery($specIds);

        $query = '
            query($bracket: String, $compQueryString: String, $count: Int, $offset: Int) {
              latestMatches(
                wowVersion: "retail", bracket: $bracket, compQueryString: $compQueryString,
                lhsShouldBeWinner: true, offset: $offset, count: $count
              ) {
                combats {
                  __typename
                  ... on ArenaMatchDataStub {
                    id playerTeamRating winningTeamId
                    units { id name spec info { teamId } }
                  }
                }
              }
            }
        ';

        $resp = Http::timeout(30)->post(self::API_URL, [
            'query' => $query,
            'variables' => ['bracket' => $bracket, 'compQueryString' => $compQueryString, 'count' => $count, 'offset' => $offset],
        ]);

        if (!$resp->successful()) {
            return [];
        }

        $numericSpecIds = $specIds;
        sort($numericSpecIds);

        $combats = $resp->json('data.latestMatches.combats') ?? [];
        $verified = [];

        foreach ($combats as $combat) {
            if (($combat['__typename'] ?? null) !== 'ArenaMatchDataStub') {
                continue;
            }

            $winningTeamSpecs = collect($combat['units'] ?? [])
                ->filter(fn ($u) => ($u['info']['teamId'] ?? null) === $combat['winningTeamId'] && $u['spec'] !== '0')
                ->pluck('spec')
                ->map(fn ($s) => (int) $s)
                ->sort()
                ->values()
                ->all();

            if ($winningTeamSpecs === $numericSpecIds) {
                $verified[] = [
                    'matchId' => $combat['id'],
                    'rating' => (int) $combat['playerTeamRating'],
                    'specIds' => $numericSpecIds,
                ];
            }
        }

        usort($verified, fn ($a, $b) => $b['rating'] <=> $a['rating']);

        return $verified;
    }

    /**
     * Builds compQueryString using the exact same ordering the site's own frontend produces
     * — JavaScript's default `Array.prototype.sort()` (lexicographic string comparison, NOT
     * numeric) — since that's what their search index is actually keyed on. PHP's
     * `sort(SORT_STRING)` matches this: each element is compared as a string, so "64" sorts
     * after "256"/"261" (first character '6' > '2'), the opposite of numeric order. See
     * searchCompWins()'s docblock for the real bug this fixes and how it was confirmed.
     */
    private function sortSpecIdsForQuery(array $specIds): string
    {
        $stringSorted = $specIds;
        sort($stringSorted, SORT_STRING);

        return implode('_', $stringSorted);
    }

    /**
     * The full pipeline: search, compare against data/arena-logs/comp-index.json, fetch+store
     * only if this comp has no stored match yet or the new one rates higher. Returns a status
     * string ('no_match_found' | 'already_best' | 'stored_new') plus details for the caller
     * to report.
     */
    public function pullBestWinForComp(array $specIds, string $bracket = '3v3', int $count = 50): array
    {
        sort($specIds);
        $signature = implode('_', $specIds);

        $candidates = $this->searchCompWins($specIds, $bracket, $count);

        if ($candidates === []) {
            return ['status' => 'no_match_found', 'signature' => $signature];
        }

        $best = $candidates[0];
        $manifest = $this->loadManifest('comp-index.json');
        $existing = $manifest[$signature] ?? null;

        if ($existing !== null && $existing['rating'] >= $best['rating']) {
            return [
                'status' => 'already_best',
                'signature' => $signature,
                'existingRating' => $existing['rating'],
                'foundRating' => $best['rating'],
                'matchId' => $existing['matchId'],
            ];
        }

        $match = $this->fetchMatch($best['matchId']);

        if ($match === null) {
            return ['status' => 'fetch_failed', 'signature' => $signature, 'matchId' => $best['matchId']];
        }

        $stored = $this->storeMatch($best['matchId'], $match);

        if (isset($stored['error'])) {
            return ['status' => 'fetch_failed', 'signature' => $signature, 'matchId' => $best['matchId'], 'error' => $stored['error']];
        }

        $manifest[$signature] = [
            'specIds' => $specIds,
            'bracket' => $bracket,
            'matchId' => $best['matchId'],
            'rating' => $best['rating'],
            'previousRating' => $existing['rating'] ?? null,
            'fetchedAt' => now()->toIso8601String(),
        ];
        $this->saveManifest('comp-index.json', $manifest);

        return [
            'status' => 'stored_new',
            'signature' => $signature,
            'matchId' => $best['matchId'],
            'rating' => $best['rating'],
            'previousRating' => $existing['rating'] ?? null,
        ];
    }

    /**
     * Parses an already-fetched match's raw log (must have been pulled via fetchMatch()+
     * storeMatch()/wow:fetch-arena-log first — this never hits the network) and returns, per
     * real player, the distinct set of spells they successfully cast (SPELL_CAST_SUCCESS
     * only — this is "what did this class/spec actually use in this one match", not a full
     * spellbook export; a single ~4 minute match will only ever surface a subset of a
     * player's real kit, see wow:diff-arena-spells's docblock for why that matters for how
     * the resulting diff should be read).
     *
     * Real players are identified by GUID prefix ("Player-...") rather than name pattern or
     * the union's own `reaction`/`affiliation` fields, both of which have been shown
     * elsewhere in this project's arena-log work to not reliably distinguish teams/pets.
     *
     * @return array<int, array{unitId: string, name: string, specExternalId: int, specId: ?int, classId: ?int, spells: array<int, array{spellId: int, name: string}>}>
     */
    public function extractCastSpellsByPlayer(string $matchId): array
    {
        $metaPath = $this->metadataPath($matchId);
        $rawPath = $this->rawLogPath($matchId);

        if (!File::exists($metaPath) || !File::exists($rawPath)) {
            throw new \RuntimeException("Match {$matchId} is not on file — run wow:fetch-arena-log first.");
        }

        $metadata = json_decode(File::get($metaPath), true);
        $rawLog = gzdecode(File::get($rawPath));

        $players = [];

        foreach ($metadata['units'] ?? [] as $unit) {
            if (!str_starts_with($unit['id'], 'Player-') || $unit['spec'] === '0') {
                continue;
            }

            $specExternalId = (int) $unit['spec'];
            $spec = Specialization::where('external_spec_id', $specExternalId)->first();

            $guid = preg_quote($unit['id'], '/');
            $pattern = '/SPELL_CAST_SUCCESS,'.$guid.',"[^"]*",0x[0-9a-fA-F]+,0x[0-9a-fA-F]+,[^,]*,[^,]*,0x[0-9a-fA-F]+,0x[0-9a-fA-F]+,(\d+),"([^"]*)"/';
            preg_match_all($pattern, $rawLog, $matches, PREG_SET_ORDER);

            $spells = [];
            $seenIds = [];
            foreach ($matches as $m) {
                $spellId = (int) $m[1];
                if (!isset($seenIds[$spellId])) {
                    $seenIds[$spellId] = true;
                    $spells[] = ['spellId' => $spellId, 'name' => $m[2]];
                }
            }

            $players[] = [
                'unitId' => $unit['id'],
                'name' => $unit['name'],
                'specExternalId' => $specExternalId,
                'specId' => $spec?->id,
                'classId' => $spec?->class_id,
                'spells' => $spells,
            ];
        }

        return $players;
    }

    /**
     * Minimum match length (seconds) for pullHighestRatedMatchForSpec() to consider a
     * candidate. Found necessary 2026-08-14, same day as the sort-order bug: wow:discover-all-specs
     * picked an 18-second match as the "highest rated" for both Frost Mage and Arms Warrior
     * (2543 rating, real, but the target players only got 1 and 0 casts off respectively
     * before it ended — confirmed by checking the match's own durationInSeconds directly, not
     * guessed) — a real blowout/early-death outranking every longer, far more useful match
     * purely on rating. 30s is a low, deliberately permissive floor: long enough to exclude
     * a near-instant kill, short enough not to reject genuinely fast, high-skill matches.
     */
    private const MIN_MATCH_DURATION_SECONDS = 30;

    /**
     * Searches recent matches containing the given spec on EITHER side, win or loss — no
     * exact-team-comp requirement and no lhsShouldBeWinner filter, unlike searchCompWins().
     * Deliberately broader/looser: the point (see pullHighestRatedMatchForSpec()'s docblock)
     * is just "find real matches where this spec got played," not "find this spec winning as
     * part of one specific comp" — win/loss is irrelevant to whether a spell got cast.
     *
     * Still re-verifies every result actually has the requested spec present, rather than
     * trusting compQueryString blindly (same discipline as searchCompWins(), even though a
     * single-element sort has no digit-count ordering ambiguity to get wrong). Also drops
     * anything under MIN_MATCH_DURATION_SECONDS — see that constant's docblock for why.
     *
     * @return array<int, array{matchId: string, rating: int, durationInSeconds: int}>
     */
    private function searchMatchesForSpec(int $specExternalId, string $bracket, int $offset, int $count): array
    {
        $query = '
            query($bracket: String, $compQueryString: String, $count: Int, $offset: Int) {
              latestMatches(
                wowVersion: "retail", bracket: $bracket, compQueryString: $compQueryString,
                offset: $offset, count: $count
              ) {
                combats {
                  __typename
                  ... on ArenaMatchDataStub { id playerTeamRating durationInSeconds units { spec } }
                }
              }
            }
        ';

        $resp = Http::timeout(30)->post(self::API_URL, [
            'query' => $query,
            'variables' => ['bracket' => $bracket, 'compQueryString' => (string) $specExternalId, 'count' => $count, 'offset' => $offset],
        ]);

        if (!$resp->successful()) {
            return [];
        }

        $combats = $resp->json('data.latestMatches.combats') ?? [];
        $results = [];

        foreach ($combats as $combat) {
            if (($combat['__typename'] ?? null) !== 'ArenaMatchDataStub') {
                continue;
            }

            $duration = (int) ($combat['durationInSeconds'] ?? 0);

            if ($duration < self::MIN_MATCH_DURATION_SECONDS) {
                continue;
            }

            $hasSpec = collect($combat['units'] ?? [])->contains(fn ($u) => (int) $u['spec'] === $specExternalId);

            if ($hasSpec) {
                $results[] = ['matchId' => $combat['id'], 'rating' => (int) $combat['playerTeamRating'], 'durationInSeconds' => $duration];
            }
        }

        return $results;
    }

    /**
     * Searches the most recent matches for a bracket with NO comp/spec filter at all — the
     * plain "latest matches" feed behind the site's own /search page when nothing is set.
     * Unlike searchMatchesForSpec()/searchCompWins() there's no comp claim to re-verify, so
     * results come back as-is beyond the same MIN_MATCH_DURATION_SECONDS blowout floor every
     * other puller in this service applies.
     *
     * `count` is capped at 50 server-side (see searchCompWins()'s docblock) — page with
     * `offset` (0, 50, 100, ...). Backs wow:pull-latest-matches.
     *
     * @return array<int, array{matchId: string, rating: int, durationInSeconds: int}>
     */
    public function searchLatestMatches(string $bracket, int $offset, int $count = 50): array
    {
        $query = '
            query($bracket: String, $count: Int, $offset: Int) {
              latestMatches(
                wowVersion: "retail", bracket: $bracket, offset: $offset, count: $count
              ) {
                combats {
                  __typename
                  ... on ArenaMatchDataStub { id playerTeamRating durationInSeconds }
                }
              }
            }
        ';

        $resp = Http::timeout(30)->post(self::API_URL, [
            'query' => $query,
            'variables' => ['bracket' => $bracket, 'count' => $count, 'offset' => $offset],
        ]);

        if (!$resp->successful()) {
            return [];
        }

        $combats = $resp->json('data.latestMatches.combats') ?? [];
        $results = [];

        foreach ($combats as $combat) {
            if (($combat['__typename'] ?? null) !== 'ArenaMatchDataStub') {
                continue;
            }

            $duration = (int) ($combat['durationInSeconds'] ?? 0);

            if ($duration < self::MIN_MATCH_DURATION_SECONDS) {
                continue;
            }

            $results[] = [
                'matchId' => $combat['id'],
                'rating' => (int) $combat['playerTeamRating'],
                'durationInSeconds' => $duration,
            ];
        }

        return $results;
    }

    /**
     * Finds the highest-rated recent match containing the given spec (any team, win or
     * loss), fetch+stores it only if better than data/arena-logs/spec-index.json's current
     * best for that spec — same "only replace on genuine improvement" rule as
     * pullBestWinForComp(), separate manifest file since the two searches aren't the same
     * kind of query (exact-team-comp-win vs. single-spec-presence).
     *
     * `latestMatches` has no rating-sort and `count` cap's at 50/request (see
     * searchCompWins()'s docblock) — this pages through `$pages` requests of `offset`
     * (0, 50, 100, ...) collecting real candidates and picking the max client-side, same
     * approach as everywhere else in this service that needs "highest rated recent."
     *
     * @return array{status: string, specExternalId: int, matchId?: string, rating?: int, previousRating?: ?int, error?: string}
     */
    public function pullHighestRatedMatchForSpec(int $specExternalId, string $bracket = '3v3', int $pages = 3): array
    {
        $candidates = $this->gatherSpecCandidates($specExternalId, $bracket, $pages);

        if ($candidates === []) {
            return ['status' => 'no_match_found', 'specExternalId' => $specExternalId];
        }

        usort($candidates, fn ($a, $b) => $b['rating'] <=> $a['rating']);
        $best = $candidates[0];

        $manifest = $this->loadManifest('spec-index.json');
        $key = (string) $specExternalId;
        $existing = $manifest[$key] ?? null;

        if ($existing !== null && $existing['rating'] >= $best['rating']) {
            return [
                'status' => 'already_best',
                'specExternalId' => $specExternalId,
                'matchId' => $existing['matchId'],
                'rating' => $existing['rating'],
            ];
        }

        $match = $this->fetchMatch($best['matchId']);

        if ($match === null) {
            return ['status' => 'fetch_failed', 'specExternalId' => $specExternalId, 'matchId' => $best['matchId']];
        }

        $stored = $this->storeMatch($best['matchId'], $match);

        if (isset($stored['error'])) {
            return ['status' => 'fetch_failed', 'specExternalId' => $specExternalId, 'matchId' => $best['matchId'], 'error' => $stored['error']];
        }

        $manifest[$key] = [
            'specExternalId' => $specExternalId,
            'bracket' => $bracket,
            'matchId' => $best['matchId'],
            'rating' => $best['rating'],
            'previousRating' => $existing['rating'] ?? null,
            'fetchedAt' => now()->toIso8601String(),
        ];
        $this->saveManifest('spec-index.json', $manifest);

        return [
            'status' => 'stored_new',
            'specExternalId' => $specExternalId,
            'matchId' => $best['matchId'],
            'rating' => $best['rating'],
            'previousRating' => $existing['rating'] ?? null,
        ];
    }

    /**
     * Shared candidate-gathering for both pullHighestRatedMatchForSpec() and
     * pullTopMatchesForSpec() — paginates searchMatchesForSpec() across $pages requests of
     * offset (0, 50, 100, ...), deduped by match id.
     *
     * @return array<int, array{matchId: string, rating: int, durationInSeconds: int}>
     */
    private function gatherSpecCandidates(int $specExternalId, string $bracket, int $pages): array
    {
        $candidates = [];
        $seen = [];

        for ($page = 0; $page < $pages; $page++) {
            foreach ($this->searchMatchesForSpec($specExternalId, $bracket, $page * 50, 50) as $c) {
                if (!isset($seen[$c['matchId']])) {
                    $seen[$c['matchId']] = true;
                    $candidates[] = $c;
                }
            }
        }

        return $candidates;
    }

    /**
     * Pulls the top $topN highest-rated recent matches for a spec (not just the single best
     * one pullHighestRatedMatchForSpec() tracks) — built 2026-08-14 for maximizing CC-duration
     * discovery coverage: a spell that never appeared in "the" best match for a spec might
     * still show up in the 2nd- or 3rd-highest-rated one. Deliberately bypasses
     * spec-index.json's "only the single best" manifest entirely — this is a different goal
     * (broad coverage across several matches) from that manifest's (avoid redundant re-fetches
     * of one canonical match per spec), so mixing the two would be the wrong semantics for
     * either use case.
     *
     * Skips a candidate entirely (no network call) if its metadata file already exists on
     * disk — matches already pulled via any other path (wow:fetch-arena-log,
     * wow:pull-comp-log, a previous run of this same method for a different spec) are never
     * re-fetched, since a match's content never changes once played.
     *
     * @return array<int, array{matchId: string, rating: int, status: string}>
     */
    public function pullTopMatchesForSpec(int $specExternalId, string $bracket, int $pages, int $topN): array
    {
        $candidates = $this->gatherSpecCandidates($specExternalId, $bracket, $pages);
        usort($candidates, fn ($a, $b) => $b['rating'] <=> $a['rating']);
        $top = array_slice($candidates, 0, $topN);

        $results = [];

        foreach ($top as $c) {
            $matchId = $c['matchId'];

            if (File::exists($this->metadataPath($matchId))) {
                $results[] = ['matchId' => $matchId, 'rating' => $c['rating'], 'status' => 'already_on_disk'];

                continue;
            }

            $match = $this->fetchMatch($matchId);

            if ($match === null) {
                $results[] = ['matchId' => $matchId, 'rating' => $c['rating'], 'status' => 'fetch_failed'];

                continue;
            }

            $stored = $this->storeMatch($matchId, $match);
            $results[] = ['matchId' => $matchId, 'rating' => $c['rating'], 'status' => isset($stored['error']) ? 'fetch_failed' : 'stored'];
        }

        return $results;
    }

    /**
     * The low-rating counterpart to pullTopMatchesForSpec() — same gather-across-pages,
     * dedupe-by-matchId-already-on-disk mechanics, but sorts ascending and filters to
     * $ratingCeiling and below instead of taking the highest. Built 2026-08-15 because the
     * comp/spec-index manifests (pullBestWinForComp()/pullHighestRatedMatchForSpec()) only
     * ever track a single "best" match per key — there is no existing path that deliberately
     * goes after LOW-rated matches, and a rating-tier comparison (e.g. 2800 vs ~2100 play)
     * needs several of those, not the accidental few that happened to already be on disk from
     * earlier comp/spec pulls.
     *
     * `latestMatches` has no server-side max-rating filter (only `minRating` exists in the
     * schema, per arena-log-api.md) — this pages through recent matches with NO rating filter
     * applied server-side and does the ceiling filtering client-side, same "gather then sort/
     * filter in PHP" approach gatherSpecCandidates() already uses for the opposite direction.
     * Recent matches span the full ladder by construction (whoever happens to be queuing right
     * now), so a plain recent-matches scan surfaces plenty of sub-2300 games without needing
     * any special low-rating query mode.
     *
     * No manifest — unlike pullHighestRatedMatchForSpec()/pullBestWinForComp(), "the low-rated
     * match for this spec" isn't a single stable target to track/replace-on-improvement the way
     * "the highest-rated" is (there's no single canonical answer to converge on), so this just
     * pulls up to $topN distinct low-rated matches it finds and returns per-match status. Skips
     * (no network call) any match already on disk, same as pullTopMatchesForSpec().
     *
     * $ratingFloor added 2026-08-15 (optional, defaults to 0 — original ceiling-only behavior
     * unchanged for existing callers) so this can target a specific band (e.g. 2100–2400, the
     * "Duelist" title range) instead of always pulling from the absolute bottom of the ladder up.
     * Without a floor, sorting ascending under a ceiling keeps grabbing whatever is lowest-rated
     * in the scanned window first — confirmed in practice pulling Havoc data 2026-08-15: a
     * 2300 ceiling with no floor returned matches clustered in the 1700s–1900s, nothing near
     * 2100–2300, because that's simply where the bulk of the recent-match population sits. A
     * floor is the only way to force coverage of a specific mid-range band rather than always
     * re-finding the same bottom-of-the-ladder games.
     *
     * @return array<int, array{matchId: string, rating: int, status: string}>
     */
    public function pullLowRatedMatchesForSpec(int $specExternalId, string $bracket, int $pages, int $topN, int $ratingCeiling, int $ratingFloor = 0): array
    {
        $candidates = $this->gatherSpecCandidates($specExternalId, $bracket, $pages);
        $candidates = array_values(array_filter($candidates, fn ($c) => $c['rating'] > $ratingFloor && $c['rating'] <= $ratingCeiling));
        usort($candidates, fn ($a, $b) => $a['rating'] <=> $b['rating']);
        $chosen = array_slice($candidates, 0, $topN);

        $results = [];

        foreach ($chosen as $c) {
            $matchId = $c['matchId'];

            if (File::exists($this->metadataPath($matchId))) {
                $results[] = ['matchId' => $matchId, 'rating' => $c['rating'], 'status' => 'already_on_disk'];

                continue;
            }

            $match = $this->fetchMatch($matchId);

            if ($match === null) {
                $results[] = ['matchId' => $matchId, 'rating' => $c['rating'], 'status' => 'fetch_failed'];

                continue;
            }

            $stored = $this->storeMatch($matchId, $match);
            $results[] = ['matchId' => $matchId, 'rating' => $c['rating'], 'status' => isset($stored['error']) ? 'fetch_failed' : 'stored'];
        }

        return $results;
    }

    /**
     * Merges a match's cast-spell list for one (class, spec) into the cumulative, deduped
     * plain-text file at {archive_path}/spell-usage/{classSlug}/{specSlug}.txt — shared by
     * wow:extract-arena-spells (manual, per-match) and wow:discover-spec-spells (automated
     * search+pull+extract). See either command's docblock for the file format and the
     * "this is partial coverage, not a full spellbook" caveat.
     *
     * Writes to config('arena_logs.archive_path'), NOT this project's own
     * data/arena-logs/spell-usage/ — see that config file's own docblock (2026-08-20). This is
     * the staging copy; spellUsageIds() below (the one the live app actually reads) is
     * deliberately still pinned to this project's own tree and only picks up new data once a
     * human manually copies it over after reviewing it.
     *
     * @param  array<int, array{spellId: int, name: string}>  $newSpells
     */
    public function mergeSpellUsage(string $classSlug, string $specSlug, string $matchId, array $newSpells): void
    {
        $dir = config('arena_logs.archive_path')."/spell-usage/{$classSlug}";
        File::ensureDirectoryExists($dir);
        $path = "{$dir}/{$specSlug}.txt";

        $existing = [];
        $seenMatchIds = [];

        if (File::exists($path)) {
            foreach (File::lines($path) as $line) {
                $line = trim($line);

                if (str_starts_with($line, '# seen in matches:')) {
                    $ids = trim(substr($line, strlen('# seen in matches:')));
                    $seenMatchIds = array_filter(array_map('trim', explode(',', $ids)));

                    continue;
                }

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = array_map('trim', explode('|', $line, 2));

                if (count($parts) === 2 && ctype_digit($parts[0])) {
                    $existing[(int) $parts[0]] = $parts[1];
                }
            }
        }

        foreach ($newSpells as $s) {
            $existing[$s['spellId']] = $s['name'];
        }

        if (!in_array($matchId, $seenMatchIds, true)) {
            $seenMatchIds[] = $matchId;
        }

        ksort($existing);

        $lines = ['# seen in matches: '.implode(', ', $seenMatchIds)];
        $lines[] = "# accumulated cast-spell list for {$classSlug}/{$specSlug} — see wow:extract-arena-spells / wow:diff-arena-spells / wow:discover-spec-spells";
        $lines[] = '';

        foreach ($existing as $spellId => $name) {
            $lines[] = "{$spellId} | {$name}";
        }

        File::put($path, implode("\n", $lines)."\n");
    }

    /**
     * Resolves the accumulated spell-usage file for one (class, spec) into the set of Blizzard
     * external spell_ids it names — i.e. every spell positively confirmed cast by a real player
     * of this exact spec, across every match fed into mergeSpellUsage() so far. Pure read, same
     * `spell_id | name` parsing convention as the file's own writer above.
     *
     * Extracted 2026-08-18 as the single shared implementation behind two independent
     * consumers that had each grown their own copy of this exact parsing loop:
     * SpellExplorer::priorityExternalSpellIds() (the "Priority Spells" filter) and
     * WowComps's own "Cooldowns" tab (added the same day). A spec with no usage file yet (no
     * matches processed for it) simply returns an empty collection — not an error state.
     *
     * Deliberately still reads this project's own base_path('data/arena-logs/...') tree, NOT
     * config('arena_logs.archive_path') — this is what SpellExplorer/WowComps show live, so it
     * must only ever reflect data a human has manually promoted after review. mergeSpellUsage()
     * above writes fresh pulls to the archive instead; this method has no visibility into that
     * staging copy until someone copies the files over. See config/arena_logs.php (2026-08-20).
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function spellUsageIds(string $classSlug, string $specSlug): \Illuminate\Support\Collection
    {
        $path = base_path("data/arena-logs/spell-usage/{$classSlug}/{$specSlug}.txt");

        if (!File::exists($path)) {
            return collect();
        }

        $ids = [];
        foreach (File::lines($path) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2 && ctype_digit($parts[0])) {
                $ids[] = (int) $parts[0];
            }
        }

        return collect($ids);
    }

    /**
     * Reads the STAGING mechanics.txt for one spec, built by
     * `wow:record-important-mechanics --all` (see spell-acquisition-model.md's "Post-pull
     * workflow" section). Deliberately reads `config('arena_logs.archive_path')` directly —
     * the staging copy, not a promoted one — since this backs a review-only surface
     * (TopDamageRotations' "Important Mechanics (Review)" block, added 2026-08-27): the whole
     * point is letting a human look at freshly-generated, not-yet-trusted candidates before
     * deciding whether any of it should ever inform real, reviewed content elsewhere. This is
     * the one deliberate exception to this project's "never read the staging archive_path for
     * anything the live app shows" rule (see config/arena_logs.php's own docblock) — the
     * exception holds only because the block itself is plainly labeled unreviewed, the same way
     * `Admin\CcReview` is allowed to surface bulk-applied-but-unspotchecked data.
     *
     * @return array{generatedAt: ?string, windowsFound: ?int, matchesScanned: ?int, windowSeconds: ?int, rows: array<int, array{spellId: int, type: string, pct: int, matchCount: int, totalWindows: int, name: string}>}|null
     */
    public function mechanicsForSpec(string $classSlug, string $specSlug): ?array
    {
        $path = config('arena_logs.archive_path')."/mechanics/{$classSlug}/{$specSlug}.txt";

        if (!File::exists($path)) {
            return null;
        }

        $generatedAt = null;
        $windowsFound = null;
        $matchesScanned = null;
        $windowSeconds = null;
        $rows = [];

        foreach (File::lines($path) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '# generated')) {
                if (preg_match('/# generated (\S+) — (\d+) pre-kill window\(s\) \(--window=(\d+)s\) across (\d+) scanned match\(es\)/', $line, $m)) {
                    $generatedAt = $m[1];
                    $windowsFound = (int) $m[2];
                    $windowSeconds = (int) $m[3];
                    $matchesScanned = (int) $m[4];
                }

                continue;
            }

            if (str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 5));

            if (count($parts) !== 5 || !ctype_digit($parts[0])) {
                continue;
            }

            [$matchCount, $totalWindows] = array_pad(explode('/', $parts[3]), 2, '0');

            $rows[] = [
                'spellId' => (int) $parts[0],
                'type' => $parts[1],
                'pct' => (int) rtrim($parts[2], '%'),
                'matchCount' => (int) $matchCount,
                'totalWindows' => (int) $totalWindows,
                'name' => $parts[4],
            ];
        }

        if ($rows === []) {
            return null;
        }

        return compact('generatedAt', 'windowsFound', 'matchesScanned', 'windowSeconds', 'rows');
    }

    /**
     * Loads the promoted offensive/defensive cooldown classification from
     * data/arena-logs/spell-classification/{offensive-buffs,offensive-spells,defensive-
     * cooldowns,mixed-cooldowns}.json — produced by wow-arena-archive's classify-cooldowns.php
     * (a separate project, see CLAUDE.md's "Offensive/Defensive Cooldown Classifier" section)
     * from real, arena-log-verified cast evidence, then manually promoted here once reviewed.
     * NOT computed live by this project — this is a read of already-reviewed, static output.
     *
     * Returns two parallel maps (by external spell_id, and by base display name as a same-named-
     * sibling fallback — see resolveIsPriority()'s own docblock just below for why that fallback
     * exists) — each spell_id/name mapping to ['offensive' => bool, 'defensive' => bool, 'label'
     * => string]. A spell from mixed-cooldowns.json sets BOTH offensive and defensive true,
     * matching that bucket's own honest "has signals from both, don't force one" definition.
     *
     * @return array{bySpellId: array<int, array{offensive: bool, defensive: bool, label: string}>, byName: array<string, array{offensive: bool, defensive: bool, label: string}>}
     */
    public function offensiveDefensiveClassification(): array
    {
        $dir = base_path('data/arena-logs/spell-classification');
        $files = [
            'offensive-spells.json' => ['offensive' => true, 'defensive' => false, 'label' => 'Offensive Spell'],
            'offensive-buffs.json' => ['offensive' => true, 'defensive' => false, 'label' => 'Offensive Buff'],
            'defensive-cooldowns.json' => ['offensive' => false, 'defensive' => true, 'label' => 'Defensive'],
            'mixed-cooldowns.json' => ['offensive' => true, 'defensive' => true, 'label' => 'Mixed'],
        ];

        $bySpellId = [];
        $byName = [];

        foreach ($files as $filename => $flags) {
            $path = "{$dir}/{$filename}";
            if (!File::exists($path)) {
                continue;
            }

            $entries = json_decode(File::get($path), true) ?? [];
            foreach ($entries as $entry) {
                $existing = $bySpellId[$entry['spellId']] ?? ['offensive' => false, 'defensive' => false, 'label' => $flags['label']];
                $bySpellId[$entry['spellId']] = [
                    'offensive' => $existing['offensive'] || $flags['offensive'],
                    'defensive' => $existing['defensive'] || $flags['defensive'],
                    'label' => $flags['label'],
                ];
                $byName[$entry['name']] = $bySpellId[$entry['spellId']];
            }
        }

        return ['bySpellId' => $bySpellId, 'byName' => $byName];
    }

    /**
     * The promoted offensive-rotation summary for one spec, from
     * data/arena-logs/rotations/{classSlug}/{specSlug}.json — produced by wow-arena-archive's
     * offensive-rotations.php (see that script's docblock for the full derivation) and manually
     * promoted here once reviewed, same flow as the offensive/defensive classification above.
     *
     * Each file holds `topDpsWindow` — the spec's single highest-damage real 12-second burst,
     * found by scanning each real match's ENTIRE timeline (not boxed inside any one cooldown's
     * usage window — see the script's findBestUnrestrictedBursts() docblock for a real, confirmed
     * case this correction caught: a denser burst outside every pre-built 30s anchor box, using
     * Blood Fury + a trinket, that the earlier nested-search design could never have found) for
     * the densest real 12s stretch of damage against one real target that also contains one of
     * the spec's own real offensive cooldowns. Replaced the earlier "most common exact combo"
     * approach entirely 2026-08-21, then the 10s nested-search approach 2026-08-22 (direct
     * instruction: "Yes switch it over"). `topDpsWindowsByLength` carries the same result at
     * 6/10/12/20/30s for a future leaderboard — only the 12s entry is read by the app today.
     * Steps carry each cast's own real spell_id from that exact real window so the UI can render
     * real icons.
     *
     * Returns null when a spec has no promoted file — a legitimate "not enough evidence yet"
     * state the caller renders as such, not an error.
     *
     * @return array{windows:int, killWindows:int, matches:int, anchors:array, majorAnchors:array, topDpsWindow:?array, topDpsWindowsByLength:array}|null
     */
    public function rotationForSpec(string $classSlug, string $specSlug): ?array
    {
        $path = base_path("data/arena-logs/rotations/{$classSlug}/{$specSlug}.json");

        if (!File::exists($path)) {
            return null;
        }

        return json_decode(File::get($path), true) ?: null;
    }

    /**
     * Resolves each step's real spell_id (already on the step, from that exact real cast) into
     * an actual Spell model, preferring the talent-linked copy over whichever internal spell_id
     * the archive's "most-cast in sampled matches" heuristic happened to pick (see
     * TalentSelectionService::preferTalentLinkedCopy()'s docblock for the confirmed Doom Winds
     * case this closes). Extracted 2026-08-22 from WowComps::getOffensiveRotationsProperty() so
     * TopDamageRotations (a second consumer of the same rotationForSpec() export, reading
     * whichever length the viewer picks from `topDpsWindowsByLength` instead of always the one
     * length WowComps has wired in) doesn't duplicate this resolution logic.
     *
     * @param  array<int, array{name: string, spellId: int, isCc: bool, isRepeat: bool}>  $steps
     * @return array<int, array{name: string, spellId: int, isCc: bool, isRepeat: bool, spell: ?Spell}>
     */
    public function resolveWindowSteps(array $steps, int $specId, \App\Http\Services\TalentSelectionService $talentService): array
    {
        $stepIds = collect($steps)->pluck('spellId')->filter()->unique();

        $spellsById = $stepIds->isEmpty()
            ? collect()
            : \App\Models\Spell::whereIn('spell_id', $stepIds)
                ->where('patch_id', \App\Models\Patch::where('is_current', true)->value('id'))
                ->get()
                ->keyBy('spell_id');

        return array_map(function ($step) use ($spellsById, $talentService, $specId) {
            $spell = $spellsById[$step['spellId']] ?? null;
            if ($spell) {
                $spell = $talentService->preferTalentLinkedCopy($spell, $specId);
            }
            $step['spell'] = $spell;
            return $step;
        }, $steps);
    }

    /**
     * Whether $spell should be treated as arena-log-confirmed ("priority"), checking not just
     * its own spell_id against $priorityExternalIds but also any same-named sibling in the same
     * patch — the same "one real ability, split across multiple internal spell_id records"
     * recovery this codebase already applies for cooldown/duration/description (see
     * ModuleSpellReferenceService::resolveBaseCooldownCharges()/resolveDescription()).
     *
     * Added 2026-08-19 after a real, confirmed case: Druid's Typhoon is displayed via spell_id
     * 132469 (the real class-tree talent, genuinely reachable via allTalentSpellIds() — the
     * copy with actual cooldown data), but real arena combat logs record the cast event under a
     * DIFFERENT internal spell_id, 61391 (identical name, identical description, but flagged
     * not_in_spellbook and missing direct cooldown data — a separate internal record Blizzard's
     * own combat log API happens to report for the cast, not the talent-tree definition record).
     * A plain exact-spell_id match against spellUsageIds() never matches the displayed 132469,
     * so Typhoon silently vanished from the Cooldowns tab (isPriority filter) even though it's
     * genuinely, confirmedly cast in real matches. The fix is NOT to add 61391 as a second,
     * independently-displayed override — 132469 is already reachable via the talent tree, so
     * doing that would just recreate a duplicate row (the exact class of bug this same session
     * fixed elsewhere) instead of a gap. Checking siblings here closes the real gap without
     * touching availability at all.
     */
    public function isPrioritySpell(Spell $spell, \Illuminate\Support\Collection $priorityExternalIds): bool
    {
        if ($priorityExternalIds->contains($spell->spell_id)) {
            return true;
        }

        $baseName = $spell->display_name;

        if ($baseName === '') {
            return false;
        }

        return Spell::where('patch_id', $spell->patch_id)
            ->where('id', '!=', $spell->id)
            ->where(fn ($q) => $q->where('name', $baseName)->orWhere('name', 'LIKE', $baseName.' (desc=%'))
            ->pluck('spell_id')
            ->intersect($priorityExternalIds)
            ->isNotEmpty();
    }

    /**
     * Bulk version of isPrioritySpell() — resolves priority status for every spell in $spells
     * via ONE query per patch (grouped by base display name in PHP) instead of one query per
     * spell whose own spell_id doesn't directly match $priorityExternalIds. Added 2026-08-19
     * alongside ModuleSpellReferenceService::preloadBaseCooldownCharges(), after profiling a
     * cold WowComps render: isPrioritySpell()'s per-spell sibling query was ~140 of ~1800 total
     * queries. Produces byte-identical results to calling isPrioritySpell() once per spell —
     * this only changes where the sibling data comes from.
     *
     * @param  \Illuminate\Support\Collection<int, Spell>  $spells
     * @return \Illuminate\Support\Collection<int, bool> keyed by spell->id
     */
    public function preloadPrioritySpells(\Illuminate\Support\Collection $spells, \Illuminate\Support\Collection $priorityExternalIds): \Illuminate\Support\Collection
    {
        $result = collect();
        $needsLookup = collect();

        foreach ($spells as $spell) {
            if ($priorityExternalIds->contains($spell->spell_id)) {
                $result[$spell->id] = true;
            } elseif ($spell->display_name !== '') {
                $needsLookup->push($spell);
            } else {
                $result[$spell->id] = false;
            }
        }

        foreach ($needsLookup->groupBy('patch_id') as $patchId => $group) {
            $baseNames = $group->pluck('display_name')->unique()->values();

            $candidates = Spell::where('patch_id', $patchId)
                ->where(function ($q) use ($baseNames) {
                    foreach ($baseNames as $name) {
                        $q->orWhere('name', $name)->orWhere('name', 'LIKE', $name.' (desc=%');
                    }
                })
                ->get(['id', 'spell_id', 'name']);

            $byBaseName = $candidates->groupBy(fn (Spell $s) => $s->display_name);

            foreach ($group as $spell) {
                $siblingSpellIds = ($byBaseName[$spell->display_name] ?? collect())
                    ->reject(fn (Spell $s) => $s->id === $spell->id)
                    ->pluck('spell_id');

                $result[$spell->id] = $siblingSpellIds->intersect($priorityExternalIds)->isNotEmpty();
            }
        }

        return $result;
    }

    /**
     * Merges arena-log-derived per-spell_id aggregate rows (casts/damage) by their resolved
     * canonical name — closes the "one ability, several spell_id sub-effect records"
     * fragmentation found 2026-08-14 building wow:key-offensive-abilities (Eviscerate split
     * across 4 rows, Shadowstrike across 2, etc. — the same class of duplicate-copy problem
     * this project has hit repeatedly elsewhere, just showing up in damage data for the first
     * time). Summing casts/damage across same-named siblings is correct here (unlike a plain
     * "pick one" dedupe) — the real total damage from "Eviscerate" genuinely is the sum of
     * whatever internal sub-effect records fired. Only the DISPLAYED spell (for cooldown/
     * charges/etc.) needs picking, via pickCanonicalSpell().
     *
     * @param  array<int, array{spell: Spell, casts: int, damage: int}>  $rowsBySpellId
     * @return array<int, array{name: string, spell: Spell, casts: int, damage: int}>
     */
    public function mergeByCanonicalName(array $rowsBySpellId): array
    {
        $byName = [];

        foreach ($rowsBySpellId as $row) {
            $name = $row['spell']->name;

            if (!isset($byName[$name])) {
                $byName[$name] = ['name' => $name, 'casts' => 0, 'damage' => 0, 'candidates' => []];
            }

            $byName[$name]['casts'] += $row['casts'];
            $byName[$name]['damage'] += $row['damage'];
            $byName[$name]['candidates'][] = $row['spell'];
        }

        foreach ($byName as $name => &$group) {
            // The observed-in-logs spell_ids aren't always the full picture: a name's REAL
            // canonical record (the one carrying cooldown/charges) can be a spell_id that
            // never itself appears in a SPELL_CAST_SUCCESS/SPELL_DAMAGE line — confirmed
            // 2026-08-14 on Shadow Blades, whose real player-facing copy (121471, 90s CD)
            // never showed up as an observed cast in the scanned matches at all; only its
            // damage-tagging sibling (279043, no CD data) did, so pickCanonicalSpell() only
            // ever saw the wrong one. Pull in every same-named DB row for this patch as
            // additional candidates before picking — they contribute zero casts/damage (never
            // actually observed) but are eligible to win the DISPLAY pick if they carry real
            // cooldown/charges data the observed rows lack.
            $patchId = $group['candidates'][0]->patch_id;
            $dbSiblings = Spell::where('patch_id', $patchId)->where('name', $name)->where('not_in_spellbook', false)->get();

            foreach ($dbSiblings as $sibling) {
                if (!collect($group['candidates'])->contains('id', $sibling->id)) {
                    $group['candidates'][] = $sibling;
                }
            }

            $group['spell'] = $this->pickCanonicalSpell($group['candidates']);
            unset($group['candidates']);
        }

        return array_values($byName);
    }

    /**
     * Picks which same-named spell_id "wins" for display purposes (cooldown/charges/etc.) —
     * same disambiguation heuristic already proven resolving the 19 duplicate-name
     * baseline-spec-overrides.txt conflicts earlier the same day: prefer a copy with real
     * cooldown/charges data over one with none, then prefer whichever has more
     * spell_relationships activity (source or target) as a "this is the real, live-content
     * copy" signal.
     *
     * @param  array<int, Spell>  $candidates
     */
    private function pickCanonicalSpell(array $candidates): Spell
    {
        foreach ($candidates as $c) {
            if ($c->cooldown_seconds !== null || $c->charges !== null) {
                return $c;
            }
        }

        usort($candidates, function ($a, $b) {
            $countFor = fn (Spell $s) => SpellRelationship::where('source_spell_id', $s->id)->orWhere('target_spell_id', $s->id)->count();

            return $countFor($b) <=> $countFor($a);
        });

        return $candidates[0];
    }

    /**
     * Finds the last real-player death in a match's raw log and returns every ability cast by
     * the winning team's real players in the $windowSeconds before it — the "how did they
     * actually close this out" sequence. Built 2026-08-14 after confirming directly (not
     * assumed) that these logs genuinely stop shortly after the deciding kill: checked a real
     * match end-to-end and found the last two PARTY_KILL/UNIT_DIED events sitting right before
     * ARENA_MATCH_END, not buried mid-file — the log's own tail is already a clean,
     * un-detected "kill sequence" boundary, no fuzzy windowing algorithm needed to find it.
     *
     * Real players only (GUID prefix "Player-") — a killed pet/totem is not a match-ending
     * event and is skipped when searching for the LAST real-player death.
     *
     * @return array{killedPlayer: string, killTime: float, players: array<int, array{guid: string, name: string, spec: int, casts: array<int, array{time: float, spellId: int}>}>}|null
     */
    public function findPreKillWindow(string $matchId, int $windowSeconds = 20): ?array
    {
        $metaPath = $this->metadataPath($matchId);
        $rawPath = $this->rawLogPath($matchId);

        if (!File::exists($metaPath) || !File::exists($rawPath)) {
            return null;
        }

        $meta = json_decode(File::get($metaPath), true);
        $raw = gzdecode(File::get($rawPath));

        preg_match_all('/^([\d\/: .-]+)\s+(?:PARTY_KILL|UNIT_DIED),[^,]*,[^,]*,[^,]*,[^,]*,(Player-[^,]+),/m', $raw, $deaths, PREG_SET_ORDER);

        if ($deaths === []) {
            return null;
        }

        $last = end($deaths);
        $killTime = $this->parseLogTimestamp($last[1]);
        $killedGuid = $last[2];

        $killedUnit = collect($meta['units'] ?? [])->firstWhere('id', $killedGuid);
        $losingReaction = $killedUnit['reaction'] ?? null;

        $winningPlayers = collect($meta['units'] ?? [])
            ->filter(fn ($u) => str_starts_with($u['id'], 'Player-') && $u['id'] !== $killedGuid && ($losingReaction === null || $u['reaction'] !== $losingReaction));

        $windowStart = $killTime - $windowSeconds;
        $players = [];

        foreach ($winningPlayers as $unit) {
            $g = preg_quote($unit['id'], '/');
            // destName (the 5th field after sourceGUID) is normally a quoted string, but WoW's
            // combat log writes the bare, UNQUOTED word `nil` here for any self-cast/ground-
            // targeted ability with no real unit target (Psychic Scream, Freezing Trap, etc.) —
            // confirmed 2026-08-15 against real captured lines. The old `"[^"]*"` requirement
            // silently failed to match ~23-24% of all casts in a typical match as a result; the
            // `(?:"[^"]*"|nil)` alternation accepts both shapes.
            preg_match_all('/^([\d\/: .-]+)\s+SPELL_CAST_SUCCESS,'.$g.',"[^"]*",[^,]*,[^,]*,[^,]*,(?:"[^"]*"|nil),[^,]*,[^,]*,(\d+),"([^"]*)"/m', $raw, $casts, PREG_SET_ORDER);

            $sequence = [];
            foreach ($casts as $c) {
                $t = $this->parseLogTimestamp($c[1]);
                if ($t >= $windowStart && $t <= $killTime) {
                    $sequence[] = ['time' => round($t - $windowStart, 2), 'spellId' => (int) $c[2], 'name' => $c[3]];
                }
            }

            if ($sequence !== []) {
                $players[] = ['guid' => $unit['id'], 'name' => $unit['name'], 'spec' => (int) $unit['spec'], 'casts' => $sequence];
            }
        }

        $losingComp = collect($meta['units'] ?? [])
            ->filter(fn ($u) => str_starts_with($u['id'], 'Player-') && $u['reaction'] === $losingReaction && $u['spec'] !== '0')
            ->pluck('spec')->map(fn ($s) => (int) $s)->values()->all();

        $winningComp = $winningPlayers->filter(fn ($u) => $u['spec'] !== '0')
            ->pluck('spec')->map(fn ($s) => (int) $s)->values()->all();

        return [
            'killedPlayer' => $killedUnit['name'] ?? $killedGuid,
            'killedSpec' => isset($killedUnit['spec']) ? (int) $killedUnit['spec'] : null,
            'killTime' => $killTime,
            'winningComp' => $winningComp,
            'losingComp' => $losingComp,
            'players' => $players,
        ];
    }

    /**
     * Extends findPreKillWindow() with buff/debuff evidence, added 2026-08-27 for
     * wow:record-important-mechanics. Same kill window, same winning players (calls
     * findPreKillWindow() as a read-only dependency rather than duplicating its death/window
     * logic), but additionally captures every real SPELL_AURA_APPLIED event in that window,
     * split into two kinds: self-buffs (source=dest=player, BUFF) and debuffs the player
     * applied directly to the actual kill target (source=player, dest=killedGuid, DEBUFF).
     *
     * This is the same three-signal methodology already verified in wow-arena-archive's
     * compare-player-windows.php (activeBuffsAtWindowStart/activeBuffsOnTarget/
     * activeDebuffsAppliedByAttacker — see that file's docblocks for the real Colossus Smash/
     * Ancient Arts worked examples this is built on), but adapted from "what was already active
     * at one instant" (a single-window snapshot) to "did this get applied at all during the
     * window" (a yes/no per match) — the right shape for tallying frequency across MANY
     * independent kill windows the way wow:common-prekill-spells already does for casts,
     * ranking by distinct-match count rather than one window's point-in-time state.
     *
     * Deliberately does NOT fold into findPreKillWindow()'s own return shape or
     * recordKillSequence()'s persisted kill-sequences.jsonl format — other code already depends
     * on those exact shapes, and this is a distinct, separately-consumed signal.
     *
     * @return array{killedPlayer: string, killedSpec: ?int, players: array<int, array{guid: string, name: string, spec: int, selfBuffs: array<int, array{t: float, spellId: int, name: string}>, targetDebuffs: array<int, array{t: float, spellId: int, name: string}>}>}|null
     */
    public function findMechanicsInWindow(string $matchId, int $windowSeconds = 20): ?array
    {
        $base = $this->findPreKillWindow($matchId, $windowSeconds);

        if ($base === null || $base['players'] === []) {
            return null;
        }

        $rawPath = $this->rawLogPath($matchId);

        if (!File::exists($rawPath)) {
            return null;
        }

        $raw = gzdecode(File::get($rawPath));
        $killTime = $base['killTime'];
        $windowStart = $killTime - $windowSeconds;

        // Recover the killed guid from the raw log's own death line — findPreKillWindow() only
        // exposes the resolved name/spec, not the raw guid, so this repeats its exact "last
        // death in the log wins" regex/selection to stay consistent with it.
        preg_match_all('/^([\d\/: .-]+)\s+(?:PARTY_KILL|UNIT_DIED),[^,]*,[^,]*,[^,]*,[^,]*,(Player-[^,]+),/m', $raw, $deaths, PREG_SET_ORDER);
        $killedGuid = $deaths !== [] ? end($deaths)[2] : null;

        $players = [];

        foreach ($base['players'] as $player) {
            $g = preg_quote($player['guid'], '/');
            $selfBuffs = [];
            $targetDebuffs = [];

            preg_match_all('/^([\d\/: .-]+)\s+SPELL_AURA_APPLIED,'.$g.',"[^"]*",[^,]*,[^,]*,(Player-[^,]+),"[^"]*",[^,]*,[^,]*,(\d+),"([^"]*)",[^,]*,(BUFF|DEBUFF)/m', $raw, $auras, PREG_SET_ORDER);

            foreach ($auras as $a) {
                $t = $this->parseLogTimestamp($a[1]);

                if ($t < $windowStart || $t > $killTime) {
                    continue;
                }

                $destGuid = $a[2];
                $entry = ['t' => round($t - $windowStart, 2), 'spellId' => (int) $a[3], 'name' => $a[4]];

                if ($a[5] === 'BUFF' && $destGuid === $player['guid']) {
                    $selfBuffs[] = $entry;
                } elseif ($a[5] === 'DEBUFF' && $killedGuid !== null && $destGuid === $killedGuid) {
                    $targetDebuffs[] = $entry;
                }
            }

            $players[] = [
                'guid' => $player['guid'],
                'name' => $player['name'],
                'spec' => $player['spec'],
                'selfBuffs' => $selfBuffs,
                'targetDebuffs' => $targetDebuffs,
            ];
        }

        return [
            'killedPlayer' => $base['killedPlayer'],
            'killedSpec' => $base['killedSpec'],
            'players' => $players,
        ];
    }

    /**
     * Persists findPreKillWindow()'s result for a match into
     * {archive_path}/kill-sequences/{classSlug}/{specSlug}.jsonl — one JSON line per
     * (match, winning real player), accumulating across every match ever processed this way,
     * same append-and-grow spirit as mergeSpellUsage()'s spell-usage files.
     *
     * JSON-lines rather than the flat `spell_id | name` shape spell-usage.txt uses — this data
     * is genuinely multi-dimensional (winning comp, losing comp, who died, the sequence itself,
     * per direct user request 2026-08-14: "there is actually more usable info in this" than a
     * bare sequence alone) and flat pipe-delimited text can't hold that cleanly. Each record:
     *   {matchId, playerSpec, winningComp: [specId,...], losingComp: [specId,...],
     *    killedSpec, sequence: [{t, spellId, name}, ...]}
     *
     * Idempotent per (matchId, playerSpec) — re-running against an already-recorded match/player
     * pair is a no-op, so this is safe to run repeatedly (e.g. after pulling new matches) without
     * producing duplicate lines.
     *
     * Writes to config('arena_logs.archive_path'), NOT this project's own
     * data/arena-logs/kill-sequences/ — see config/arena_logs.php's docblock (2026-08-20).
     * WowComps's Kill Sequence tab reads this project's own tree directly (its own hardcoded
     * path, not this method) and only picks up new data once manually promoted.
     *
     * @return array{recorded: int, alreadyPresent: int}
     */
    public function recordKillSequence(string $matchId, int $windowSeconds = 20): array
    {
        $result = $this->findPreKillWindow($matchId, $windowSeconds);

        if ($result === null || $result['players'] === []) {
            return ['recorded' => 0, 'alreadyPresent' => 0];
        }

        $patch = \App\Models\Patch::where('is_current', true)->first();
        $recorded = 0;
        $alreadyPresent = 0;

        foreach ($result['players'] as $player) {
            $spec = Specialization::where('external_spec_id', $player['spec'])->first();
            if (!$spec) {
                continue;
            }
            $class = \App\Models\GameClass::find($spec->class_id);

            $dir = config('arena_logs.archive_path')."/kill-sequences/{$class->slug}";
            File::ensureDirectoryExists($dir);
            $path = "{$dir}/{$spec->slug}.jsonl";

            // Keyed by (matchId, playerGuid), not (matchId, playerSpec) — a same-spec-mirror
            // comp (two players of the identical spec on the winning side) would otherwise
            // silently drop the second player's real, distinct sequence as "already present."
            // Not observed in the 79 matches on file as of 2026-08-14 (confirmed directly, not
            // assumed), but the dedup key needs to be actually-unique regardless of whether
            // it's been hit yet.
            $existingKeys = [];
            if (File::exists($path)) {
                foreach (File::lines($path) as $line) {
                    $decoded = json_decode($line, true);
                    if ($decoded) {
                        $existingKeys[$decoded['matchId'].'|'.($decoded['playerGuid'] ?? $decoded['playerSpec'])] = true;
                    }
                }
            }

            $key = $matchId.'|'.$player['guid'];
            if (isset($existingKeys[$key])) {
                $alreadyPresent++;

                continue;
            }

            // Hybrid name resolution, confirmed necessary 2026-08-14 (not just theorized): prefer
            // our own `spells` row's canonical English name when the spell_id is known; fall back
            // to the raw combat log's own embedded spellName field only when it isn't. A DB-only
            // lookup was the original cause of "spell_id N" placeholders for real casts not yet in
            // our data (trinkets, racials, Shadowstep before it was added). But a raw-log-only
            // approach (tried first, briefly) turned out to have its own real bug: the log's own
            // spellName field is written in whatever locale the recording client was running —
            // confirmed directly, 1,025 of 2,925 cast entries (29 of the matches on file) carry
            // Chinese names ("积雪"/"暗影步" instead of "Snowdrift"/"Shadowstep") rather than
            // English. The DB lookup is what gives a canonical, locale-independent name; the raw
            // log is what covers the spells the DB doesn't have yet. Neither alone is sufficient.
            $sequence = [];
            foreach ($player['casts'] as $cast) {
                $spellRow = $patch ? Spell::where('patch_id', $patch->id)->where('spell_id', $cast['spellId'])->first() : null;
                $name = $spellRow?->name ?? $cast['name'] ?? "spell_id {$cast['spellId']}";
                $sequence[] = ['t' => $cast['time'], 'spellId' => $cast['spellId'], 'name' => $name];
            }

            $record = [
                'matchId' => $matchId,
                'playerGuid' => $player['guid'],
                'playerSpec' => $player['spec'],
                'winningComp' => $result['winningComp'],
                'losingComp' => $result['losingComp'],
                'killedSpec' => $result['killedSpec'],
                'sequence' => $sequence,
            ];

            File::append($path, json_encode($record, JSON_UNESCAPED_SLASHES)."\n");
            $recorded++;
        }

        return ['recorded' => $recorded, 'alreadyPresent' => $alreadyPresent];
    }

    private function parseLogTimestamp(string $raw): float
    {
        if (!preg_match('/(\d{1,2}):(\d{2}):(\d{2})\.(\d+)/', trim($raw), $m)) {
            return 0.0;
        }

        return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3] + ((float) ('0.'.$m[4]));
    }

    /**
     * Healer specs — hardcoded, well-known stable game knowledge, not derived from any DB
     * column (none exists yet). Used to tag the roster so a causal-analysis report can label
     * "CC landed on the enemy healer" without the reader having to already know every spec's
     * role.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const HEALER_SPEC_SLUGS = [
        ['druid', 'restoration'], ['shaman', 'restoration'], ['priest', 'holy'], ['priest', 'discipline'],
        ['paladin', 'holy'], ['monk', 'mistweaver'], ['evoker', 'preservation'],
    ];

    /**
     * Name substrings for defensive cooldowns, offensive cooldowns, and PvP trinkets —
     * deliberately a curated keyword list, not a DB-driven tag. `ModuleSpellReferenceService::
     * categorize()` was considered instead but explicitly avoided for this: this project's own
     * standing feedback ("offensive cooldown isn't an actual tag in our system yet... offensive
     * and defensive tags miss a lot") means that classifier isn't reliable enough yet for what
     * this report needs — a wrong or missing tag here just means an entry doesn't get
     * highlighted (the full, untagged cast timeline is still shown), so the failure mode is
     * silent omission, not a wrong claim. Expand this list opportunistically as new matches get
     * analyzed; it will never be exhaustive across all 13 classes.
     */
    private const WATCHED_DEFENSIVES = [
        'Cloak of Shadows', 'Evasion', 'Feint', 'Crimson Vial', 'Guardian Spirit', 'Desperate Prayer',
        'Astral Shift', 'Earth Elemental', 'Stone Bulwark Totem', 'Fade', 'Power Word: Shield',
        'Pain Suppression', 'Ice Block', 'Divine Shield', 'Barkskin', 'Survival Instincts',
        'Frenzied Regeneration',
    ];

    private const WATCHED_OFFENSIVES = [
        'Bestial Wrath', 'Feral Frenzy', 'Ascendance', 'Incarnation', 'Berserking', 'Avenging Wrath',
        'Combustion', 'Icy Veins', 'Arcane Surge', 'Trueshot', 'Metamorphosis', 'Recklessness',
        'Avatar', 'Chaos Blades', 'Dragonrage', 'Coordinated Assault', 'Adrenaline Rush', 'Shadow Blades',
    ];

    private const WATCHED_TRINKETS = ["Gladiator's Badge", "Gladiator's Medallion", "Gladiator's Emblem"];

    /**
     * Reconstructs the full causal picture around one match's deciding kill — every CC landing
     * (real source AND real target, via SPELL_AURA_APPLIED/REMOVED, which carry destination
     * info that SPELL_CAST_SUCCESS never does), every watched defensive/offensive/trinket cast
     * from EITHER team, the killed player's HP curve, and the top damage sources against them
     * in the closing seconds.
     *
     * Built 2026-08-15, consolidating a chain of one-off scratchpad investigations from the
     * same session into a single reusable tool — see wow:analyze-kill's docblock for the full
     * story of what prompted this (a real Jungle-comp match where "who was locking the enemy
     * healer, and when did their own defensives actually cover the damage that killed them"
     * turned out to be answerable, but only by hand-writing five separate throwaway scripts).
     *
     * Deliberately does NOT attempt to explain WHY a player pressed something — that's
     * interpretation, not extraction, and needs a human (or a human+AI conversation) reading
     * the output with real game knowledge, the same way the original investigation worked. This
     * method's job is only to put the real facts in front of that conversation without them
     * having to be re-derived from raw log grepping every time.
     *
     * @return array{
     *   matchId: string, killTime: float, killedGuid: string, killedName: string,
     *   killedSpec: ?string, durationSeconds: int,
     *   roster: array<string, array{name: string, spec: string, reaction: mixed, isHealer: bool}>,
     *   timeline: array<int, array{t: float, text: string}>,
     *   hpCurve: array<int, array{t: float, currentHp: float, maxHp: float, pct: float}>,
     *   damageTaken: array<int, array{t: float, source: string, amount: int, ability: string}>
     * }|null
     */
    public function analyzeKillCausally(string $matchId, int $windowSeconds = 60): ?array
    {
        $metaPath = $this->metadataPath($matchId);
        $rawPath = $this->rawLogPath($matchId);

        if (!File::exists($metaPath) || !File::exists($rawPath)) {
            return null;
        }

        $meta = json_decode(File::get($metaPath), true);
        $raw = gzdecode(File::get($rawPath));

        if (!preg_match_all('/^([\d\/: .-]+)\s+(?:PARTY_KILL|UNIT_DIED),[^,]*,[^,]*,[^,]*,[^,]*,(Player-[^,]+),"([^"]*)"/m', $raw, $deaths, PREG_SET_ORDER)) {
            return null;
        }
        if ($deaths === []) {
            return null;
        }
        $last = end($deaths);
        $killTime = $this->parseLogTimestamp($last[1]);
        $killedGuid = $last[2];
        $killedName = $last[3];

        $isHealer = function (int $extSpecId) {
            $spec = Specialization::where('external_spec_id', $extSpecId)->first();
            if (!$spec || !$spec->gameClass) {
                return false;
            }
            foreach (self::HEALER_SPEC_SLUGS as [$c, $s]) {
                if ($spec->gameClass->slug === $c && $spec->slug === $s) {
                    return true;
                }
            }

            return false;
        };

        $roster = [];
        $killedSpec = null;
        foreach ($meta['units'] ?? [] as $u) {
            if (!str_starts_with($u['id'], 'Player-') || !isset($u['spec']) || (int) $u['spec'] === 0) {
                continue;
            }
            $spec = Specialization::with('gameClass')->where('external_spec_id', (int) $u['spec'])->first();
            $specLabel = $spec ? "{$spec->name} {$spec->gameClass?->name}" : "spec {$u['spec']}";
            $roster[$u['id']] = ['name' => $u['name'], 'spec' => $specLabel, 'reaction' => $u['reaction'] ?? null, 'isHealer' => $isHealer((int) $u['spec'])];
            if ($u['id'] === $killedGuid) {
                $killedSpec = $specLabel;
            }
        }

        $patch = \App\Models\Patch::where('is_current', true)->first();
        $ccByCategory = $patch
            ? Spell::where('patch_id', $patch->id)->whereNotNull('dr_category')->pluck('dr_category', 'spell_id')->all()
            : [];
        $timeline = [];

        foreach (['SPELL_AURA_APPLIED' => 'landed on', 'SPELL_AURA_REMOVED' => 'fell off'] as $eventType => $verb) {
            preg_match_all('/^([\d\/: .-]+)\s+'.$eventType.',(Player-[^,]+),"[^"]*",[^,]*,[^,]*,(Player-[^,]+),"[^"]*",[^,]*,[^,]*,(\d+),"([^"]*)"/m', $raw, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $spellId = (int) $m[4];
                $name = $m[5];
                $isCC = isset($ccByCategory[$spellId]);
                $isWatched = $this->matchesAny($name, [...self::WATCHED_DEFENSIVES, ...self::WATCHED_OFFENSIVES, ...self::WATCHED_TRINKETS]);
                if (!$isCC && !$isWatched) {
                    continue;
                }

                $t = $this->parseLogTimestamp($m[1]);
                $secBefore = $killTime - $t;
                if ($secBefore < 0 || $secBefore > $windowSeconds) {
                    continue;
                }

                $src = $roster[$m[2]]['name'] ?? $m[2];
                $dst = $roster[$m[3]]['name'] ?? $m[3];
                $tag = $isCC ? " ({$ccByCategory[$spellId]})" : '';
                $timeline[] = ['t' => $secBefore, 'text' => "{$src} -> {$name}{$tag} {$verb} {$dst}"];
            }
        }

        foreach ($roster as $guid => $info) {
            $g = preg_quote($guid, '/');
            preg_match_all('/^([\d\/: .-]+)\s+SPELL_CAST_SUCCESS,'.$g.',"[^"]*",[^,]*,[^,]*,[^,]*,(?:"[^"]*"|nil),[^,]*,[^,]*,(\d+),"([^"]*)"/m', $raw, $casts, PREG_SET_ORDER);
            foreach ($casts as $c) {
                $name = $c[3];
                if (!$this->matchesAny($name, [...self::WATCHED_DEFENSIVES, ...self::WATCHED_OFFENSIVES, ...self::WATCHED_TRINKETS])) {
                    continue;
                }
                $t = $this->parseLogTimestamp($c[1]);
                $secBefore = $killTime - $t;
                if ($secBefore < 0 || $secBefore > $windowSeconds) {
                    continue;
                }
                $timeline[] = ['t' => $secBefore + 0.0001, 'text' => "CAST: {$info['name']} ({$info['spec']}) casts {$name}"];
            }
        }

        usort($timeline, fn ($a, $b) => $b['t'] <=> $a['t']);

        // HP curve + damage breakdown for the killed player specifically — SPELL_DAMAGE/
        // SPELL_PERIODIC_DAMAGE's currentHP field reflects post-hit health (confirmed 2026-08-15
        // against a real killing blow's overkill math), SWING_DAMAGE has a 3-field-shorter
        // prefix so its currentHP sits 3 tail positions earlier.
        $kg = preg_quote($killedGuid, '/');
        preg_match_all('/^([\d\/: .-]+)\s+(SPELL_DAMAGE|SPELL_PERIODIC_DAMAGE|SWING_DAMAGE),(Player-[^,]+),"[^"]*",[^,]*,[^,]*,'.$kg.',"[^"]*",[^,]*,[^,]*,(.*)$/m', $raw, $dmgMatches, PREG_SET_ORDER);

        $hpCurve = [];
        $damageTaken = [];
        foreach ($dmgMatches as $d) {
            $t = $this->parseLogTimestamp($d[1]);
            $secBefore = $killTime - $t;
            if ($secBefore < 0 || $secBefore > $windowSeconds) {
                continue;
            }
            $type = $d[2];
            $sourceName = $roster[$d[3]]['name'] ?? $d[3];

            if ($type === 'SWING_DAMAGE') {
                $tail = explode(',', $d[4]);
                $hpIdx = 11;
                $amountIdx = 19;
                $ability = '(melee)';
            } else {
                if (!preg_match('/^\d+,"([^"]*)",[^,]*,(.*)$/', $d[4], $sub)) {
                    continue;
                }
                $ability = $sub[1];
                $tail = explode(',', $sub[2]);
                $hpIdx = 14;
                $amountIdx = 19;
            }

            $currentHp = (float) ($tail[$hpIdx] ?? 0);
            $maxHp = (float) ($tail[$hpIdx + 1] ?? 0);
            $amount = (int) ($tail[$amountIdx] ?? 0);

            if ($maxHp > 0) {
                $hpCurve[] = ['t' => $secBefore, 'currentHp' => $currentHp, 'maxHp' => $maxHp, 'pct' => round($currentHp / $maxHp * 100, 1)];
            }
            if ($amount > 0) {
                $damageTaken[] = ['t' => $secBefore, 'source' => $sourceName, 'amount' => $amount, 'ability' => $ability];
            }
        }

        usort($hpCurve, fn ($a, $b) => $b['t'] <=> $a['t']);
        usort($damageTaken, fn ($a, $b) => $b['t'] <=> $a['t']);

        return [
            'matchId' => $matchId,
            'killTime' => $killTime,
            'killedGuid' => $killedGuid,
            'killedName' => $killedName,
            'killedSpec' => $killedSpec,
            'durationSeconds' => (int) ($meta['durationInSeconds'] ?? 0),
            'roster' => $roster,
            'timeline' => $timeline,
            'hpCurve' => $hpCurve,
            'damageTaken' => $damageTaken,
        ];
    }

    /**
     * Whether (classSlug, specSlug) is one of WoW's 7 healer specs — see HEALER_SPEC_SLUGS's own
     * docblock for why this is hardcoded rather than DB-driven. Exposed publicly 2026-08-22 for
     * wow:find-cc-chains, which needs the exact same classification analyzeKillCausally() already
     * uses privately above — avoids a third copy of this list existing in the codebase.
     * (WowComps::SPEC_ROLES, built the same day for a different page's role-restricted picker, is
     * a separate, more granular healer/dps/tank map with its own consumer — not merged with this
     * one, since neither page needs what the other tracks.)
     */
    public function isHealerSpec(string $classSlug, string $specSlug): bool
    {
        foreach (self::HEALER_SPEC_SLUGS as [$c, $s]) {
            if ($c === $classSlug && $s === $specSlug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any real player in $matchId's roster is playing $specializationId — a lightweight
     * roster-composition check, reusing the same metadata JSON + external_spec_id lookup pattern
     * as analyzeKillCausally()'s own roster build, but without needing the raw log or a kill
     * event at all (metadata alone carries every unit's spec). Built 2026-08-17 for
     * FindCcDuration's outlier-explanation check — was this match's unusually long CC-duration
     * instance explained by a Preservation Evoker's Oppressing Roar being in play — but
     * deliberately generic (takes any specialization id), not Oppressing-Roar-specific, since
     * "was spec X present in this match" is a reusable question.
     */
    public function matchRosterHasSpec(string $matchId, int $specializationId): bool
    {
        $metaPath = $this->metadataPath($matchId);

        if (!File::exists($metaPath)) {
            return false;
        }

        $spec = Specialization::find($specializationId);
        if (!$spec) {
            return false;
        }

        $meta = json_decode(File::get($metaPath), true);

        foreach ($meta['units'] ?? [] as $u) {
            if (str_starts_with($u['id'] ?? '', 'Player-') && (int) ($u['spec'] ?? 0) === $spec->external_spec_id) {
                return true;
            }
        }

        return false;
    }

    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function manifestPath(string $file = 'comp-index.json'): string
    {
        return base_path("data/arena-logs/{$file}");
    }

    private function loadManifest(string $file = 'comp-index.json'): array
    {
        $path = $this->manifestPath($file);

        return File::exists($path) ? json_decode(File::get($path), true) : [];
    }

    private function saveManifest(string $file, array $manifest): void
    {
        ksort($manifest);
        File::put(
            $this->manifestPath($file),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }
}
