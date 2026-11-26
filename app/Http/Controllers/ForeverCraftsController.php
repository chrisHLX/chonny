<?php

namespace App\Http\Controllers;

use App\Http\Services\ForeverCraftsService;
use App\Models\PageViewEvent;
use Illuminate\Http\Request;

/**
 * WoW Forever's Crafting Profits (/wow/forever/crafts, 2026-11-26): what each craft earns on one
 * auction house over its last week of shared scans. The sums are ForeverCraftsService's; the recipes
 * are the Auction House addon's own (ForeverRecipes). ?market= picks the house; the default is the
 * one scanned most.
 */
class ForeverCraftsController extends Controller
{
    public function show(Request $request, ForeverCraftsService $crafts)
    {
        $markets = $crafts->markets();
        $wanted = (string) $request->query('market', '');
        $market = collect($markets)->firstWhere('key', $wanted) ?? ($markets[0] ?? null);

        PageViewEvent::log('forever_crafts', slot: $market['key'] ?? null);

        // The region is named only when there is more than one (Forever runs one server per region).
        $regions = collect($markets)->pluck('region')->unique()->count();
        foreach ($markets as &$m) {
            $m['label'] = ($regions > 1 ? $m['region'].' ' : '').($m['faction'] === 'Neutral' ? 'Neutral (goblin AH)' : $m['faction']);
        }
        unset($m);

        return view('forever.crafts', [
            'markets' => $markets,
            'market' => $market ? collect($markets)->firstWhere('key', $market['key']) : null,
            'result' => $market ? $crafts->forMarket($market['key']) : null,
        ]);
    }
}
