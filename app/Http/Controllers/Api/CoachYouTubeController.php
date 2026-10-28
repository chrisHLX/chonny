<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\YouTubeService;
use App\Http\Services\YouTubeVideoService;
use App\Jobs\BuildCoachPages;
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
        if (! $youtube->availableTo($user)) {
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
        $video = YoutubeVideo::updateOrCreate(
            ['user_id' => $user->id, 'lobby_id' => $data['lobby']],
            ['video_id' => $data['videoId'], 'privacy' => $data['privacy'], 'started' => $data['started'] ?? null, 'rounds' => $data['rounds'] ?? [],
                // What YouTube says is asked later (YouTubeVideoService::refresh); until then, what was chosen.
                'status' => $data['privacy'], 'listed' => null],
        );
        $video->forceFill(['checked_at' => null, 'game' => null])->save();
        app(YouTubeVideoService::class)->ensureSummary($video);
        // The game's card and the list say it is on YouTube (its signature carries the video).
        BuildCoachPages::dispatch($user->id, cardsOnly: true);

        return response()->json(['status' => 'ok', 'page' => route('videos.show', $video->video_id)]);
    }

    /**
     * The player's videos for the app's upload window (2026-10-28): for each, who can watch it, its
     * views, and its page to share. YouTube is asked again about any not asked for ten minutes.
     */
    public function videos(Request $request, YouTubeVideoService $service): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }
        $videos = YoutubeVideo::where('user_id', $user->id)->latest()->limit(100)->get();
        $service->refresh($videos);

        return response()->json(['status' => 'ok', 'library' => route('videos'), 'manage' => route('coach').'#videos',
            'videos' => $videos->map(fn (YoutubeVideo $v) => [
                'lobby' => $v->lobby_id,
                'videoId' => $v->video_id,
                'title' => $v->game['title'] ?? $v->title,
                'privacy' => $v->currentPrivacy(),
                'processing' => $v->processing,
                'watchable' => $v->watchable(),
                'inLibrary' => $v->inLibrary(),
                'views' => $v->views,
                'line' => $v->shareLine(),
                'page' => route('videos.show', $v->video_id),
                'uploadedAt' => $v->created_at?->toIso8601String(),
            ])->values()]);
    }
}
