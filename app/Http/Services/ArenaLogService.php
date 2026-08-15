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

        $rawDir = base_path('data/arena-logs/raw');
        $metaDir = base_path('data/arena-logs/metadata');
        File::ensureDirectoryExists($rawDir);
        File::ensureDirectoryExists($metaDir);

        $rawBody = $logResp->body();
        $compressed = gzencode($rawBody, 9);
        File::put("{$rawDir}/{$matchId}.log.gz", $compressed);

        $metadata = $match;
        $metadata['fetchedAt'] = now()->toIso8601String();
        $metadata['sourceUrl'] = "https://wowarenalogs.com/match?id={$matchId}";

        File::put(
            "{$metaDir}/{$matchId}.json",
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
        $metaPath = base_path("data/arena-logs/metadata/{$matchId}.json");
        $rawPath = base_path("data/arena-logs/raw/{$matchId}.log.gz");

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

            if (File::exists(base_path("data/arena-logs/metadata/{$matchId}.json"))) {
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

            if (File::exists(base_path("data/arena-logs/metadata/{$matchId}.json"))) {
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
     * plain-text file at data/arena-logs/spell-usage/{classSlug}/{specSlug}.txt — shared by
     * wow:extract-arena-spells (manual, per-match) and wow:discover-spec-spells (automated
     * search+pull+extract). See either command's docblock for the file format and the
     * "this is partial coverage, not a full spellbook" caveat.
     *
     * @param  array<int, array{spellId: int, name: string}>  $newSpells
     */
    public function mergeSpellUsage(string $classSlug, string $specSlug, string $matchId, array $newSpells): void
    {
        $dir = base_path("data/arena-logs/spell-usage/{$classSlug}");
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
        $metaPath = base_path("data/arena-logs/metadata/{$matchId}.json");
        $rawPath = base_path("data/arena-logs/raw/{$matchId}.log.gz");

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
            preg_match_all('/^([\d\/: .-]+)\s+SPELL_CAST_SUCCESS,'.$g.',"[^"]*",[^,]*,[^,]*,[^,]*,"[^"]*",[^,]*,[^,]*,(\d+),"([^"]*)"/m', $raw, $casts, PREG_SET_ORDER);

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
     * Persists findPreKillWindow()'s result for a match into
     * data/arena-logs/kill-sequences/{classSlug}/{specSlug}.jsonl — one JSON line per
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

            $dir = base_path("data/arena-logs/kill-sequences/{$class->slug}");
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
