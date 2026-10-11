<?php

namespace App\Http\Controllers;

use App\Models\PageViewEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Getting the desktop app, from a link to a game on the page (2026-10-11): the download page, the
 * download itself, and connecting the app to an account without copying a key.
 *
 *   /wow/app                 what the app does and the four steps (public; unlisted for now)
 *   /wow/coach/app           the exe (public: it does nothing until it is connected to an account)
 *   /wow/app/connect?code=   the app opens this in the browser; the player signs in or signs up
 *                            (the intended URL brings them back) and approves
 *   GET /api/coach/connect/{code}   the app polls this and receives its key, once
 *
 * The code is 32 random hex characters the app makes and only it and the player's browser see. The
 * key waits in the cache for ten minutes and is handed out once. Approving issues a new key, which
 * replaces any earlier one (User::issueCoachToken), as making one on Your games always has. Like any
 * device sign-in, a player could be talked into approving someone else's code, so the page says
 * plainly to approve only a code their own app just opened.
 */
class AppDownloadController extends Controller
{
    private const CODE = '/^[a-f0-9]{32}$/';

    private const MINUTES = 10;

    public function page()
    {
        PageViewEvent::log('app_page');

        return view('app.download', ['available' => is_file(DesktopAppController::path())]);
    }

    public function connect(Request $request)
    {
        PageViewEvent::log('app_connect');
        $code = (string) $request->query('code', '');

        return view('app.connect', ['code' => preg_match(self::CODE, $code) ? $code : null, 'done' => false]);
    }

    public function approve(Request $request)
    {
        $code = (string) $request->input('code', '');
        abort_unless(preg_match(self::CODE, $code), 422);

        Cache::put(self::key($code), $request->user()->issueCoachToken(), now()->addMinutes(self::MINUTES));

        return view('app.connect', ['code' => $code, 'done' => true]);
    }

    /** The app's side: its key once the player has approved, else "waiting". */
    public function pickup(string $code): JsonResponse
    {
        abort_unless(preg_match(self::CODE, $code), 404);
        $key = Cache::pull(self::key($code));

        return $key
            ? response()->json(['status' => 'connected', 'key' => $key])
            : response()->json(['status' => 'waiting'], 202);
    }

    private static function key(string $code): string
    {
        return 'coach-connect:'.$code;
    }
}
