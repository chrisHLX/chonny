<?php

namespace App\Http\Controllers;

use App\Http\Services\YouTubeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Linking the player's YouTube channel (2026-10-26): the app can then upload the games they pick
 * (YouTubeService). Started from Your games; the callback lands back there.
 */
class YouTubeLinkController extends Controller
{
    public function redirect(Request $request, YouTubeService $youtube): RedirectResponse
    {
        abort_unless($youtube->availableTo($request->user()), 404);
        $state = Str::random(40);
        $request->session()->put('youtube_state', $state);

        return redirect()->away($youtube->authorizeUrl($state));
    }

    public function callback(Request $request, YouTubeService $youtube): RedirectResponse
    {
        abort_unless($youtube->availableTo($request->user()), 404);
        $back = redirect()->to(route('coach').'#youtube');
        if (! hash_equals((string) $request->session()->pull('youtube_state'), (string) $request->query('state'))) {
            return $back->with('youtube_status', 'That link had expired. Click Connect YouTube to try again.');
        }
        if ($request->query('error')) {
            return $back->with('youtube_status', 'YouTube was not linked.');
        }
        $token = $request->query('code') ? $youtube->exchange((string) $request->query('code')) : null;
        if (! $token) {
            return $back->with('youtube_status', 'Google did not give us access. Click Connect YouTube to try again.');
        }
        $request->user()->forceFill(['youtube_token' => $token, 'youtube_linked_at' => now()])->save();

        return $back->with('youtube_status', 'YouTube is linked. The app can upload your games to your channel now.');
    }

    public function disconnect(Request $request, YouTubeService $youtube): RedirectResponse
    {
        $youtube->disconnect($request->user());

        return redirect()->to(route('coach').'#youtube')->with('youtube_status', 'YouTube is disconnected, and our access is revoked with Google.');
    }
}
