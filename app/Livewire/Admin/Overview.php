<?php

namespace App\Livewire\Admin;

use App\Models\PageViewEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /admin: the site's numbers in one place (2026-10-19, Chriso: "combine the analytics into one
 * spot"). Every count here is of PEOPLE, and the parts add up to the whole:
 *
 *  - A visitor is a session in which a browser ran a page (confirmed_at, from the layout's signed
 *    beacon) or someone was signed in. Not "two tracked events": the quiz and basics pages log two
 *    rows for one load, so a crawler's single request met that bar, and 276 of the 303 "engaged"
 *    sessions of 12-19 Oct never ran a page at all.
 *  - Where they came from is each visitor's first outside referrer, and "Direct or an app link"
 *    for the rest, so the rows sum to the visitors. Most real visits carry no referrer.
 *  - Sign-ups are accounts created, by method; the old funnel counted loads of /register (crawlers
 *    follow the "Sign up free" link: 1 of 71 such sessions in 30 days was a browser).
 *
 * The detail pages stay where they were; the admin tab strip (layouts.app) links them all.
 */
class Overview extends Component
{
    public const PERIODS = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];

    #[Url]
    public string $period = '7';

    private function days(): int
    {
        return isset(self::PERIODS[$this->period]) ? (int) $this->period : 7;
    }

    /** Session ids of the people who visited between $from and $to. */
    private function people($from, $to): Collection
    {
        return PageViewEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('session_id')
            ->where(fn ($q) => $q->whereNotNull('confirmed_at')->orWhereNotNull('user_id'))
            ->distinct()
            ->pluck('session_id');
    }

    /** @return array<string, mixed> */
    private function window($from, $to): array
    {
        $people = $this->people($from, $to);
        $rows = $people->isEmpty() ? collect() : PageViewEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('session_id', $people)
            ->orderBy('created_at')
            ->get(['session_id', 'page', 'slot', 'user_id', 'referrer_host', 'created_at']);
        $bySession = $rows->groupBy('session_id');

        $sessionsWith = fn (string $page) => $bySession->filter(fn ($r) => $r->contains('page', $page))->count();
        $users = DB::table('users')->whereBetween('created_at', [$from, $to]);
        $newUserIds = (clone $users)->pluck('id');
        $battlenet = DB::table('battlenet_accounts')->whereIn('user_id', $newUserIds)->pluck('user_id')->unique();
        $google = (clone $users)->whereNotNull('google_id')->pluck('id');

        return [
            'visitors' => $people->count(),
            'signedIn' => $bySession->filter(fn ($r) => $r->whereNotNull('user_id')->isNotEmpty())->count(),
            'views' => $rows->whereNull('slot')->count(),
            'sources' => $bySession->map(fn ($r) => $r->pluck('referrer_host')->filter()->first() ?? 'Direct or an app link')
                ->countBy()->sortDesc()->all(),
            'landing' => $bySession->map(fn ($r) => $r->whereNull('slot')->first()?->page ?? $r->first()->page)
                ->countBy()->sortDesc()->take(8)->all(),
            'pages' => $rows->whereNull('slot')->groupBy('page')->map(fn ($r) => $r->pluck('session_id')->unique()->count())
                ->sortDesc()->take(12)->all(),
            'path' => [
                'Visited the site' => $people->count(),
                'Opened the app page' => $sessionsWith('app_page'),
                'Downloaded the app' => $sessionsWith('desktop_app'),
                'Created an account' => $newUserIds->count(),
                'Connected the app' => DB::table('users')->whereBetween('coach_token_at', [$from, $to])->count(),
                'Uploaded a game' => DB::table('arena_rounds')->whereBetween('created_at', [$from, $to])->distinct()->count('user_id'),
            ],
            'signups' => [
                'Google' => $google->count(),
                'Battle.net' => $battlenet->diff($google)->count(),
                'Email' => $newUserIds->count() - $google->count() - $battlenet->diff($google)->count(),
            ],
            'games' => DB::table('arena_rounds')->whereBetween('created_at', [$from, $to])->distinct()->count('lobby_id'),
            'interests' => DB::table('game_interests')->whereBetween('created_at', [$from, $to])->count(),
            // What is NOT in "visitors": crawlers that say so, and requests no browser ever ran.
            'bots' => PageViewEvent::where('is_bot', true)->whereNull('slot')->whereBetween('created_at', [$from, $to])->count(),
            'scripts' => PageViewEvent::human()->whereBetween('created_at', [$from, $to])->whereNotNull('session_id')
                ->whereNotIn('session_id', $people)->distinct()->count('session_id'),
        ];
    }

    /** @return array<int, array<string, mixed>> the last 14 days, newest first */
    private function daily(): array
    {
        $out = [];
        for ($i = 0; $i < 14; $i++) {
            $from = now()->subDays($i)->startOfDay();
            $to = (clone $from)->endOfDay();
            $people = $this->people($from, $to);
            $out[] = [
                'day' => $from->format('D j M'),
                'visitors' => $people->count(),
                'appPage' => $people->isEmpty() ? 0 : PageViewEvent::whereBetween('created_at', [$from, $to])->where('page', 'app_page')->whereIn('session_id', $people)->distinct()->count('session_id'),
                'downloads' => $people->isEmpty() ? 0 : PageViewEvent::whereBetween('created_at', [$from, $to])->where('page', 'desktop_app')->whereIn('session_id', $people)->distinct()->count('session_id'),
                'signups' => DB::table('users')->whereBetween('created_at', [$from, $to])->count(),
                'connected' => DB::table('users')->whereBetween('coach_token_at', [$from, $to])->count(),
                'games' => DB::table('arena_rounds')->whereBetween('created_at', [$from, $to])->distinct()->count('lobby_id'),
            ];
        }

        return $out;
    }

    public function render()
    {
        $days = $this->days();
        $now = $this->window(now()->subDays($days), now());
        $before = $this->window(now()->subDays(2 * $days), now()->subDays($days));
        $labels = PageUsage::pageLabels();

        return view('livewire.admin.overview', [
            'now' => $now,
            'before' => $before,
            'daily' => $this->daily(),
            'labels' => $labels,
            'periods' => self::PERIODS,
        ])->layout('layouts.app', ['title' => 'Admin']);
    }
}
