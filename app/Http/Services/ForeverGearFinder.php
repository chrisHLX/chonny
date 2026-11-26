<?php

namespace App\Http\Services;

use App\Support\ForeverRecipes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WoW Forever's Gear Finder (/wow/forever/gear, 2026-11-27): the best weapons and armour for a level, by
 * the stats the player picks (Chriso: "people put in what stats they want ... we just filter gear and
 * level"). No stat priorities of our own. The items are the addon's sweep (forever_items, ForeverGearService);
 * suffix versions ("of the Bandit") come in as their own items once a scan has seen them.
 *
 * The score is the sum of the picked stats, each times the weight the player gives it (1 unless they
 * change it). Hit and crit count as the percentage the tooltip shows: 10 hit rating and 14 crit rating
 * to 1% at any level (Lionheart Helm, read on a level 1 and a level 41 character, 2026-11-26).
 *
 * Required level is not availability: many bind-on-pickup drops have none (Darkmantle Cap, a level-60
 * dungeon set piece, has no requirement). So items with no requirement more than NO_REQ_SPAN item
 * levels above the player's level are left out unless the player asks for them.
 */
class ForeverGearFinder
{
    public const NO_REQ_SPAN = 10;

    /** Where an item goes, in the order a character sheet lists them. Shirts and tabards are left out. */
    public const SLOTS = [
        'Head' => ['INVTYPE_HEAD'], 'Neck' => ['INVTYPE_NECK'], 'Shoulder' => ['INVTYPE_SHOULDER'], 'Back' => ['INVTYPE_CLOAK'],
        'Chest' => ['INVTYPE_CHEST', 'INVTYPE_ROBE'], 'Wrist' => ['INVTYPE_WRIST'], 'Hands' => ['INVTYPE_HAND'],
        'Waist' => ['INVTYPE_WAIST'], 'Legs' => ['INVTYPE_LEGS'], 'Feet' => ['INVTYPE_FEET'], 'Finger' => ['INVTYPE_FINGER'],
        'Trinket' => ['INVTYPE_TRINKET'], 'One-hand' => ['INVTYPE_WEAPON', 'INVTYPE_WEAPONMAINHAND'],
        'Two-hand' => ['INVTYPE_2HWEAPON'], 'Off hand' => ['INVTYPE_WEAPONOFFHAND', 'INVTYPE_SHIELD', 'INVTYPE_HOLDABLE'],
        'Ranged' => ['INVTYPE_RANGED', 'INVTYPE_RANGEDRIGHT', 'INVTYPE_THROWN', 'INVTYPE_RELIC'],
    ];

    /**
     * The stats a player can pick: a label and the game's stat keys that add up to it, each with a factor.
     * "+Attack Power" counts for melee and ranged; RANGED_ATTACK_POWER for ranged only.
     */
    public const STATS = [
        'str' => ['Strength', [['STRENGTH', 1]]],
        'agi' => ['Agility', [['AGILITY', 1]]],
        'sta' => ['Stamina', [['STAMINA', 1]]],
        'int' => ['Intellect', [['INTELLECT', 1]]],
        'spi' => ['Spirit', [['SPIRIT', 1]]],
        'armor' => ['Armor', [['RESISTANCE0_NAME', 1]]],
        'ap' => ['Attack Power', [['ATTACK_POWER', 1], ['MELEE_ATTACK_POWER', 1]]],
        'rap' => ['Ranged Attack Power', [['ATTACK_POWER', 1], ['RANGED_ATTACK_POWER', 1]]],
        'crit' => ['Crit %', [['CRIT_RATING', 1 / 14]]],
        'hit' => ['Hit %', [['HIT_RATING', 1 / 10]]],
        'sp' => ['Spell Damage', [['SPELL_POWER', 1], ['SPELL_DAMAGE_DONE', 1]]],
        'heal' => ['Healing', [['SPELL_POWER', 1], ['SPELL_HEALING_DONE', 1]]],
        'mp5' => ['Mana per 5 sec', [['MANA_REGENERATION', 1]]],
        'def' => ['Defense', [['DEFENSE_SKILL_RATING', 1]]],
        'dodge' => ['Dodge rating', [['DODGE_RATING', 1]]],
        'parry' => ['Parry rating', [['PARRY_RATING', 1]]],
        'block' => ['Block rating', [['BLOCK_RATING', 1]]],
        'dps' => ['Weapon DPS', [['DAMAGE_PER_SECOND', 1]]],
    ];

    /** The game's armour and weapon types (GetItemInfo's subclass), checked against the names it gave for the probe's items. */
    public const ARMOUR = [1 => 'Cloth', 2 => 'Leather', 3 => 'Mail', 4 => 'Plate'];

    public const WEAPONS = [0 => 'One-Handed Axes', 1 => 'Two-Handed Axes', 2 => 'Bows', 3 => 'Guns', 4 => 'One-Handed Maces',
        5 => 'Two-Handed Maces', 6 => 'Polearms', 7 => 'One-Handed Swords', 8 => 'Two-Handed Swords', 10 => 'Staves',
        13 => 'Fist Weapons', 15 => 'Daggers', 16 => 'Thrown', 18 => 'Crossbows', 19 => 'Wands'];

    private const PER_SLOT = 5;

    private const ONE_SLOT = 50;

    /** Base items with a suffix version known, for hasVariants(). */
    private ?array $withVariants = null;

    /**
     * Every wearable item in the game, as compact rows, cached until the next sweep or suffix version arrives.
     *
     * @return list<array{id:int, bonus:?string, name:string, q:int, ilvl:int, req:int, slot:string, class:int, sub:int, bind:int, stats:array, lines:list<string>}>
     */
    public function items(): array
    {
        $key = 'forever-gear-items:'.DB::table('forever_items')->max('updated_at').':'.DB::table('forever_items')->count()
            .':'.DB::table('forever_item_variants')->max('updated_at').':'.DB::table('forever_item_variants')->count();

        return Cache::remember($key, now()->addDay(), function () {
            $slots = array_merge(...array_values(self::SLOTS));
            $rows = [];
            $base = [];
            foreach (DB::table('forever_items')->where('in_game', true)->whereIn('slot', $slots)
                ->get(['item_id', 'name', 'quality', 'item_level', 'required_level', 'slot', 'class_id', 'subclass_id', 'bind', 'stats', 'lines']) as $r) {
                $row = [
                    'id' => (int) $r->item_id, 'bonus' => null, 'name' => $r->name, 'q' => (int) $r->quality, 'ilvl' => (int) $r->item_level,
                    'req' => (int) $r->required_level, 'slot' => $r->slot, 'class' => (int) $r->class_id, 'sub' => (int) $r->subclass_id,
                    'bind' => (int) $r->bind, 'stats' => json_decode($r->stats ?? '[]', true) ?: [], 'lines' => json_decode($r->lines ?? '[]', true) ?: [],
                ];
                $base[$row['id']] = $row;
                $rows[] = $row;
            }
            foreach (DB::table('forever_item_variants')->get(['item_id', 'bonus', 'name', 'stats']) as $v) {
                if (isset($base[(int) $v->item_id])) {
                    $rows[] = ['bonus' => $v->bonus, 'name' => $v->name, 'stats' => json_decode($v->stats ?? '[]', true) ?: []] + $base[(int) $v->item_id];
                }
            }

            return $rows;
        });
    }

    /** The class names items are limited to ("Classes: Hunter, Warrior"), from the items themselves. */
    public function classes(): array
    {
        return Cache::remember('forever-gear-classes:'.DB::table('forever_items')->max('updated_at'), now()->addDay(), function () {
            $names = [];
            foreach ($this->items() as $i) {
                foreach (self::classLimit($i) ?? [] as $c) {
                    $names[$c] = true;
                }
            }
            ksort($names);

            return array_keys($names);
        });
    }

    /**
     * The best items for the filters, per slot (or one slot), with where to get each.
     *
     * @param  array{level:int, class:?string, armour:list<int>, weapons:list<int>, quality:int, stats:array<string, float>, slot:?string, far:bool, market:?string}  $f
     * @return array<string, list<array>> slot => items, best first
     */
    public function find(array $f): array
    {
        if ($f['stats'] === []) {
            return [];
        }
        $slotOf = [];
        foreach (self::SLOTS as $name => $types) {
            foreach ($types as $t) {
                $slotOf[$t] = $name;
            }
        }
        $found = [];
        foreach ($this->items() as $i) {
            $slot = $slotOf[$i['slot']] ?? null;
            if ($slot === null || ($f['slot'] && $f['slot'] !== $slot) || $i['q'] < $f['quality'] || $i['req'] > $f['level']) {
                continue;
            }
            if ($i['req'] === 0 && ! $f['far'] && $i['ilvl'] > $f['level'] + self::NO_REQ_SPAN) {
                continue;
            }
            if ($i['class'] === 4 && isset(self::ARMOUR[$i['sub']]) && $i['slot'] !== 'INVTYPE_CLOAK' && ! in_array($i['sub'], $f['armour'], true)) {
                continue;
            }
            if ($i['class'] === 2 && ! in_array($i['sub'], $f['weapons'], true)) {
                continue;
            }
            $limit = self::classLimit($i);
            if ($f['class'] && $limit !== null && ! in_array($f['class'], $limit, true)) {
                continue;
            }
            $values = [];
            $score = 0.0;
            foreach ($f['stats'] as $key => $weight) {
                $v = 0.0;
                foreach (self::STATS[$key][1] as [$stat, $factor]) {
                    $v += ($i['stats'][$stat] ?? 0) * $factor;
                }
                $values[$key] = $v;
                $score += $v * $weight;
            }
            if ($score <= 0) {
                continue;
            }
            $found[$slot][] = $i + ['values' => $values, 'score' => $score];
        }

        $out = [];
        foreach (self::SLOTS as $slot => $_) {
            if (! isset($found[$slot])) {
                continue;
            }
            usort($found[$slot], fn ($a, $b) => [$b['score'], $b['ilvl'], $a['name']] <=> [$a['score'], $a['ilvl'], $b['name']]);
            $out[$slot] = array_slice($found[$slot], 0, $f['slot'] ? self::ONE_SLOT : self::PER_SLOT);
        }

        return $this->withSources($out, $f['market']);
    }

    /** The classes an item is limited to, or null when any class can use it. */
    private static function classLimit(array $i): ?array
    {
        foreach ($i['lines'] as $l) {
            if (str_starts_with($l, 'Classes: ')) {
                return array_map('trim', explode(',', substr($l, 9)));
            }
        }

        return null;
    }

    /** Each found item's auction price (this week, on the market), vendors and recipe. */
    private function withSources(array $bySlot, ?string $market): array
    {
        $ids = [];
        foreach ($bySlot as $items) {
            foreach ($items as $i) {
                $ids[$i['id']] = true;
            }
        }
        $ids = array_keys($ids);
        $since = now()->subDays(ForeverMarketService::POOL_DAYS);

        $ah = [];
        if ($market && $ids) {
            foreach (DB::table('forever_prices')->where('market', $market)->whereIn('item_id', $ids)->where('hour', '>=', $since)
                ->orderBy('hour')->get(['item_id', 'min_unit', 'scanned_at']) as $p) {
                $ah[(int) $p->item_id][] = $p;
            }
            foreach (DB::table('forever_variant_prices')->where('market', $market)->whereIn('item_id', $ids)->where('hour', '>=', $since)
                ->orderBy('hour')->get(['item_id', 'bonus', 'min_unit', 'scanned_at']) as $p) {
                $ah[$p->item_id.':'.$p->bonus][] = $p;
            }
        }
        $vendors = DB::table('forever_vendor_items')->whereIn('item_id', $ids ?: [0])->orderBy('npc_name')
            ->get(['item_id', 'npc_name', 'zone', 'price', 'costs', 'requires'])->groupBy('item_id');
        $made = [];
        foreach (ForeverRecipes::recipes() as $r) {
            $made[$r['out']] ??= (ForeverRecipes::PROFESSIONS[$r['profession']] ?? 'a profession').' '.$r['skill'];
        }

        foreach ($bySlot as &$items) {
            foreach ($items as &$i) {
                // A suffix version is priced on its own; the base item's price mixes every suffix.
                $readings = $ah[$i['bonus'] !== null ? $i['id'].':'.$i['bonus'] : $i['id']] ?? [];
                if ($i['bonus'] === null && $this->hasVariants($i)) {
                    $readings = [];
                }
                $i['ah'] = $readings ? [
                    'now' => (int) end($readings)->min_unit,
                    'at' => end($readings)->scanned_at,
                    'usual' => (int) round(ForeverCraftsService::median(array_map(fn ($p) => (int) $p->min_unit, $readings))),
                    'seen' => count($readings),
                ] : null;
                $i['vendors'] = ($vendors[$i['id']] ?? collect())->map(fn ($v) => [
                    'name' => $v->npc_name, 'zone' => $v->zone, 'price' => (int) $v->price,
                    'costs' => json_decode($v->costs ?? '[]', true) ?: [], 'requires' => json_decode($v->requires ?? '[]', true) ?: [],
                ])->values()->all();
                $i['made'] = $made[$i['id']] ?? null;
                $i['requires'] = array_values(array_filter($i['lines'], fn ($l) => str_starts_with($l, 'Requires ')));
                $i['unique'] = in_array('Unique', $i['lines'], true) || in_array('Unique-Equipped', $i['lines'], true);
            }
        }

        return $bySlot;
    }

    /** Whether any suffix version of this base item is known: its own stats are then the base's alone. */
    private function hasVariants(array $i): bool
    {
        $this->withVariants ??= DB::table('forever_item_variants')->distinct()->pluck('item_id')->flip()->all();

        return isset($this->withVariants[$i['id']]);
    }
}
