<?php

namespace App\Livewire\Admin;

use App\Models\PageViewEvent;
use App\Models\Specialization;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * How the pages written for search are doing (/admin/search-pages, 2026-10-16): for each spec guide
 * (SpecPvpController), the people who read it, how many came from a search engine, how much crawlers
 * look at it, and what readers did next on the site (the app's page, the download, connecting the app).
 *
 * Read off page_view_events, which already holds the session, the referrer's host and whether a
 * browser confirmed the view (rule 28: `is_bot = false` is not a person, a confirmed view is). A
 * download is a file, not a page, so it never sends the browser's beacon: it counts when the request
 * was not a bot's and carried the reader's session.
 *
 * Which searches bring people, and where each page ranks, needs Google Search Console; until it is
 * connected the page says so rather than guessing from referrers, which carry no query.
 */
class SearchPages extends Component
{
    public const PERIODS = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];

    /** Referrer hosts that are search engines. */
    public const SEARCH_HOSTS = ['google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'ecosia.org', 'search.brave.com', 'yandex.', 'baidu.com', 'qwant.com'];

    /** What a reader can go on to do, in the order the funnel reads. */
    public const NEXT = ['app_page' => 'Opened the app page', 'desktop_app' => 'Downloaded the app', 'app_connect' => 'Connected the app'];

    #[Url]
    public string $period = '30';

    public static function isSearch(?string $host): bool
    {
        return $host !== null && collect(self::SEARCH_HOSTS)->contains(fn ($s) => str_contains($host, $s));
    }

    private function rows(): array
    {
        $from = now()->subDays(isset(self::PERIODS[$this->period]) ? (int) $this->period : 30);
        $views = PageViewEvent::query()
            ->whereIn('page', ['spec_pvp', 'spec_pvp_index'])
            ->where('created_at', '>=', $from)
            ->get(['page', 'spec_id', 'session_id', 'is_bot', 'referrer_host', 'confirmed_at', 'created_at']);

        $people = $views->filter(fn ($v) => $v->confirmed_at !== null && $v->session_id);
        // When each reader first opened a guide, so "next" only counts what came after it.
        $firstSeen = $people->groupBy('session_id')->map(fn ($v) => $v->min('created_at'));
        $next = PageViewEvent::query()
            ->whereIn('session_id', $firstSeen->keys())
            ->whereIn('page', array_keys(self::NEXT))
            ->where('is_bot', false)
            ->get(['page', 'session_id', 'created_at'])
            ->filter(fn ($e) => $e->created_at >= $firstSeen[$e->session_id]);
        $nextBySession = $next->groupBy('session_id')->map(fn ($e) => $e->pluck('page')->unique()->all());

        $summarise = function (Collection $views) use ($nextBySession) {
            $readers = $views->filter(fn ($v) => $v->confirmed_at !== null && $v->session_id);
            $sessions = $readers->pluck('session_id')->unique();
            $fromSearch = $readers->filter(fn ($v) => self::isSearch($v->referrer_host))->pluck('session_id')->unique();
            $then = [];
            foreach (array_keys(self::NEXT) as $page) {
                $then[$page] = $sessions->filter(fn ($s) => in_array($page, $nextBySession[$s] ?? [], true))->count();
            }

            return [
                'readers' => $sessions->count(),
                'fromSearch' => $fromSearch->count(),
                'crawler' => $views->where('is_bot', true)->count(),
                'then' => $then,
            ];
        };

        $specs = Specialization::with('gameClass')->whereIn('id', $views->pluck('spec_id')->filter()->unique())->get()->keyBy('id');
        $pages = $views->where('page', 'spec_pvp')->groupBy('spec_id')
            ->map(fn ($v, $specId) => ['spec' => $specs[$specId] ?? null] + $summarise($v))
            ->filter(fn ($r) => $r['spec'] !== null)
            ->sortByDesc(fn ($r) => $r['readers'] * 1000 + $r['crawler'])
            ->values();

        $engines = $people->filter(fn ($v) => self::isSearch($v->referrer_host))
            ->groupBy(fn ($v) => preg_replace('/^www\./', '', $v->referrer_host))
            ->map(fn ($v) => $v->pluck('session_id')->unique()->count())
            ->sortDesc();

        return [
            'total' => $summarise($views),
            'hub' => $summarise($views->where('page', 'spec_pvp_index')),
            'pages' => $pages,
            'engines' => $engines,
        ];
    }

    public function render()
    {
        return view('livewire.admin.search-pages', $this->rows() + [
            'periods' => self::PERIODS,
            'next' => self::NEXT,
            'classColors' => config('wow_classes.colors', []),
        ])->layout('layouts.app', ['title' => 'Search pages']);
    }
}
