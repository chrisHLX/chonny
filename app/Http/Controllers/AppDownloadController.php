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

    /**
     * The version of the app the website serves (2026-10-11), which the app checks to update
     * itself: storage/app/desktop/version.json, written beside the exe when it is published
     * (tools/desktop-app/README.md). Null when it is not there, which turns updating off.
     */
    public function appVersion(): JsonResponse
    {
        return response()->json(['version' => self::servedVersion()]);
    }

    /** The version in storage/app/desktop/version.json, or null when it is missing or malformed. */
    public static function servedVersion(): ?string
    {
        $path = dirname(DesktopAppController::path()).'/version.json';
        $version = is_file($path) ? (json_decode((string) file_get_contents($path), true)['version'] ?? null) : null;

        return is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : null;
    }

    /**
     * What search engines read about the app (schema.org SoftwareApplication, 2026-10-15): a free
     * Windows app, its version and its pictures, so a search for a WoW arena app can show it as one.
     */
    public static function schema(): array
    {
        return array_filter([
            '@type' => 'SoftwareApplication',
            'name' => 'MindCollector',
            'description' => 'Reviews every WoW arena game you play: a card for each game, and how you compare with other players of your spec.',
            'operatingSystem' => 'Windows 10, Windows 11',
            'applicationCategory' => 'GameApplication',
            'url' => route('app'),
            'downloadUrl' => route('coach.app'),
            'softwareVersion' => self::servedVersion(),
            'screenshot' => collect(['card', 'improve', 'death', 'comp'])->map(fn ($p) => url("/images/app/{$p}.webp"))->all(),
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
            'publisher' => ['@type' => 'Organization', 'name' => 'MindCollector', 'url' => url('/')],
        ], fn ($v) => $v !== null);
    }

    private static function key(string $code): string
    {
        return 'coach-connect:'.$code;
    }
}
