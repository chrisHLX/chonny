<?php

namespace App\Http\Controllers;

use App\Http\Services\ForeverCraftsService;
use App\Http\Services\ForeverGearFinder;
use App\Models\PageViewEvent;
use Illuminate\Http\Request;

/**
 * WoW Forever's Gear Finder (/wow/forever/gear, 2026-11-27): the best weapons and armour for a level, by
 * the stats the player picks (ForeverGearFinder). The form is a plain GET, so a search is a link.
 */
class ForeverGearFinderController extends Controller
{
    public function show(Request $request, ForeverGearFinder $finder, ForeverCraftsService $crafts)
    {
        // `f` marks a submitted form: without it the armour and weapon boxes start all ticked.
        $sent = $request->has('f');
        $ints = fn (string $name, array $allowed) => array_values(array_intersect(
            array_map('intval', (array) $request->query($name, [])), array_keys($allowed)));

        $stats = [];
        foreach ((array) $request->query('pick', []) as $key) {
            if (is_string($key) && isset(ForeverGearFinder::STATS[$key])) {
                $w = $request->query('w')[$key] ?? 1;
                $stats[$key] = is_numeric($w) ? max(0.0, min(100.0, (float) $w)) : 1.0;
            }
        }
        $classes = $finder->classes();
        $class = $request->query('class');
        $slot = $request->query('slot');

        $markets = $crafts->markets();
        $market = collect($markets)->firstWhere('key', (string) $request->query('market', '')) ?? ($markets[0] ?? null);

        $f = [
            'level' => max(1, min(60, (int) $request->query('level', 60) ?: 60)),
            'class' => is_string($class) && in_array($class, $classes, true) ? $class : null,
            'armour' => $sent ? $ints('armour', ForeverGearFinder::ARMOUR) : array_keys(ForeverGearFinder::ARMOUR),
            'weapons' => $sent ? $ints('weapons', ForeverGearFinder::WEAPONS) : array_keys(ForeverGearFinder::WEAPONS),
            'quality' => max(0, min(4, (int) $request->query('quality', 2))),
            'stats' => $stats,
            'slot' => is_string($slot) && isset(ForeverGearFinder::SLOTS[$slot]) ? $slot : null,
            'far' => $request->boolean('far'),
            'market' => $market['key'] ?? null,
        ];

        PageViewEvent::log('forever_gear', slot: $stats ? implode(',', array_keys($stats)) : null);

        return view('forever.gear', [
            'f' => $f,
            'classes' => $classes,
            'markets' => $markets,
            'market' => $market,
            'results' => $finder->find($f),
            'itemCount' => count($finder->items()),
        ]);
    }
}
