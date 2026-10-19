<?php

namespace App\Http\Controllers;

use App\Http\Services\CompLeaderboardService;
use App\Livewire\CompsPlayed;

/**
 * A comp's card as its own document, for the frame on /wow/comps/played (CompsPlayed): only for an
 * account with the app connected, whatever asks for it directly.
 */
class CompCardController extends Controller
{
    public function __invoke(CompLeaderboardService $board, string $key, ?string $vs = null)
    {
        abort_unless(CompsPlayed::canOpen(auth()->user()), 403);

        $html = $board->card($key, $vs);
        abort_if($html === null, 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
