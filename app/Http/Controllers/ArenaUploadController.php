<?php

namespace App\Http\Controllers;

use App\Http\Services\ArenaReviewIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Receives one arena round at a time from the Game Review upload control.
 *
 * A round, not a log: see ArenaReviewIngestService for why the browser does the splitting and why
 * every piece of interpretation stays on this side of the wire.
 *
 * RATE LIMITED PER USER, because the box is one vCPU. Deriving a round is a few hundred
 * milliseconds of parsing, and somebody uploading a season's backlog would otherwise hold the
 * single core against every other request on the site. The limit is generous next to a real
 * session — an evening is six rounds a lobby — and mean to a bulk replay.
 */
class ArenaUploadController extends Controller
{
    /** Rounds per minute per user. An evening of shuffle is well inside this. */
    private const ROUNDS_PER_MINUTE = 60;

    public function round(Request $request, ArenaReviewIngestService $ingest): JsonResponse
    {
        $user = $request->user();

        $key = 'arena-upload:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::ROUNDS_PER_MINUTE)) {
            return response()->json([
                'status' => 'rate-limited',
                'reason' => 'Too many rounds at once. Wait '.RateLimiter::availableIn($key).'s and carry on.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $request->validate([
            'round' => ['required', 'file', 'max:12288'],
        ]);

        $raw = file_get_contents($request->file('round')->getRealPath());

        if ($raw === false) {
            return response()->json(['status' => 'rejected', 'reason' => 'Could not read the upload.'], 422);
        }

        // The browser gzips each slice: a round is mostly repeated names and spell ids and
        // compresses about eight to one, which is what keeps a request inside the 1MB nginx
        // default this site still runs on.
        if (str_starts_with($raw, "\x1f\x8b")) {
            $inflated = @gzdecode($raw);

            if ($inflated === false) {
                return response()->json(['status' => 'rejected', 'reason' => 'Upload was not readable gzip.'], 422);
            }

            $raw = $inflated;
        }

        return response()->json($ingest->ingestRound($user, $raw));
    }

    /**
     * Finishes an upload by assembling every lobby whose rounds are now complete. Separate from
     * the per-round call so a lobby's six rounds are assembled once, not six times.
     */
    public function assemble(Request $request, ArenaReviewIngestService $ingest): JsonResponse
    {
        $user = $request->user();
        $assembled = [];

        foreach ($ingest->lobbiesNeedingAssembly($user) as $lobbyId) {
            $review = $ingest->assembleLobby($user, $lobbyId);

            if ($review) {
                $assembled[] = [
                    'lobbyId' => $review->lobby_id,
                    'rounds' => $review->rounds,
                    'record' => $review->rounds_won.'-'.$review->rounds_lost,
                    'character' => $review->character_name,
                    'linked' => $review->battlenet_character_id !== null,
                ];
            }
        }

        return response()->json(['status' => 'ok', 'assembled' => $assembled]);
    }
}
