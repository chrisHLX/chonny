<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\ArenaReviewIngestService;
use App\Jobs\BuildCoachPages;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $body = implode('', array_map(fn ($n) => File::get("{$dir}/{$n}"), range(1, $parts)));
            File::deleteDirectory($dir);
        }

        self::roomToMeasure();
        $raw = @gzdecode($body);
        if ($raw === false || $raw === '') {
            return response()->json(['status' => 'rejected', 'reason' => 'Not readable gzip.'], 422);
        }

        $result = $ingest->ingestRound($user, $raw);

        // The archive's own lobby, as wow:sync gives it, so a shuffle groups exactly as on the PC.
        $lobby = (string) $request->header('X-Lobby', '');
        if (($result['status'] ?? null) === 'stored' && preg_match('/^[a-f0-9]{32}$/', $lobby)) {
            ArenaRound::where('user_id', $user->id)->where('match_id', $result['matchId'])->update(['lobby_id' => $lobby]);
        }

        return response()->json(array_diff_key($result, ['review' => true]));
    }

    /**
     * Room for one round's measurement. PHP-FPM gives a request 128 MB and 30s; the largest archived
     * round (89,225 lines) peaked at 170 MB and took 6s on a dev PC (2026-10-05), a typical one 4 MB.
     */
    public static function roomToMeasure(): void
    {
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

        return response()->json(['version' => \App\Http\Services\RoundAnalysisService::VERSION]);
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
        BuildCoachPages::dispatch($user->id);

        return response()->json(['status' => 'ok', 'assembled' => $assembled]);
    }
}
