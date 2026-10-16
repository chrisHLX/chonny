<?php

namespace App\Http\Controllers;

use App\Models\GameInterest;
use App\Models\PageViewEvent;
use Illuminate\Http\Request;

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
            'waiting' => $user !== null && GameInterest::where('game', $game)->where('user_id', $user->id)->exists(),
        ]);
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
