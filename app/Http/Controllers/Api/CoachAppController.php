<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\PlayerExperienceService;
use App\Jobs\BuildCoachPages;
use App\Jobs\FetchPlayerExperience;
use App\Models\ArenaNote;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * What the desktop app does while a player plays (2026-10-25), taken over from the developer app:
 * notes and Ctrl+Shift+M marks, and the experience of the players in the game in progress. Both
 * with the player's key, like the uploads (CoachUploadController).
 */
class CoachAppController extends Controller
{
    /**
     * A note from the app. Either it names a past game (`lobby`, one of the player's own), or it has
     * only its time, written during a round, as a mark, or kept for the next game; attachNotes()
     * files those by time when the cards are built. Keyed by the app's own id, so a retry stores it
     * once and a mark written about later updates in place.
     */
    public function note(Request $request): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }
        if (RateLimiter::tooManyAttempts('coach-note:'.$user->id, 60)) {
            return response()->json(['status' => 'rate-limited', 'retryAfter' => RateLimiter::availableIn('coach-note:'.$user->id)], 429);
        }
        RateLimiter::hit('coach-note:'.$user->id, 60);

        $v = Validator::make($request->all(), [
            'id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kind' => ['required', 'in:note,mark,next'],
            'text' => ['nullable', 'string', 'max:4000'],
            'at' => ['required', 'date_format:Y-m-d H:i:s'],
            'roundStart' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'lobby' => ['nullable', 'string', 'max:191'],
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 'rejected', 'reason' => $v->errors()->first()], 422);
        }
        $data = $v->validated();
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '' && $data['kind'] !== 'mark') {
            return response()->json(['status' => 'rejected', 'reason' => 'A note needs some text.'], 422);
        }

        $lobby = $data['lobby'] ?? null;
        if ($lobby !== null && ! ArenaRound::where('user_id', $user->id)->where('lobby_id', $lobby)->exists()) {
            return response()->json(['status' => 'unknown-game'], 404);
        }

        ArenaNote::updateOrCreate(
            ['user_id' => $user->id, 'client_id' => $data['id']],
            [
                'lobby_id' => $lobby,
                'body' => $text,
                'kind' => $data['kind'],
                'noted_at' => $data['at'],
                'round_start' => $data['roundStart'] ?? null,
            ],
        );

        // Redraw now only when the note is about a game already here. One written during a game,
        // or for the next one, lands when that game's upload builds the pages anyway.
        $about = $lobby !== null || ($data['kind'] !== 'next' && ArenaRound::where('user_id', $user->id)
            ->whereBetween('played_at', [now()->parse($data['at'])->subHours(3), $data['at']])->exists());
        if ($about) {
            BuildCoachPages::dispatch($user->id, cardsOnly: true);
        }

        return response()->json(['status' => 'stored']);
    }

    /**
     * The players in the game the app is watching: their experience where it is already known, and
     * a lookup queued for the rest (FetchPlayerExperience, a second or so each), so the app asks
     * again a little later. Never waits on Blizzard inside the request.
     */
    public function players(Request $request, PlayerExperienceService $experience): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }
        if (RateLimiter::tooManyAttempts('coach-players:'.$user->id, 30)) {
            return response()->json(['status' => 'rate-limited'], 429);
        }
        RateLimiter::hit('coach-players:'.$user->id, 60);

        $names = collect((array) $request->input('players', []))
            ->filter(fn ($n) => is_string($n) && Str::substrCount($n, '-') >= 2 && strlen($n) <= 80)
            ->unique()->take(10)->values()->all();

        $known = $experience->cachedMany($names);
        $missing = array_values(array_filter($names, fn ($n) => ($known[$n] ?? null) === null));
        if ($missing !== []) {
            FetchPlayerExperience::dispatch($missing);
        }

        return response()->json(['players' => array_filter($known, fn ($x) => $x !== null), 'pending' => $missing]);
    }
}
