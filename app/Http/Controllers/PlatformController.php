<?php

namespace App\Http\Controllers;

use App\Models\GameInterest;
use App\Models\Module;
use App\Models\PageViewEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * MindCollector as a platform (2026-10-17): the home at / that says what MindCollector is and lets
 * a visitor pick a game, and the page of each game that is not open yet (/sc2, /lol), where a
 * player can ask to be told when it is. WoW's own home is the old front page, now at /wow
 * (App\Livewire\Landing). The games are config/platform.php.
 */
class PlatformController extends Controller
{
    public function home()
    {
        PageViewEvent::log('platform_home');

        return view('platform.home', ['games' => config('platform.games')]);
    }

    public function game(Request $request, string $game)
    {
        $info = config("platform.games.{$game}");
        abort_if($info === null || $info['status'] !== 'soon', 404);
        PageViewEvent::log("{$game}_home");

        $user = $request->user();

        return view('platform.coming', [
            'slug' => $game,
            'game' => $info,
            'games' => config('platform.games'),
            'now' => self::openNow($game),
            'waiting' => $user !== null && GameInterest::where('game', $game)->where('user_id', $user->id)->exists(),
        ]);
    }

    /**
     * The game's playstyle diagnostic at the game's own address (/sc2/diagnostic), so the sidebar
     * stays on that game. It is the learning platform's diagnostic module named by
     * app.screener_{game}_module_id, shown exactly as /modules/{slug}/quiz shows it.
     */
    public function diagnostic(string $game)
    {
        $slug = config("app.screener_{$game}_module_id");
        $module = $slug ? Module::where('slug', $slug)->first() : null;
        abort_if($module === null, 404);

        PageViewEvent::log("{$game}_diagnostic");

        return app(ModuleQuizController::class)->show($module);
    }

    /**
     * What a coming game already has open (config platform.games.*.now), each with its address:
     * the game's page and the sidebar both list them.
     *
     * @return array<int, array{title: string, body: string, cta: string, url: string}>
     */
    public static function openNow(string $game): array
    {
        return collect(config("platform.games.{$game}.now", []))
            ->map(fn (array $n) => $n + ['url' => isset($n['route']) && Route::has($n['route']) ? route($n['route']) : null])
            ->filter(fn (array $n) => $n['url'] !== null)
            ->values()->all();
    }

    /** "Tell me when it's ready": by the account when signed in, by email otherwise. */
    public function notify(Request $request, string $game)
    {
        $info = config("platform.games.{$game}");
        abort_if($info === null || $info['status'] !== 'soon', 404);

        // A field no person sees: a form filled in by a script fills it, and gets the same thanks.
        if (filled($request->input('website'))) {
            return back()->with('interest_status', 'Thanks. We will let you know when it is ready.');
        }

        if ($user = $request->user()) {
            GameInterest::firstOrCreate(['game' => $game, 'user_id' => $user->id], ['email' => null]);
        } else {
            $email = strtolower(trim((string) $request->validate(['email' => ['required', 'email:rfc', 'max:190']])['email']));
            GameInterest::firstOrCreate(['game' => $game, 'email' => $email]);
        }
        PageViewEvent::log("{$game}_notify");

        return back()->with('interest_status', "Thanks. We will let you know when {$info['name']} is ready on MindCollector.");
    }
}
