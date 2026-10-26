<?php

namespace App\Http\Controllers;

use App\Jobs\BuildCoachPages;
use Illuminate\Support\Facades\File;

/**
 * Any player's game card, for an admin opening it from /admin/matches (2026-10-15). The same file
 * the player's own "Your games" serves (CoachController::page), read from that player's folder.
 * The route carries `can:admin`; the file name is held to the pattern the cards are written with.
 */
class AdminMatchCardController extends Controller
{
    public function __invoke(int $user, string $file)
    {
        // A game's timeline is built when asked, not stored (CoachController::page does the same).
        if (preg_match('/^watch-([0-9a-f]{32})\.html$/', $file, $m)) {
            $html = app(\App\Http\Services\WatchPageService::class)->html($user, $m[1], null, ['game' => $m[1].'.html', 'folder' => null]);
            abort_if($html === null, 404);

            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-cache']);
        }

        $path = BuildCoachPages::dir($user).'/'.$file;
        abort_unless(File::exists($path), 404);

        return response(File::get($path), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-cache']);
    }
}
