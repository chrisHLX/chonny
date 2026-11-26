<?php

namespace App\Http\Services;

use App\Models\ForeverScan;
use App\Support\ForeverRecipes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What each WoW Forever craft earns on one auction house over its last week of shared scans
 * (/wow/forever/crafts, 2026-11-26; Chriso: "the website could display the most profitable crafts for
 * the week").
 *
 * The sum is the addon's own (ui.lua profitsFor): what the product sells for, less the auction
 * house's 5% cut, times how many one craft makes, minus what the materials cost to buy. A material a
 * vendor sells is priced at the vendor's price when that's lower. Unlike the addon, which uses each
 * item's newest reading, a price here is the middle (median) of the cheapest listing across the
 * week's readings, so one stray listing or one bad scan can't make a craft look good. A material you
 * could make yourself is still priced at what it costs to buy: the site doesn't know your professions.
 *
 * The week is the 7 days up to the market's newest scan, not up to today, so a market nobody has
 * scanned lately still shows its last week, with its dates. Cached until the market's next scan.
 */
class ForeverCraftsService
{
    public const WINDOW_DAYS = 7;

    /** The auction house keeps this share of every sale. */
    public const AH_CUT = 0.05;

    /**
     * Every market with a scan, most scanned first: [key, region, faction, scans, newest]. A market is
     * "REGION/Faction" (ForeverMarketService::market).
     *
     * @return list<array{key: string, region: string, faction: string, scans: int, newest: Carbon}>
     */
    public function markets(): array
    {
        return ForeverScan::query()
            ->selectRaw('market, COUNT(*) as scans, MAX(scanned_at) as newest')
            ->groupBy('market')
            ->orderByDesc('scans')
            ->get()
            ->map(function ($row) {
                [$region, $faction] = array_pad(explode('/', $row->market, 2), 2, '');

                return [
                    'key' => $row->market, 'region' => $region, 'faction' => $faction,
                    'scans' => (int) $row->scans, 'newest' => Carbon::parse($row->newest),
                ];
            })
            ->all();
    }

    /**
     * One market's crafts, most profit a craft first.
     *
     * @return array{market: string, from: ?Carbon, to: ?Carbon, scans: int, players: int, hours: int,
     *               crafts: list<array>, unpriced: int}
     */
    public function forMarket(string $market): array
    {
        $lastScan = (int) ForeverScan::where('market', $market)->max('id');
        $key = "forever-crafts:{$market}:{$lastScan}:".ForeverRecipes::version();

        return Cache::remember($key, now()->addDays(2), fn () => $this->build($market));
    }

    private function build(string $market): array
    {
        $newest = DB::table('forever_prices')->where('market', $market)->max('scanned_at');
        if ($newest === null) {
            return ['market' => $market, 'from' => null, 'to' => null, 'scans' => 0, 'players' => 0, 'hours' => 0, 'crafts' => [], 'unpriced' => 0];
        }
        $to = Carbon::parse($newest);
        $from = $to->copy()->subDays(self::WINDOW_DAYS);

        // Only the items some recipe makes or uses.
        $recipes = ForeverRecipes::recipes();
        $wanted = [];
        foreach ($recipes as $r) {
            $wanted[$r['out']] = true;
            foreach ($r['reagents'] as [$item]) {
                $wanted[$item] = true;
            }
        }

        $prices = $listed = [];
        $since = $from->copy()->startOfHour();
        foreach (array_chunk(array_keys($wanted), 1000) as $ids) {
            foreach (DB::table('forever_prices')->where('market', $market)->where('hour', '>=', $since)->whereIn('item_id', $ids)
                ->select(['item_id', 'min_unit', 'quantity'])->cursor() as $row) {
                $prices[$row->item_id][] = (int) $row->min_unit;
                $listed[$row->item_id][] = (int) $row->quantity;
            }
        }
        $hours = DB::table('forever_prices')->where('market', $market)->where('hour', '>=', $since)->distinct()->count('hour');

        $scans = ForeverScan::where('market', $market)->whereBetween('scanned_at', [$from, $to]);
        $players = (clone $scans)->distinct()->count('user_id');

        $crafts = [];
        $unpriced = 0;
        foreach ($recipes as $r) {
            if (! isset($prices[$r['out']])) {
                continue; // nothing listed to sell it against: bind on pickup, or not on this house
            }
            $cost = 0;
            $mats = [];
            foreach ($r['reagents'] as [$item, $count]) {
                $ah = isset($prices[$item]) ? self::median($prices[$item]) : null;
                $vendor = ForeverRecipes::vendorPrice($item);
                $unit = $vendor !== null && ($ah === null || $vendor <= $ah) ? $vendor : $ah;
                if ($unit === null) {
                    $cost = null;
                    break;
                }
                $cost += $unit * $count;
                $mats[] = [
                    'name' => ForeverRecipes::name($item) ?? "Item {$item}", 'count' => $count, 'unit' => (int) round($unit),
                    'vendor' => $unit === $vendor,
                ];
            }
            if ($cost === null) {
                $unpriced++;

                continue;
            }
            $sells = self::median($prices[$r['out']]);
            $earns = $sells * (1 - self::AH_CUT) * $r['yield'];
            $profit = (int) round($earns - $cost);
            $product = ForeverRecipes::name($r['out']) ?? $r['name'];
            $crafts[] = [
                'name' => $product,
                // the recipe's own name when it isn't the product's: "Transmute: Arcanite" makes Arcanite Bar
                'recipe' => $r['name'] !== $product ? $r['name'] : null,
                'cooldown' => $r['cooldown'],
                'cooldownGroup' => $r['cooldownGroup'],
                'profession' => ForeverRecipes::PROFESSIONS[$r['profession']] ?? 'Other',
                'skill' => $r['skill'],
                'trainer' => $r['recipeItem'] === 0,
                'yield' => $r['yield'],
                'cost' => (int) round($cost),
                'sells' => (int) round($sells),
                'earns' => (int) round($earns),
                'profit' => $profit,
                'margin' => $cost > 0 ? $profit / $cost : null,
                'listed' => (int) round(self::median($listed[$r['out']])),
                'seen' => count($prices[$r['out']]),
                'mats' => $mats,
            ];
        }
        usort($crafts, fn ($a, $b) => [$b['profit'], $a['name']] <=> [$a['profit'], $b['name']]);

        return [
            'market' => $market, 'from' => $from, 'to' => $to, 'scans' => $scans->count(), 'players' => $players,
            'hours' => $hours, 'crafts' => $crafts, 'unpriced' => $unpriced,
        ];
    }

    /** The middle value; for an even count, the mean of the middle two. ForeverMarketService uses it too. */
    public static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
