<?php

namespace App\Http\Controllers;

use App\Http\Services\WatchPageService;
use App\Http\Services\YouTubeVideoService;
use App\Models\ArenaRound;
use App\Models\PageViewEvent;
use App\Models\YoutubeVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Games on video (2026-10-28): the games players uploaded to their YouTube channels from the app,
 * each on its own page with the game's timeline under the video, and a library of them filtered by
 * what the combat log knows exactly (both teams' specs, the bracket, MMR, the result).
 *
 * Who sees what follows YouTube. A video YouTube lets others watch (unlisted or public) has a page
 * anyone with its link can open; a private one plays only for its owner, so its page is the owner's
 * alone. The library holds the ones public on YouTube, unless the player chose otherwise on their
 * Videos tab (YoutubeVideo::inLibrary). Pages name specs, never players, as the video's title does:
 * the timeline swaps every name for its spec (WatchPageService::html, $public).
 */
class VideoController extends Controller
{
    private const PER_PAGE = 24;

    public function index(Request $request, YouTubeVideoService $service)
    {
        PageViewEvent::log('wow_videos');
        $filters = array_filter($request->only(['bracket', 'spec', 'pov', 'vs', 'result', 'mmr', 'sort']), fn ($v) => is_string($v) && $v !== '');
        if (array_diff_key($filters, ['sort' => true]) !== []) {
            PageViewEvent::log('wow_videos_filter', slot: mb_substr(http_build_query($filters), 0, 191));
        }

        // A video made public on YouTube after an unlisted upload joins the library without its owner
        // opening anything (2026-10-30, Chriso). Only with the API key, the one way that tells unlisted
        // from public, and at most every 15 minutes: one videos.list call for up to 50 videos.
        if (config('services.youtube.api_key') && Cache::add('youtube-library-recheck', true, now()->addMinutes(15))) {
            $service->refresh(YoutubeVideo::whereNotNull('game')->where('status', 'unlisted')->where(fn ($q) => $q->whereNull('listed')->orWhere('listed', true))
                ->latest()->limit(50)->get(), staleMinutes: 15);
        }
        $all = YoutubeVideo::whereNotNull('game')->latest('played_at')->get()->filter->inLibrary()->values();
        $options = $this->options($all);
        $videos = $all->filter(fn (YoutubeVideo $v) => $this->matches($v->game, $filters))->values();
        if (($filters['sort'] ?? null) === 'views') {
            $videos = $videos->sortByDesc(fn ($v) => $v->views ?? 0)->values();
        }
        $page = max(1, (int) $request->query('page', 1));

        return view('videos.index', [
            'videos' => $videos->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'total' => $videos->count(),
            'page' => $page,
            'pages' => (int) ceil($videos->count() / self::PER_PAGE),
            'filters' => $filters,
            'options' => $options,
        ]);
    }

    public function show(Request $request, string $video, YouTubeVideoService $service)
    {
        $v = $this->find($request, $video);
        $service->refresh(collect([$v]), staleMinutes: 30);
        $service->ensureSummary($v);
        abort_unless($v->watchable() || $this->owns($request, $v), 404);
        PageViewEvent::log('wow_video');

        return view('videos.show', ['v' => $v, 'owner' => $this->owns($request, $v)]);
    }

    /** The game's timeline under the video, names swapped for specs; in a frame, as Your games shows it. */
    public function timeline(Request $request, string $video, WatchPageService $watch)
    {
        $v = $this->find($request, $video);
        abort_unless($v->watchable() || $this->owns($request, $v), 404);
        $measured = (string) ArenaRound::where('user_id', $v->user_id)->where('lobby_id', $v->lobby_id)->max('updated_at');
        // The view's own time is in the key, so a changed page is drawn afresh after a deploy.
        $view = (string) @filemtime(resource_path('views/desktop/watch.blade.php'));
        $html = Cache::remember('video-timeline:'.$v->id.':'.md5($measured.'|'.$v->updated_at.'|'.$view), now()->addHours(6),
            fn () => $watch->html($v->user_id, $v->lobby_id, null, null, public: true));
        abort_if($html === null, 404);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'public, max-age=300', 'X-Robots-Tag' => 'noindex']);
    }

    /** In the library or out of it: the owner's choice (Your games, Videos). */
    public function listed(Request $request, string $video)
    {
        $v = YoutubeVideo::where('video_id', $video)->where('user_id', $request->user()->id)->firstOrFail();
        $v->listed = $request->boolean('listed');
        $v->save();

        return redirect()->back()->with('videos_status', $v->listed
            ? 'It is in the video library now, for anyone to find.'
            : 'It is out of the video library. Anyone with its link can still watch it.');
    }

    private function find(Request $request, string $video): YoutubeVideo
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{6,20}$/', $video), 404);

        return YoutubeVideo::where('video_id', $video)->firstOrFail();
    }

    private function owns(Request $request, YoutubeVideo $v): bool
    {
        return $request->user()?->id === $v->user_id;
    }

    /**
     * Each filter's choices, from the videos in the library only: a choice never leads to nothing.
     *
     * @return array<string, array<string, string>>
     */
    private function options($videos): array
    {
        $specs = [];
        $povs = [];
        $classes = [];
        $brackets = [];
        foreach ($videos as $v) {
            $g = $v->game;
            $brackets[$g['bracket']] = $g['bracket'];
            foreach (array_merge($g['us'] ?? [], $g['them'] ?? []) as $p) {
                if ($p['specSlug'] ?? null) {
                    $specs[$p['classSlug'].'/'.$p['specSlug']] = $p['spec'];
                }
                if (($p['pov'] ?? false) && ($p['specSlug'] ?? null)) {
                    $povs[$p['classSlug'].'/'.$p['specSlug']] = $p['spec'];
                }
            }
            foreach ($g['them'] ?? [] as $p) {
                if ($p['classSlug'] ?? null) {
                    $classes[$p['classSlug']] = ucwords(str_replace('-', ' ', $p['classSlug']));
                }
            }
        }
        foreach ([&$specs, &$povs, &$classes, &$brackets] as &$list) {
            asort($list);
        }

        return ['bracket' => $brackets, 'spec' => $specs, 'pov' => $povs, 'vs' => $classes];
    }

    /** Whether a video's game passes the filters. A spec is class/spec; "vs" is a class on either team, against the recording player's. */
    private function matches(array $g, array $f): bool
    {
        $key = fn ($p) => ($p['classSlug'] ?? '').'/'.($p['specSlug'] ?? '');
        $everyone = array_merge($g['us'] ?? [], $g['them'] ?? []);
        if (isset($f['bracket']) && $g['bracket'] !== $f['bracket']) {
            return false;
        }
        if (isset($f['spec']) && ! collect($everyone)->contains(fn ($p) => $key($p) === $f['spec'])) {
            return false;
        }
        if (isset($f['pov']) && ! collect($g['us'] ?? [])->contains(fn ($p) => ($p['pov'] ?? false) && $key($p) === $f['pov'])) {
            return false;
        }
        if (isset($f['vs']) && ! collect($g['them'] ?? [])->contains(fn ($p) => ($p['classSlug'] ?? null) === $f['vs'])) {
            return false;
        }
        if (isset($f['result'])) {
            $won = $g['won'] ?? (isset($g['record']) ? $g['record'][0] > $g['record'][1] : null);
            if ($won === null || ($f['result'] === 'won') !== $won) {
                return false;
            }
        }
        if (isset($f['mmr']) && ($g['mmr'] ?? 0) < (int) $f['mmr']) {
            return false;
        }

        return true;
    }
}
