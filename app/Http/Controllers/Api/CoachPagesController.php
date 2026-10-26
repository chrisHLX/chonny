<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\WatchPageService;
use App\Jobs\BuildCoachPages;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * The desktop app reading a player's pages back from the website, with the same key it uploads with
 * (`Authorization: Bearer mc_...`, from /wow/coach). This is what lets the app run on a PC with no
 * copy of this project: it sends rounds (CoachUploadController), the server measures them and builds
 * the pages (BuildCoachPages), and the app shows what the server built.
 *
 * Read only, and only ever from the key's own player's folder, exactly as CoachController serves the
 * same files to the signed-in browser. The pages carry site-relative image paths (/storage/...); the
 * app gives them the site as their base.
 */
class CoachPagesController extends Controller
{
    /** The player's index: every game, character, comp and class page the last build drew. */
    public function index(Request $request): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised', 'reason' => 'Unknown key: make a new one on /wow/coach.'], 401);
        }

        $path = BuildCoachPages::dir($user->id).'/index.json';
        $index = File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];

        return response()->json([
            'built' => $index !== [],
            'builtAt' => File::exists($path) ? date(DATE_ATOM, File::lastModified($path)) : null,
        ] + $index);
    }

    /** One page, from the key's own player's folder only. */
    public function page(Request $request, string $file): Response
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }

        $path = BuildCoachPages::dir($user->id).'/'.$file;
        abort_unless(File::exists($path), 404);

        // The app keeps each page and asks with its ETag; an unchanged page is answered with 304 and
        // no body. Laravel does not do this on its own: without it the app re-downloaded every page
        // (212 KB for a shuffle) on each click (2026-10-08).
        $etag = '"'.md5_file($path).'"';
        $headers = ['Cache-Control' => 'private, no-cache', 'ETag' => $etag];
        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304, $headers);
        }

        return response(File::get($path), 200, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * The host the desktop app serves its videos folder under (tools/desktop-app/VideoLibrary.cs,
     * SetVirtualHostNameToFolderMapping). The page plays the video from the player's own disk; it is
     * never uploaded.
     */
    public const VIDEO_HOST = 'videos.mindcollector.invalid';

    /**
     * One game's Watch page for the app's Videos tab (2026-10-26): the video the app recorded, with
     * the game's goes, deaths and defensives under it. The app sends what the video's timing file
     * says (its name, its first frame, each round's START, on the PC's clock, which is the combat
     * log's clock and so played_at's), so each round is placed in the video exactly.
     */
    public function watch(Request $request, string $lobby): Response
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }

        $rounds = ArenaRound::where('user_id', $user->id)->where('lobby_id', $lobby)->get();
        abort_if($rounds->isEmpty(), 404);

        $recording = null;
        $video = basename((string) $request->query('video', ''));
        $clock = function ($at): ?float {
            try {
                return is_string($at) && $at !== '' ? (float) Carbon::parse($at)->format('U.v') : null;
            } catch (\Throwable) {
                return null;
            }
        };
        if ($video !== '' && ($started = $clock($request->query('started'))) !== null) {
            $recording = [
                'name' => $video,
                'url' => 'https://'.self::VIDEO_HOST.'/'.rawurlencode($video),
                'started' => $started,
                'rounds' => array_values(array_filter(array_map($clock, array_slice((array) $request->query('rounds', []), 0, 12)), fn ($t) => $t !== null)),
            ];
        }

        // Icons as /storage/... : the app shows this page with the site as its base (DesktopAsset).
        config(['desktop.web_assets' => true]);
        $page = app(WatchPageService::class)->page($rounds, $recording);
        abort_if($page === null, 404);
        $page['app'] = [
            'game' => 'mc-app://game/'.$lobby,
            'folder' => $video !== '' ? 'mc-app://folder/'.rawurlencode($video) : null,
        ];

        return response(view('desktop.watch', $page)->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}
