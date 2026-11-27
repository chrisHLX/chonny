<?php

namespace App\Http\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * WoW Forever weapons and armour as the game describes them (2026-11-27, Chriso: a page of the best
 * gear a player can get at their level, from the auction house, vendors and drops). The Auction House
 * addon reads it in game (tools/wow-addon/MindCollectorObserver/Gear.lua) and the app sends it here.
 *
 * Why the addon and not the game files: wago.tools' copy of Forever's ItemSparse holds stats for only
 * 7,921 of the client's 19,048 weapon and armour numbers; the server sends the rest when the game asks.
 * The addon asks for every number once a build (/mco items). On Chriso's first sweep (build
 * 1.60.1.71139, 26 Nov) 13,146 came back and 5,902 were refused, which is what in_game false records:
 * numbers from other WoW versions that share the file, and raid gear before its raid opens. Every item
 * that the files also describe came back with the files' stats.
 *
 * Anyone with an app key can send, and the newest sweep of an item wins; each row keeps who sent it.
 * A sweep's refusal never overwrites an item another sweep found in the game.
 */
class ForeverGearService
{
    /**
     * A suffix version's bonus numbers, "12751" or "a.b". Never one digit: app 0.6.39's addon read a
     * link's context field as the bonus, so every auction house suffix came as "1" (Gear.lua bonusKey).
     */
    public const BONUS = '/^\d{2,7}(\.\d{2,7}){0,15}$/';

    /** Positions in an item the addon sends: Gear.lua's readItem. */
    private const NAME = 0;

    private const QUALITY = 1;

    private const ILVL = 2;

    private const REQ = 3;

    private const SLOT = 4;

    private const ITEM_CLASS = 5;

    private const SUBCLASS = 6;

    private const BIND = 7;

    private const SELL = 8;

    private const STATS = 9;

    private const LINES = 10;

    private const DAMAGE = 11;

    /**
     * {build, items: {id: [name, quality, item level, required level, slot, class, subclass, bind, sell
     * price, stats, lines, damage]}, gone: [id], variants: {"id:bonus": [name, stats]}}
     *
     * @return array{status: string, reason?: string, items?: int, gone?: int, variants?: int}
     */
    public function storeItems(User $user, array $data): array
    {
        $build = $data['build'] ?? null;
        if (! is_string($build) || ! preg_match('/^\d+(\.\d+){1,4}$/', $build)) {
            return ['status' => 'rejected', 'reason' => 'No game build.'];
        }
        $now = now();

        $rows = [];
        $bad = 0;
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $id => $r) {
            $row = self::itemRow($id, $r);
            if ($row === null) {
                $bad++;

                continue;
            }
            $rows[] = $row + ['in_game' => true, 'build' => $build, 'user_id' => $user->id, 'updated_at' => $now];
        }
        if ($bad > max(10, count($rows) * 0.05)) {
            return ['status' => 'rejected', 'reason' => 'Too many unreadable items.'];
        }

        $gone = [];
        foreach (is_array($data['gone'] ?? null) ? $data['gone'] : [] as $id) {
            if (is_int($id) && $id > 0 && $id < 10_000_000) {
                $gone[$id] = true;
            }
        }
        foreach ($rows as $row) {
            unset($gone[$row['item_id']]);
        }

        $variants = [];
        foreach (is_array($data['variants'] ?? null) ? $data['variants'] : [] as $key => $v) {
            if (! preg_match('/^(\d{1,7}):(.+)$/', (string) $key, $m) || ! preg_match(self::BONUS, $m[2]) || ! is_array($v)
                || ! is_string($v[0] ?? null) || $v[0] === '' || mb_strlen($v[0]) > 160) {
                continue;
            }
            $stats = self::stats($v[1] ?? null);
            if ($stats === false) {
                continue;
            }
            $variants[] = ['item_id' => (int) $m[1], 'bonus' => $m[2], 'name' => $v[0], 'stats' => $stats,
                'user_id' => $user->id, 'updated_at' => $now];
        }

        DB::transaction(function () use ($rows, $gone, $variants, $build, $user, $now) {
            $fields = ['in_game', 'name', 'quality', 'item_level', 'required_level', 'slot', 'class_id', 'subclass_id',
                'bind', 'sell_price', 'stats', 'lines', 'damage', 'build', 'user_id', 'updated_at'];
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('forever_items')->upsert($chunk, ['item_id'], $fields);
            }
            $found = [];
            foreach (array_chunk(array_keys($gone), 500) as $chunk) {
                $found += DB::table('forever_items')->whereIn('item_id', $chunk)->where('in_game', true)->pluck('item_id', 'item_id')->all();
            }
            $refused = array_map(fn ($id) => ['item_id' => $id, 'in_game' => false, 'build' => $build, 'user_id' => $user->id, 'updated_at' => $now],
                array_values(array_diff(array_keys($gone), $found)));
            foreach (array_chunk($refused, 500) as $chunk) {
                DB::table('forever_items')->upsert($chunk, ['item_id'], ['in_game', 'build', 'user_id', 'updated_at']);
            }
            foreach (array_chunk($variants, 500) as $chunk) {
                DB::table('forever_item_variants')->upsert($chunk, ['item_id', 'bonus'], ['name', 'stats', 'user_id', 'updated_at']);
            }
        });

        return ['status' => 'stored', 'items' => count($rows), 'gone' => count($gone), 'variants' => count($variants)];
    }

    /**
     * {vendors: [{npc, name, zone, sub, mapID, x, y, t, items: [[id, price, stack, stock, costs, requires]]}]}:
     * each vendor's list replaces what was kept for that vendor, unless what was kept is newer.
     *
     * @return array{status: string, vendors: int, items: int}
     */
    public function storeVendors(User $user, array $data): array
    {
        $vendors = 0;
        $items = 0;
        foreach (is_array($data['vendors'] ?? null) ? $data['vendors'] : [] as $v) {
            $npc = $v['npc'] ?? null;
            $name = $v['name'] ?? null;
            $t = $v['t'] ?? null;
            if (! is_int($npc) || $npc < 1 || ! is_string($name) || $name === '' || mb_strlen($name) > 128 || ! is_int($t)
                || $t > now()->addMinutes(10)->timestamp || ! is_array($v['items'] ?? null)) {
                continue;
            }
            $seen = now()->setTimestamp($t);
            $kept = DB::table('forever_vendor_items')->where('npc_id', $npc)->max('seen_at');
            if ($kept && $seen->lte($kept)) {
                continue;
            }
            $where = [
                'npc_id' => $npc, 'npc_name' => $name,
                'zone' => self::text($v['zone'] ?? null, 128), 'subzone' => self::text($v['sub'] ?? null, 128),
                'map_id' => is_int($v['mapID'] ?? null) ? $v['mapID'] : null,
                'x' => is_numeric($v['x'] ?? null) && $v['x'] >= 0 && $v['x'] <= 100 ? round($v['x'], 1) : null,
                'y' => is_numeric($v['y'] ?? null) && $v['y'] >= 0 && $v['y'] <= 100 ? round($v['y'], 1) : null,
                'user_id' => $user->id, 'seen_at' => $seen,
            ];
            $rows = [];
            foreach ($v['items'] as $i) {
                if (! is_array($i) || ! is_int($i[0] ?? null) || $i[0] < 1 || ! is_int($i[1] ?? null) || $i[1] < 0) {
                    continue;
                }
                $costs = [];
                foreach (is_array($i[4] ?? null) ? $i[4] : [] as $c) {
                    if (is_array($c) && is_int($c[0] ?? null) && (is_int($c[1] ?? null) || (is_string($c[1] ?? null) && mb_strlen($c[1]) <= 64))) {
                        $costs[] = [$c[0], $c[1]];
                    }
                }
                $requires = array_values(array_filter(is_array($i[5] ?? null) ? $i[5] : [], fn ($l) => is_string($l) && mb_strlen($l) <= 200));
                $rows[$i[0]] = $where + [
                    'item_id' => $i[0], 'price' => $i[1],
                    'stack' => is_int($i[2] ?? null) && $i[2] > 0 ? min($i[2], 65535) : 1,
                    'stock' => is_int($i[3] ?? null) && $i[3] >= 0 ? min($i[3], 65535) : null,
                    'costs' => $costs ? json_encode($costs) : null,
                    'requires' => $requires ? json_encode($requires) : null,
                ];
            }
            DB::transaction(function () use ($npc, $rows) {
                DB::table('forever_vendor_items')->where('npc_id', $npc)->delete();
                foreach (array_chunk(array_values($rows), 200) as $chunk) {
                    DB::table('forever_vendor_items')->insert($chunk);
                }
            });
            $vendors++;
            $items += count($rows);
        }

        return ['status' => 'stored', 'vendors' => $vendors, 'items' => $items];
    }

    /** One item's row, or null when it isn't readable. */
    private static function itemRow(int|string $id, mixed $r): ?array
    {
        if (! is_numeric($id) || (int) $id < 1 || (int) $id >= 10_000_000 || ! is_array($r)) {
            return null;
        }
        $name = $r[self::NAME] ?? null;
        if (! is_string($name) || $name === '' || mb_strlen($name) > 128) {
            return null;
        }
        foreach ([self::QUALITY, self::ILVL, self::REQ, self::ITEM_CLASS, self::SUBCLASS, self::BIND, self::SELL] as $k) {
            if (! is_int($r[$k] ?? null) || $r[$k] < 0 || $r[$k] > 100_000_000) {
                return null;
            }
        }
        $slot = $r[self::SLOT] ?? null;
        if (! is_string($slot) || ! preg_match('/^[A-Z0-9_]{0,32}$/', $slot)) {
            return null;
        }
        $stats = self::stats($r[self::STATS] ?? null);
        if ($stats === false) {
            return null;
        }
        $lines = $r[self::LINES] ?? null;
        $lines = is_array($lines) ? array_values(array_filter($lines, fn ($l) => is_string($l) && mb_strlen($l) <= 400)) : [];
        $damage = $r[self::DAMAGE] ?? null;
        $damage = is_array($damage) && count($damage) >= 2 && is_numeric($damage[0]) && is_numeric($damage[1])
            ? json_encode([(int) $damage[0], (int) $damage[1], is_numeric($damage[2] ?? null) ? round((float) $damage[2], 2) : null])
            : null;

        return [
            'item_id' => (int) $id, 'name' => $name, 'quality' => $r[self::QUALITY], 'item_level' => $r[self::ILVL],
            'required_level' => $r[self::REQ], 'slot' => $slot, 'class_id' => $r[self::ITEM_CLASS], 'subclass_id' => $r[self::SUBCLASS],
            'bind' => $r[self::BIND], 'sell_price' => $r[self::SELL], 'stats' => $stats,
            'lines' => $lines ? json_encode($lines) : null, 'damage' => $damage,
        ];
    }

    /** A stats map as JSON, null when empty, false when unreadable: {"STRENGTH": 18, ...}. */
    private static function stats(mixed $s): string|false|null
    {
        if ($s === null || $s === []) {
            return null;
        }
        if (! is_array($s) || count($s) > 64) {
            return false;
        }
        $out = [];
        foreach ($s as $k => $v) {
            if (! is_string($k) || ! preg_match('/^[A-Z0-9_]{1,48}$/', $k) || ! is_numeric($v)) {
                return false;
            }
            $out[$k] = is_float($v) ? round($v, 2) : $v;
        }

        return json_encode($out);
    }

    private static function text(mixed $s, int $max): ?string
    {
        return is_string($s) && $s !== '' ? mb_substr($s, 0, $max) : null;
    }
}
