<?php

namespace App\Http\Controllers;

use App\Models\PageViewEvent;

/**
 * /wow/sample (2026-10-18): what "Your games" shows, for anyone, without an account. Four real
 * pages the app drew from real games (a 3v3 win, a Solo Shuffle, an Improve page, a comp page),
 * with every player's name swapped for a label ("You", "Their Evoker") by tools/app-pictures/anon.py,
 * whose check against every name in the database came back empty for all four. They are committed
 * in resources/sample/wow/ and served as they are, the way /wow/coach serves a player's own pages.
 *
 * To take them again after the app's pages change: tools/app-pictures/README.md, then copy the four
 * anonymised files over these names. Never copy a page that has not been through anon.py.
 */
class SampleGameController extends Controller
{
    /** Tab => [file, label, what it is]. */
    public const PAGES = [
        'game' => ['game.html', 'A 3v3 game', 'Every game you play gets a card like this about a minute after it ends: who you played, how each death happened, both teams\' goes, and everyone\'s damage and healing.'],
        'shuffle' => ['shuffle.html', 'A Solo Shuffle', 'A Solo Shuffle lobby, round by round: who you were with, who died first, and how each round was won or lost.'],
        'improve' => ['improve.html', 'Where you stand', 'Each character gets a page like this: your damage, defensives and crowd control set against every other player of your spec in your games, session by session.'],
        'comp' => ['comp.html', 'A comp you met', 'Every comp you have played against, from your own games with them: when they go, what they go with, the crowd control they chain and who they kill.'],
    ];

    public function index()
    {
        PageViewEvent::log('wow_sample');

        return view('sample.index', ['pages' => self::PAGES]);
    }

    public function page(string $tab)
    {
        abort_unless(isset(self::PAGES[$tab]), 404);

        return response(file_get_contents(resource_path('sample/wow/'.self::PAGES[$tab][0])), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
