<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildCoachPages;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
