<?php

namespace App\Http\Services;

use App\Models\ForeverScan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WoW Forever auction prices pooled from every app user who shares their scans (2026-11-12, Chriso:
 * "everyone's scan data saved on the server so it can be accessed by everyone who has the app").
 *
 * The Auction House addon (was Observer) scans the whole auction house (C_AuctionHouse.ReplicateItems) and keeps, per item,
 * the cheapest unit price, how many are listed and how many sit in each time-left band. The app sends
 * each new scan (store()), and writes the pooled view back into the addon (pooled()), so one player's
 * Professions, Market and "at least N sold" read from everyone's scans.
 *
 * Only the scan is sent: never what the player looked at, their ledger, their questions or where they
 * were. A market is "REGION/Faction" (US/Horde, US/Alliance, US/Neutral for the goblin house).
 *
 * The realm is not part of it (2026-11-26). Forever is one server per region, with a second name on
 * every character (Crypto-Shot) that puts it on a shard, and one auction house per region and faction
 * (Chriso). UnitFullName's realm part is that second name ("Cuz"), not a realm. Measured: a scan
 * the client called realm CAMWOWAU1Realm2PvP and one called CAMWOWUS2Realm2PvP, 12 minutes apart, held
 * 156,690 and 157,617 auctions, and 89% of the 5,792 items in both had the same cheapest price (two
 * scans of one house 1.5 hours apart: 65%). From 22 Nov the client reports no realm at all
 * (GetNormalizedRealmName and GetRealmName both "", GetRealmID 0), and with the realm in the key every
 * scan was refused. Region and faction still come from the game; the realm the app sends is ignored.
 */
class ForeverMarketService
{
    /** Readings older than this are dropped from forever_prices (the scans stay on disk). */
    public const KEEP_DAYS = 30;

    /** What the app downloads covers this many days. */
    public const POOL_DAYS = 7;

    /** Bump when an item's fields in pooled() change, so a cached view of the old shape isn't served (rule 20). */
    private const POOL_SHAPE = 2;

    /** A scan more than this many times the market's biggest recent one is not believed. */
    private const MAX_GROWTH = 3;

    public static function market(string $region, string $faction): string
    {
        return strtoupper($region).'/'.ucfirst(strtolower($faction));
    }

    /**
     * Checks and keeps one scan: {region, faction, t, n, items: [[id, min, listed, e0, e1, e2, e3], ...]} (a realm
     * the app sends is ignored)
     * (the e's optional: how many of `listed` have under 30m, 30m-2h, 2-12h, over 12h left), and optionally
     * variants: [[id, bonus, min, listed], ...], a weapon or armour piece's suffix versions by the bonus
     * numbers on their links ("12751", or "a.b"), from app 0.6.39 (2026-11-27, ForeverGearService).
     *
     * @return array{status: string, reason?: string, items?: int}
     */
    public function store(User $user, array $scan): array
    {
        $market = self::market($scan['region'], $scan['faction']);
        $t = (int) $scan['t'];
        if ($t > now()->addMinutes(10)->timestamp || $t < now()->subDays(14)->timestamp) {
            return ['status' => 'rejected', 'reason' => 'The scan is too old, or from the future.'];
        }
        $at = Carbon::createFromTimestamp($t);

        $items = [];
        foreach ($scan['items'] as $row) {
            if (! is_array($row) || count($row) < 3) {
                continue;
            }
            [$id, $min, $listed] = $row;
            if (! is_int($id) || $id < 1 || $id > 10_000_000 || ! is_numeric($min) || $min < 1 || $min > 100_000_000_000
                || ! is_int($listed) || $listed < 1 || $listed > 10_000_000) {
                continue;
            }
            $expiring = null;
            if (count($row) >= 7) {
                $e = array_slice($row, 3, 4);
                if (array_filter($e, fn ($x) => ! is_int($x) || $x < 0) === []) {
                    $expiring = implode(',', $e);
                }
            }
            $items[$id] = [(int) round($min), $listed, $expiring];
        }
        if ($items === [] || count($items) < count($scan['items']) * 0.95) {
            return ['status' => 'rejected', 'reason' => 'The scan has too many unreadable items.'];
        }
        $variants = [];
        foreach (is_array($scan['variants'] ?? null) ? $scan['variants'] : [] as $row) {
            [$id, $bonus, $min, $listed] = (is_array($row) ? $row : []) + [null, null, null, null];
            if (is_int($id) && isset($items[$id]) && is_string($bonus) && preg_match('/^\d{1,7}(\.\d{1,7}){0,15}$/', $bonus)
                && is_numeric($min) && $min >= 1 && $min <= 100_000_000_000 && is_int($listed) && $listed >= 1 && $listed <= 10_000_000) {
                $variants[$id.':'.$bonus] = [$id, $bonus, (int) round($min), $listed];
            }
        }

        $auctions = (int) $scan['n'];
        $biggest = ForeverScan::where('market', $market)->where('scanned_at', '>', now()->subDays(3))->max('auctions');
        if ($auctions < count($items) || ($biggest >= 1000 && $auctions > $biggest * self::MAX_GROWTH)) {
            return ['status' => 'rejected', 'reason' => 'The scan is not the size of this market.'];
        }

        if (ForeverScan::where(['user_id' => $user->id, 'market' => $market, 'scanned_at' => $at])->exists()) {
            return ['status' => 'duplicate'];
        }

        $path = 'forever/scans/'.preg_replace('/[^A-Za-z0-9-]+/', '-', $market).'/'.$at->format('Y-m-d').'/'.$user->id.'-'.$t.'.json.gz';
        $full = storage_path('app/'.$path);
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        file_put_contents($full, gzencode(json_encode($scan), 9));

        DB::transaction(function () use ($user, $market, $at, $auctions, $items, $variants, $path) {
            ForeverScan::create([
                'user_id' => $user->id, 'market' => $market, 'scanned_at' => $at,
                'auctions' => $auctions, 'items' => count($items), 'path' => $path,
            ]);
            $this->merge($market, $at, $auctions, $items);
            $this->mergeVariants($market, $at, $auctions, $variants);
            DB::table('forever_variant_prices')->where('market', $market)->where('hour', '<', now()->subDays(self::KEEP_DAYS))->delete();
            DB::table('forever_prices')->where('market', $market)->where('hour', '<', now()->subDays(self::KEEP_DAYS))->delete();
        });

        return ['status' => 'stored', 'items' => count($items)];
    }

    /**
     * Into the market's reading for that hour. Several players' scans of one hour make one reading:
     * the most complete scan (most auctions) wins, a later one breaks a tie, and `sources` counts them.
     */
    private function merge(string $market, Carbon $at, int $auctions, array $items): void
    {
        $hour = $at->copy()->startOfHour();
        $existing = DB::table('forever_prices')->where('market', $market)->where('hour', $hour)
            ->get(['item_id', 'scan_auctions', 'scanned_at'])->keyBy('item_id');

        $wins = [];
        $seen = [];
        foreach ($items as $id => [$min, $listed, $expiring]) {
            $ex = $existing->get($id);
            if ($ex && ($auctions < $ex->scan_auctions || ($auctions === (int) $ex->scan_auctions && $at->lte(Carbon::parse($ex->scanned_at))))) {
                $seen[] = $id;

                continue;
            }
            $wins[] = [
                'market' => $market, 'item_id' => $id, 'hour' => $hour, 'scanned_at' => $at,
                'min_unit' => $min, 'quantity' => $listed, 'expiring' => $expiring,
                'scan_auctions' => $auctions, 'sources' => 1,
            ];
        }
        foreach (array_chunk($wins, 500) as $chunk) {
            DB::table('forever_prices')->upsert($chunk, ['market', 'item_id', 'hour'], [
                'scanned_at', 'min_unit', 'quantity', 'expiring', 'scan_auctions',
                'sources' => DB::raw('sources + 1'),
            ]);
        }
        foreach (array_chunk($seen, 500) as $chunk) {
            DB::table('forever_prices')->where('market', $market)->where('hour', $hour)->whereIn('item_id', $chunk)->increment('sources');
        }
    }

    /** Suffix versions' readings, merged by the same rule as merge(): the scan with more auctions wins the hour. */
    private function mergeVariants(string $market, Carbon $at, int $auctions, array $variants): void
    {
        if ($variants === []) {
            return;
        }
        $hour = $at->copy()->startOfHour();
        $existing = DB::table('forever_variant_prices')->where('market', $market)->where('hour', $hour)
            ->get(['item_id', 'bonus', 'scan_auctions', 'scanned_at'])->keyBy(fn ($r) => $r->item_id.':'.$r->bonus);
        $wins = [];
        foreach ($variants as $key => [$id, $bonus, $min, $listed]) {
            $ex = $existing->get($key);
            if ($ex && ($auctions < $ex->scan_auctions || ($auctions === (int) $ex->scan_auctions && $at->lte(Carbon::parse($ex->scanned_at))))) {
                continue;
            }
            $wins[] = ['market' => $market, 'item_id' => $id, 'bonus' => $bonus, 'hour' => $hour, 'scanned_at' => $at,
                'min_unit' => $min, 'quantity' => $listed, 'scan_auctions' => $auctions];
        }
        foreach (array_chunk($wins, 500) as $chunk) {
            DB::table('forever_variant_prices')->upsert($chunk, ['market', 'item_id', 'bonus', 'hour'], ['scanned_at', 'min_unit', 'quantity', 'scan_auctions']);
        }
    }

    /**
     * The pooled view of a market for the app to hand the addon: per item the newest reading, the
     * one before it (with its time-left bands), the lowest price in POOL_DAYS days, how many scans saw
     * the newest, the usual price (the median of the cheapest listing over those days' hourly readings,
     * as ForeverCraftsService prices a craft) and how many readings it is the median of. They come last
     * so an older addon, which reads the first twelve, is unaffected (2026-11-26, Chriso made Elixir of the Mongoose on a "76s a
     * craft" that came from one hour's dear listing). Cached until the market's next scan.
     *
     * @return array{status: string, market: string, t: ?int, players: int, items: array<int, array>}
     */
    public function pooled(string $market): array
    {
        $lastScan = (int) ForeverScan::where('market', $market)->max('id');
        if ($lastScan === 0) {
            // an object even when empty, so the app always reads `items` the same way
            return ['status' => 'ok', 'market' => $market, 't' => null, 'players' => 0, 'items' => new \stdClass];
        }

        return Cache::remember('forever-market:v'.self::POOL_SHAPE.":{$market}:{$lastScan}", now()->addDay(), function () use ($market) {
            $since = now()->subDays(self::POOL_DAYS);
            $items = [];
            $newest = 0;
            $run = function (?int $id, array $rows) use (&$items, &$newest) {
                if ($id === null || $rows === []) {
                    return;
                }
                $now = $rows[count($rows) - 1];
                $was = count($rows) > 1 ? $rows[count($rows) - 2] : null;
                $e = $was && $was->expiring ? array_map('intval', explode(',', $was->expiring)) : [null, null, null, null];
                $t = Carbon::parse($now->scanned_at)->timestamp;
                $newest = max($newest, $t);
                $items[$id] = [
                    $t, (int) $now->min_unit, (int) $now->quantity,
                    $was ? Carbon::parse($was->scanned_at)->timestamp : 0, $was ? (int) $was->min_unit : 0, $was ? (int) $was->quantity : 0,
                    ...$e,
                    (int) min(array_map(fn ($r) => (int) $r->min_unit, $rows)),
                    (int) $now->sources,
                    (int) round(ForeverCraftsService::median(array_map(fn ($r) => (int) $r->min_unit, $rows))),
                    count($rows),
                ];
            };

            $current = null;
            $rows = [];
            foreach (DB::table('forever_prices')->where('market', $market)->where('hour', '>=', $since)
                ->select(['item_id', 'hour', 'scanned_at', 'min_unit', 'quantity', 'expiring', 'sources'])
                ->orderBy('item_id')->orderBy('hour')->cursor() as $row) {
                if ((int) $row->item_id !== $current) {
                    $run($current, $rows);
                    $current = (int) $row->item_id;
                    $rows = [];
                }
                $rows[] = $row;
            }
            $run($current, $rows);

            $players = ForeverScan::where('market', $market)->where('scanned_at', '>=', $since)->distinct()->count('user_id');

            return ['status' => 'ok', 'market' => $market, 't' => $newest ?: null, 'players' => $players, 'items' => $items ?: new \stdClass];
        });
    }
}
