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
    /**
     * Tab => [file, label, what it is, the page's own title].
     *
     * The title is per tab because the page shows four different reports and a single heading has
     * to be either wrong or vague for three of them. It names the report rather than selling it:
     * the surrounding page already does the selling, and a demonstration of an analytical tool
     * reads as marketing copy if it is given a marketing voice (Chriso, 2026-10-22).
     */
    public const PAGES = [
        'game' => ['game.html', 'A 3v3 game', 'Every game you play gets a card like this about a minute after it ends: who you played, how each death happened, both teams\' goes, and everyone\'s damage and healing.', '3v3 Post-Match Analysis'],
        // The Watch page on the game's own clock (2026-10-26; tools/app-pictures/watchsample.php,
        // which swaps the six names at the source and checks the page against every name held).
        'watch' => ['watch.html', 'Watch a game', "Press play and the game runs on its own clock. Each time someone's health fell, the timeline shows what the other team used to do it. Under it, every player's buttons light up when ready and count down when used, and a line shows everything they pressed. Record with OBS and your video plays above it.", 'Game Timeline'],
        'shuffle' => ['shuffle.html', 'A Solo Shuffle', 'A Solo Shuffle lobby, round by round: who you were with, who died first, and how each round was won or lost.', 'Solo Shuffle Post-Match Analysis'],
        'improve' => ['improve.html', 'Where you stand', 'Each character gets a page like this: your damage, defensives and crowd control set against every other player of your spec in your games, session by session.', 'Where You Stand'],
        'comp' => ['comp.html', 'A comp you met', 'Every comp you have played against, from your own games with them: when they go, what they go with, the crowd control they chain and who they kill.', 'Enemy Comp Report'],
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
