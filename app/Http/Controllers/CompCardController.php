<?php

namespace App\Http\Controllers;

use App\Http\Services\CompLeaderboardService;
use App\Livewire\CompsPlayed;

/**
 * A comp's card, or one game's, as its own document for the frame on /wow/comps/played (CompsPlayed)
 * and /wow/comps/game (CompGame): only for an account, whatever asks for it directly.
 */
class CompCardController extends Controller
{
    public function __invoke(CompLeaderboardService $board, string $key, ?string $vs = null)
    {
        abort_unless(CompsPlayed::canOpen(auth()->user()), 403);

        return $this->page($board->card($key, $vs));
    }

    /** One game of the board, read as the uploader's team or (?as=them) the team they played. */
    public function game(CompLeaderboardService $board, int $round)
    {
        abort_unless(CompsPlayed::canOpen(auth()->user()), 403);

        return $this->page($board->game($round, request()->query('as') === 'them' ? 'them' : 'us'));
    }

    /** One game's timeline, with no one named (CompLeaderboardService::timeline()). */
    public function timeline(CompLeaderboardService $board, int $round)
    {
        abort_unless(CompsPlayed::canOpen(auth()->user()), 403);

        return $this->page($board->timeline($round));
    }

    private function page(?string $html)
    {
        abort_if($html === null, 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
