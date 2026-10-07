<?php

namespace App\Http\Controllers;

use App\Http\Services\LobbyReviewService;
use App\Jobs\BuildCoachPages;
use App\Models\PageViewEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * "Your games" (/wow/coach, "Your coach" until 2026-10-05): the one place for a player's own games.
 * Merged that day from four pages (Chriso): this one (the desktop app's pages), Game Review (the
 * browser upload and the same-spec lobby review), "Your analysis" (retired: Improve replaces it) and
 * the raw review-tool output (admin only now). Tabs: Games, Improve, Comps, Shuffle (the app's
 * pages), Same spec (Game Review's lobby reviews, which open on their own page) and Upload (the
 * browser upload and the desktop app key). A browser upload builds the same pages as the app's.
 *
 * The desktop app's pages on the website, for review away from the PC. One copy of every page: the same templates and services draw them for the app
 * (into %APPDATA%) and for the site (into storage/app/coach/{user}, by BuildCoachPages after each
 * upload). A change to a page reaches the app at the next card build and the site at the next deploy.
 *
 * The pages are served as the files they are, inside a frame, so their own styles stay theirs and
 * the site's never restyle them. Every page names the other players in the games, so each is private
 * to its player: a page is only ever read from the viewer's own folder.
 */
class CoachController extends Controller
{
    public function index(Request $request)
    {
        PageViewEvent::log('coach');
        $user = $request->user();
        $path = BuildCoachPages::dir($user->id).'/index.json';
        $index = File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];

        $games = collect($index['games'] ?? [])->map(fn ($g, $lobby) => $g + ['file' => $lobby.'.html'])
            ->sortByDesc('playedAt')->values()->all();
        $comps = collect($index['comps'] ?? [])->map(fn ($c, $key) => $c + ['key' => $key])->sortByDesc('games')->values();

        return view('coach.index', [
            'built' => $index !== [],
            'games' => $games,
            'characters' => collect($index['characters'] ?? [])->sortByDesc('games')->values()->all(),
            'comps' => $comps->filter(fn ($c) => ! str_contains($c['key'], ':'))->values()->all(),
            'shuffle' => $comps->filter(fn ($c) => str_starts_with($c['key'], 'shuffle:'))->values()->all(),
            'mirrors' => app(LobbyReviewService::class)->index($user),
            'hasKey' => $user->coach_token_at !== null,
            'keyMadeAt' => $user->coach_token_at,
            'newKey' => session('coach_key'),
        ]);
    }

    /** One page, from the viewer's own folder only. */
    public function page(Request $request, string $file)
    {
        $path = BuildCoachPages::dir($request->user()->id).'/'.$file;
        abort_unless(File::exists($path), 404);

        return response(File::get($path), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-cache']);
    }

    /** A new key for the desktop app, shown once; it replaces any earlier key. */
    public function key(Request $request)
    {
        return redirect()->route('coach')->with('coach_key', $request->user()->issueCoachToken());
    }
}
