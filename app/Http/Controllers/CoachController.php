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
 * the raw review-tool output (admin only now). Tabs: Games, Improve, Comps, Shuffle, Classes (the
 * app's pages), Same spec (Game Review's lobby reviews, which open on their own page) and Upload (the
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
        $user = $request->user();
        // Signed out: the sample game, which says what this page would hold and offers the sign-in.
        if ($user === null) {
            return redirect()->route('wow.sample');
        }
        PageViewEvent::log('coach');
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
            // The strongest player of each spec met, by class (ClassLibraryService).
            'classes' => collect($index['classes'] ?? [])->sortBy(fn ($c) => $c['class'].'|'.$c['spec'].'|'.$c['name'])->groupBy('class')->all(),
            'mirrors' => app(LobbyReviewService::class)->index($user),
            'hasKey' => $user->coach_token_at !== null,
            'keyMadeAt' => $user->coach_token_at,
            'newKey' => session('coach_key'),
            'pooled' => $user->pool_opt_out_at === null,
        ]);
    }

    /** One page, from the viewer's own folder only. */
    public function page(Request $request, string $file)
    {
        // A game's timeline (2026-10-26), built from the stored rounds when asked.
        if (preg_match('/^watch-([0-9a-f]{32})\.html$/', $file, $m)) {
            $html = app(\App\Http\Services\WatchPageService::class)->html($request->user()->id, $m[1], null, ['game' => $m[1].'.html', 'folder' => null]);
            abort_if($html === null, 404);

            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-cache']);
        }

        $path = BuildCoachPages::dir($request->user()->id).'/'.$file;
        abort_unless(File::exists($path), 404);

        return response(File::get($path), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-cache']);
    }

    /**
     * In or out of the shared averages (2026-10-12). Out takes effect at the next rebuild of the
     * norms, a couple of minutes later. The averages already published are totals with nobody in them.
     */
    public function pool(Request $request)
    {
        $user = $request->user();
        $user->forceFill(['pool_opt_out_at' => $request->boolean('share') ? null : now()])->save();
        \App\Jobs\RefreshPopulationNorms::dispatch()->delay(now()->addSeconds(\App\Jobs\RefreshPopulationNorms::DELAY_SECONDS));

        return redirect()->route('coach')->with('pool_status', $user->pool_opt_out_at
            ? 'Done. Your games are out of the shared averages.'
            : 'Done. Your games count towards the shared averages again.');
    }

    /** A new key for the desktop app, shown once; it replaces any earlier key. */
    public function key(Request $request)
    {
        return redirect()->route('coach')->with('coach_key', $request->user()->issueCoachToken());
    }

    /**
     * Write a note on one of your own games, from the browser (2026-10-22).
     *
     * Deliberately unstructured: a note is whatever the player noticed while reviewing, and
     * giving it fields now would decide what is worth noticing before anyone has noticed
     * anything. The desktop app writes notes too (notes.json); both land on the same card.
     *
     * The lobby is checked against the viewer's own rounds, not trusted from the form -- a lobby
     * id is guessable and a note is the first thing on this page that writes.
     */
    public function note(Request $request)
    {
        $data = $request->validate([
            'lobby' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $user = $request->user();
        abort_unless(
            \App\Models\ArenaRound::where('user_id', $user->id)->where('lobby_id', $data['lobby'])->exists(),
            404,
        );

        \App\Models\ArenaNote::create([
            'user_id' => $user->id,
            'lobby_id' => $data['lobby'],
            'body' => trim($data['body']),
        ]);

        // The card embeds its notes, and a note changes the game's signature, so the page is
        // redrawn rather than patched. Cards first: that queue is the one the workers take first.
        BuildCoachPages::dispatch($user->id, cardsOnly: true);

        return redirect()->route('coach')->with('note_status', 'Note saved. The card redraws in a moment.');
    }
}
