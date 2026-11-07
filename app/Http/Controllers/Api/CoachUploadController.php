<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\ArenaReviewIngestService;
use App\Http\Services\RoundAnalysisService;
use App\Http\Services\TalentSelectionService;
use App\Jobs\BuildCoachPages;
use App\Jobs\FetchPlayerExperience;
use App\Models\ArenaRound;
use App\Models\Patch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The desktop app putting a player's games on their account, so /wow/coach shows the same pages the
 * app does (`wow:push-rounds` on the player's machine sends them).
 *
 * The same path as everything else: each round's slice of the combat log, gzipped, goes through
 * ArenaReviewIngestService::ingestRound(), which `wow:sync` itself uses, so a game is measured on the
 * server exactly as on the player's PC. The server never trusts a measurement from the client.
 * Re-sending a round measures it again (it is keyed by match id), which is how games already here
 * catch up when a measure changes: the app sends them again.
 *
 * IN PIECES WHEN LARGE. Nginx takes 1 MB a request on this site, and 15 of 751 archived rounds were
 * bigger than that gzipped (2 MB the largest) on 2026-10-05. A round over CHUNK bytes arrives as
 * numbered parts and is put back together here.
 *
 * Authenticated by the player's key from /wow/coach (`Authorization: Bearer mc_...`), and rate
 * limited per player because the box is one vCPU and a round is about two seconds to measure.
 */
class CoachUploadController extends Controller
{
    /** Rounds (or parts) a minute per player. */
    private const PER_MINUTE = 40;

    /**
     * At most this many rounds measured at once, across every player (2026-10-12). The box has one
     * core and twelve PHP workers; a round costs 0.5-2s of CPU (measured on it that day), so a rush of
     * new players (a Reddit post) could hold every worker and stall the site. A round over the limit
     * is told to come back in RETRY_SECONDS; the app already waits and retries on a 429.
     */
    private const MEASURING_AT_ONCE = 3;

    private const RETRY_SECONDS = 10;

    /** More parts than this is not a round. */
    private const MAX_PARTS = 40;

    public function round(Request $request, ArenaReviewIngestService $ingest): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised', 'reason' => 'Unknown key: make a new one on /wow/coach.'], 401);
        }

        $key = 'coach-upload:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return response()->json(['status' => 'rate-limited', 'retryAfter' => RateLimiter::availableIn($key)], 429);
        }
        RateLimiter::hit($key, 60);

        $match = (string) $request->header('X-Match', '');
        $part = (int) $request->header('X-Part', 1);
        $parts = (int) $request->header('X-Parts', 1);
        if (! preg_match('/^[a-f0-9]{32}$/', $match) || $parts < 1 || $parts > self::MAX_PARTS || $part < 1 || $part > $parts) {
            return response()->json(['status' => 'rejected', 'reason' => 'Missing or bad X-Match / X-Part / X-Parts.'], 422);
        }

        $body = $request->getContent();
        if ($parts > 1) {
            $dir = storage_path("app/coach-parts/{$user->id}/{$match}");
            File::ensureDirectoryExists($dir);
            File::put("{$dir}/{$part}", $body);
            if (count(File::files($dir)) < $parts) {
                return response()->json(['status' => 'part', 'part' => $part, 'parts' => $parts]);
            }
        }

        // A free measuring slot, or come back shortly. The part that completed a multi-part round is
        // dropped when busy, so the retry of that same part completes it again; the others stay.
        $slot = null;
        for ($i = 0; $i < self::MEASURING_AT_ONCE && $slot === null; $i++) {
            $lock = Cache::lock("coach-measuring:{$i}", 120);
            $slot = $lock->get() ? $lock : null;
        }
        if ($slot === null) {
            if ($parts > 1) {
                File::delete(storage_path("app/coach-parts/{$user->id}/{$match}/{$part}"));
            }

            return response()->json(['status' => 'busy', 'retryAfter' => self::RETRY_SECONDS], 429);
        }

        if ($parts > 1) {
            $body = implode('', array_map(fn ($n) => File::get("{$dir}/{$n}"), range(1, $parts)));
            File::deleteDirectory($dir);
        }

        try {
            self::roomToMeasure();
            $raw = @gzdecode($body);
            if ($raw === false || $raw === '') {
                return response()->json(['status' => 'rejected', 'reason' => 'Not readable gzip.'], 422);
            }

            $result = $ingest->ingestRound($user, $raw);
        } finally {
            $slot->release();
        }

        // The archive's own lobby, as wow:sync gives it, so a shuffle groups exactly as on the PC.
        $lobby = (string) $request->header('X-Lobby', '');
        if (($result['status'] ?? null) === 'stored' && preg_match('/^[a-f0-9]{32}$/', $lobby)) {
            ArenaRound::where('user_id', $user->id)->where('match_id', $result['matchId'])->update(['lobby_id' => $lobby]);
        }
        // The pages get built even if the app stops before it says "done" (BuildCoachPages::backup).
        if (($result['status'] ?? null) === 'stored') {
            BuildCoachPages::backup($user->id);
        }

        return response()->json(array_diff_key($result, ['review' => true]));
    }

    /**
     * Room for one round's measurement. PHP-FPM gives a request 128 MB and 30s; the largest archived
     * round (89,225 lines) peaked at 170 MB and took 6s on a dev PC (2026-10-05), a typical one 4 MB.
     *
     * Not under the test suite (2026-11-08): every test request shares one PHP process, so the first
     * upload test started a 120s clock for the whole run, and the suite died "Maximum execution time
     * of 120 seconds exceeded" two minutes later, wherever it had got to.
     */
    public static function roomToMeasure(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);
    }

    /**
     * After a batch: assemble the lobbies the batch completed, and build the player's pages in the
     * background (BuildCoachPages), once per batch rather than once per round.
     */
    /**
     * Which measure version this server stores (RoundAnalysisService::VERSION). The desktop app asks
     * before sending, and marks a game sent at the lower of its own version and this one: a game the
     * server measured with older code is sent again once the server is deployed, never lost.
     */
    public function version(Request $request): JsonResponse
    {
        abort_unless(User::forCoachToken($request->bearerToken()), 401);

        return response()->json(['version' => RoundAnalysisService::VERSION]);
    }

    /** What a round here is measured with: the deployed commit and the current WoW spell build. */
    public static function measuredWith(): array
    {
        return [
            'commit' => app(TalentSelectionService::class)->deployedCodeFingerprint(),
            'build' => Patch::where('is_current', true)->whereHas('game', fn ($q) => $q->where('slug', 'wow'))->value('build_version'),
        ];
    }

    /**
     * A round measured on an admin's own PC (2026-11-04, Chriso: "every time we have to use up server
     * resources when we don't need to"). Every change to a measure re-sends every game, and measuring
     * here is about 4s a round on one core; storing a finished payload is a database write.
     *
     * Trusted only because it is an admin's key, and only when it was measured with exactly what this
     * server would use: the same RoundAnalysisService::VERSION, the same deployed commit (X-Commit,
     * the PC's full HEAD, which starts with the short one deploy.sh writes) and the same spell build
     * (X-Build). Anything else is refused with 409, which names this server's commit and build: the
     * PC asks with an empty request first to learn them (not /api/coach/version, which public/index.php
     * answers without booting Laravel), and sends the raw log instead when they differ. The body is
     * the stored row, gzipped JSON. What ingestRound() learns from the raw text (a non-English
     * client's spell names) is not learned this way.
     */
    public function measured(Request $request): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised', 'reason' => 'Unknown key: make a new one on /wow/coach.'], 401);
        }
        if (! $user->is_admin) {
            return response()->json(['status' => 'rejected', 'reason' => 'Only an admin may send measured rounds.'], 403);
        }

        $key = 'coach-upload:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE * 5)) {
            return response()->json(['status' => 'rate-limited', 'retryAfter' => RateLimiter::availableIn($key)], 429);
        }
        RateLimiter::hit($key, 60);

        $here = self::measuredWith();
        $commit = (string) $request->header('X-Commit', '');
        $build = (string) $request->header('X-Build', '');
        if ($here['commit'] === 'dev' || $commit === '' || ! str_starts_with($commit, $here['commit']) || $build !== (string) $here['build']) {
            return response()->json(['status' => 'mismatch', 'reason' => "This server measures with commit {$here['commit']} and spell build {$here['build']}."] + $here, 409);
        }

        $row = json_decode((string) @gzdecode($request->getContent()), true);
        $payload = $row['payload'] ?? null;
        $match = (string) ($row['match_id'] ?? '');
        $lobby = (string) ($row['lobby_id'] ?? '');
        if (! preg_match('/^[a-f0-9]{32}$/', $match) || ! preg_match('/^[a-f0-9]{32}$/', $lobby) || ! is_array($payload) || ! isset($payload['metadata'], $payload['analysis'])) {
            return response()->json(['status' => 'rejected', 'reason' => 'Not a measured round.'], 422);
        }
        if (($payload['analysis']['version'] ?? null) !== RoundAnalysisService::VERSION) {
            return response()->json(['status' => 'mismatch', 'reason' => 'Measured at version '.($payload['analysis']['version'] ?? '?').', this server is at '.RoundAnalysisService::VERSION.'.'] + $here, 409);
        }

        ArenaRound::updateOrCreate(
            ['user_id' => $user->id, 'match_id' => $match],
            [
                'lobby_id' => $lobby,
                'roster_key' => (string) ($row['roster_key'] ?? ''),
                'sequence' => (int) ($row['sequence'] ?? 1),
                'bracket' => (string) ($row['bracket'] ?? 'unknown'),
                'played_at' => $row['played_at'] ?? null,
                'payload' => $payload,
            ]
        );

        $players = array_column($payload['analysis']['players'] ?? [], 'name');
        if ($players !== [] && config('services.battlenet.client_id')) {
            FetchPlayerExperience::dispatch($players, $user->id)->delay(now()->addSeconds(FetchPlayerExperience::DELAY_SECONDS));
        }
        BuildCoachPages::backup($user->id);

        return response()->json(['status' => 'stored', 'matchId' => $match, 'lobbyId' => $lobby]);
    }

    public function done(Request $request, ArenaReviewIngestService $ingest): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }

        $assembled = 0;
        foreach ($ingest->lobbiesNeedingAssembly($user) as $lobbyId) {
            $assembled += $ingest->assembleLobby($user, $lobbyId) ? 1 : 0;
        }
        BuildCoachPages::cardsFirst($user->id);
        // Every upload feeds the shared norms by default (2026-10-10); one rebuild per burst.
        \App\Jobs\RefreshPopulationNorms::dispatch()->delay(now()->addSeconds(\App\Jobs\RefreshPopulationNorms::DELAY_SECONDS));

        return response()->json(['status' => 'ok', 'assembled' => $assembled]);
    }
}
