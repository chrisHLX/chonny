<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\YouTubeService;
use App\Models\User;
use App\Models\YoutubeVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The desktop app uploading a game to the player's YouTube channel (2026-10-26), with its usual key.
 * prepare(): a short-lived access token, and the title, description and chapters for the game.
 * uploaded(): the video id once YouTube has it, so the game's Watch page plays it. The file goes
 * from the player's PC straight to YouTube; nothing here receives it.
 */
class CoachYouTubeController extends Controller
{
    public function prepare(Request $request, YouTubeService $youtube): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }
        if (! $youtube->configured()) {
            return response()->json(['status' => 'unavailable', 'reason' => 'YouTube uploads are not set up on the website yet.'], 503);
        }
        $lobby = (string) $request->input('lobby');
        abort_unless(preg_match('/^[0-9a-f]{32}$/', $lobby), 422);

        $access = $youtube->accessToken($user);
        if (! $access) {
            return response()->json(['status' => 'not-linked', 'connect' => route('coach').'#youtube'], 409);
        }
        $details = $youtube->details($user, $lobby, $request->input('started'), array_values(array_filter((array) $request->input('rounds', []), 'is_string')));
        if (! $details) {
            return response()->json(['status' => 'no-game'], 404);
        }

        return response()->json(['status' => 'ok', 'accessToken' => $access['token'], 'expiresIn' => $access['expiresIn']] + $details);
    }

    public function uploaded(Request $request): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }
        $data = $request->validate([
            'lobby' => ['required', 'regex:/^[0-9a-f]{32}$/'],
            'videoId' => ['required', 'regex:/^[A-Za-z0-9_-]{6,20}$/'],
            'privacy' => ['required', 'in:private,unlisted,public'],
            'started' => ['nullable', 'string', 'max:32'],
            'rounds' => ['nullable', 'array', 'max:12'],
            'rounds.*' => ['string', 'max:32'],
        ]);
        YoutubeVideo::updateOrCreate(
            ['user_id' => $user->id, 'lobby_id' => $data['lobby']],
            ['video_id' => $data['videoId'], 'privacy' => $data['privacy'], 'started' => $data['started'] ?? null, 'rounds' => $data['rounds'] ?? []],
        );

        return response()->json(['status' => 'ok']);
    }
}
