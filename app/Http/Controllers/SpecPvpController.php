<?php

namespace App\Http\Controllers;

use App\Console\Commands\BuildGoCooldowns;
use App\Http\Services\SpellIconIndex;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Specialization;
use App\Support\KickPriorities;
use App\Support\SpecPages;
use App\Support\SpellTips;
use Illuminate\Support\Collection;

/**
 * The public spec guides (2026-10-16): /wow/pvp lists every spec, /wow/pvp/{class}/{spec} is one
 * spec's arena guide counted from real games. Written for search: each page answers what people
 * type ("disc priest pvp talents", "ret paladin pvp guide midnight") with numbers from play, and
 * ends with the app, which gives the reader the same for their own games.
 *
 * Every number comes from a committed or live artifact (SpecPages, BuildGoCooldowns' file), never
 * from the archive (rule 14). A spec with too few games has no page: a page with no
 * numbers would be the thin kind of page search engines bury, and it would say nothing.
 *
 * Tracked as `spec_pvp` (attributed to the spec, since the address names it) and `spec_pvp_index`;
 * /admin/search-pages reads them with their search referrers and what the visitor did next.
 */
class SpecPvpController extends Controller
{
    /** The expansion the patch numbers belong to, for titles. Change it when the expansion changes. */
    public const EXPANSION = 'Midnight';

    public function index()
    {
        PageViewEvent::log('spec_pvp_index');

        $data = SpecPages::load();
        $specs = $this->specsWithPages($data)->groupBy(fn ($s) => $s->gameClass->name)->sortKeys();

        return view('spec-pvp.index', [
            'classes' => $specs,
            'data' => $data,
            'patch' => self::patchLabel(),
            'classColors' => config('wow_classes.colors', []),
        ]);
    }

    public function show(string $classSlug, string $specSlug)
    {
        $spec = Specialization::with('gameClass')
            ->where('slug', $specSlug)
            ->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug)->whereHas('game', fn ($g) => $g->where('slug', 'wow')))
            ->first();
        $page = $spec?->external_spec_id ? SpecPages::forSpec((int) $spec->external_spec_id) : null;
        abort_if($page === null, 404);

        PageViewEvent::log('spec_pvp', $spec->class_id, $spec->id);

        $all = collect(SpecPages::load()['specs'] ?? []);
        $bySpecId = $this->specsWithPages(SpecPages::load())->keyBy('external_spec_id');
        $goFile = json_decode((string) @file_get_contents(base_path(BuildGoCooldowns::PATH)), true) ?: [];

        // The average over every spec, weighted by rounds: the one comparison each death line needs.
        $rounds = max(1, $all->sum('rounds'));
        $average = [
            'died' => round($all->sum(fn ($s) => $s['died'] * $s['rounds']) / $rounds, 2),
            'diedFirst' => round($all->sum(fn ($s) => $s['diedFirst'] * $s['rounds']) / $rounds, 2),
            'interruptRounds' => round($all->sum(fn ($s) => ($s['interruptRounds'] ?? 0) * $s['rounds']) / $rounds, 2),
        ];

        // Every spec with a kick, pooled: the comparison the kicker line needs.
        $kickers = $all->pluck('priorityKicks')->filter();
        $kickChances = $kickers->sum('chances');
        $average['priorityKickShare'] = $kickChances > 0 ? round($kickers->sum('mine') / $kickChances, 2) : null;

        $key = $spec->gameClass->slug.'/'.$spec->slug;

        $comps = collect($page['comps'])->map(fn ($c) => $c + [
            'members' => collect($c['specs'])->map(fn ($id) => $bySpecId[$id] ?? null)->filter()->values(),
        ])->filter(fn ($c) => $c['members']->count() === 3)->values();

        $go = $goFile['specs'][(string) $spec->external_spec_id] ?? null;
        $kicks = KickPriorities::forSpec($key);
        $alsoKicked = array_slice(KickPriorities::alsoKicked($key), 0, 4);

        // Every ability the page names, to its icon in one query (SpellIconIndex).
        $icons = app(SpellIconIndex::class)->for(array_merge(
            array_keys($page['pvpTalents']),
            array_keys($page['defensives']['self']),
            array_keys($page['defensives']['team']),
            array_column($go['spells'] ?? [], 'name'),
            collect($go['healerRuns'] ?? [])->flatMap(fn ($r) => $r['chain'])->all(),
            array_column($kicks, 'name'),
            array_column($alsoKicked, 'name'),
        ));

        return view('spec-pvp.show', [
            'spec' => $spec,
            'name' => "{$spec->name} {$spec->gameClass->name}",
            'page' => $page,
            'go' => $go,
            'icons' => $icons,
            // What a click on a PvP talent opens: its description and cooldown (SpellTips, cached).
            'tips' => app(SpellTips::class)->forMany(array_keys($page['pvpTalents'])),
            'average' => $average,
            'comps' => $comps,
            'siblings' => $bySpecId->filter(fn ($s) => $s->class_id === $spec->class_id && $s->id !== $spec->id)->values(),
            'patch' => self::patchLabel(),
            'color' => config('wow_classes.colors', [])[$spec->gameClass->slug] ?? '#B3B3B3',
            'classColors' => config('wow_classes.colors', []),
            'builtAt' => SpecPages::load()['generatedAt'] ?? null,
            // What to kick on this spec: the published interrupt list, each cast set against the
            // archive (KickPriorities; committed files, rule 14).
            'kicks' => $kicks,
            'alsoKicked' => $alsoKicked,
            'kickSource' => KickPriorities::source($key),
            'kickGames' => KickPriorities::evidence()['games'] ?? 0,
        ]);
    }

    /** "Midnight 12.1", from the current patch's build. */
    public static function patchLabel(): string
    {
        $build = Patch::where('is_current', true)->value('build_version');

        return trim(self::EXPANSION.' '.(preg_match('/^(\d+\.\d+)/', (string) $build, $m) ? $m[1] : ''));
    }

    /** Every WoW spec that has a page, with its class. */
    private function specsWithPages(array $data): Collection
    {
        $ids = array_map('intval', array_keys($data['specs'] ?? []));

        return Specialization::with('gameClass')
            ->whereIn('external_spec_id', $ids)
            ->whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))
            ->get()
            ->sortBy(fn ($s) => $s->gameClass->name.' '.$s->name)
            ->values();
    }
}
