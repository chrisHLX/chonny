<?php

namespace App\Http\Controllers;

use App\Models\Specialization;
use App\Models\UserGuide;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * /sitemap.xml, built from the database (2026-10-15). It replaced a hand-written public/sitemap.xml
 * of 11 URLs that left out the app's page, the Matchup Lab, the Brain, every spec's guide page and
 * every published guide, and listed /wow/pvp-guides, which only redirects to a spec.
 *
 * Only the URL a page lives at, never one that redirects; only public pages (robots.txt keeps
 * /admin, /dashboard and the builder out). Cached for six hours: guides change rarely, and the box
 * has one core.
 */
class SitemapController extends Controller
{
    public const CACHE_KEY = 'sitemap.xml';

    /** The fixed public pages, most important first, with how often each changes. */
    private const PAGES = [
        '/' => ['weekly', '1.0'],
        '/wow' => ['daily', '1.0'],
        '/wow/app' => ['weekly', '0.9'],
        '/sc2' => ['monthly', '0.5'],
        '/sc2/quiz' => ['monthly', '0.5'],
        '/sc2/quiz/terran' => ['monthly', '0.4'],
        '/sc2/quiz/protoss' => ['monthly', '0.4'],
        '/sc2/quiz/zerg' => ['monthly', '0.4'],
        '/wow/comps' => ['daily', '0.9'],
        '/wow/matchup-lab' => ['weekly', '0.8'],
        '/browse-guides' => ['daily', '0.8'],
        '/claudes-comp-guides' => ['weekly', '0.7'],
        '/brain' => ['weekly', '0.7'],
        '/wow/spells' => ['weekly', '0.7'],
        '/wow/burst-guides' => ['weekly', '0.6'],
        '/wow/top-damage-rotations' => ['weekly', '0.6'],
        '/wow/cc-chains' => ['weekly', '0.6'],
        '/wow/spell-counters' => ['weekly', '0.6'],
        '/privacy' => ['yearly', '0.2'],
        '/terms' => ['yearly', '0.2'],
    ];

    public function __invoke(): Response
    {
        $xml = Cache::remember(self::CACHE_KEY, now()->addHours(6), fn () => $this->build());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function build(): string
    {
        $urls = [];
        foreach (self::PAGES as $path => [$freq, $priority]) {
            $urls[] = ['loc' => url($path), 'freq' => $freq, 'priority' => $priority];
        }

        // The spec guides counted from real games (SpecPvpController): the pages written for search.
        $urls[] = ['loc' => route('spec-pvp.index'), 'freq' => 'weekly', 'priority' => '0.9'];
        $withPages = array_map('intval', array_keys(\App\Support\SpecPages::load()['specs'] ?? []));
        foreach (Specialization::with('gameClass')->whereIn('external_spec_id', $withPages)
            ->whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))->get() as $spec) {
            $urls[] = ['loc' => route('spec-pvp.show', [$spec->gameClass->slug, $spec->slug]), 'freq' => 'weekly', 'priority' => '0.8'];
        }

        // Every spec's guide page: /wow/pvp-guides alone only redirects to one of these.
        $specs = Specialization::with('gameClass')
            ->whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))
            ->get()
            ->sortBy(fn ($s) => $s->gameClass->slug.'/'.$s->slug);
        foreach ($specs as $spec) {
            $urls[] = ['loc' => route('pvp-guides', ['classSlug' => $spec->gameClass->slug, 'specSlug' => $spec->slug]), 'freq' => 'weekly', 'priority' => '0.7'];
        }

        // Published public guides, by people and by the model alike: both are public at /g/.
        foreach (UserGuide::listed()->with('user:id,username')->latest('updated_at')->get(['id', 'user_id', 'slug', 'updated_at']) as $guide) {
            if ($url = $guide->publicUrl()) {
                $urls[] = ['loc' => $url, 'freq' => 'weekly', 'priority' => '0.6', 'lastmod' => $guide->updated_at?->toDateString()];
            }
        }

        $body = collect($urls)->unique('loc')->map(fn ($u) => '    <url>'
            .'<loc>'.e($u['loc']).'</loc>'
            .(isset($u['lastmod']) ? '<lastmod>'.$u['lastmod'].'</lastmod>' : '')
            .'<changefreq>'.$u['freq'].'</changefreq>'
            .'<priority>'.$u['priority'].'</priority>'
            .'</url>')->implode("\n");

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".$body."\n</urlset>\n";
    }
}
