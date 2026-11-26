<?php

namespace App\Support;

/**
 * WoW Forever's recipes, read from the Auction House addon's own Data.lua (2026-11-26), so the site and
 * the addon's Professions tab work from one list. Data.lua is generated from the game client's tables
 * by tools/forever-market/observer_data.py (docs/forever/observer-handoff-2026-11-10.md) and committed.
 *
 * Read from it: every recipe (ns.recipes), item names (ns.itemNames), what vendors sell reagents for
 * (ns.vendor) and Wowhead's facts per recipe (ns.wh: the skill it's learned at, how many a craft makes),
 * laid over the recipe the same way ui.lua does on load.
 */
class ForeverRecipes
{
    public const PROFESSIONS = [
        171 => 'Alchemy', 164 => 'Blacksmithing', 185 => 'Cooking', 333 => 'Enchanting', 202 => 'Engineering',
        129 => 'First Aid', 165 => 'Leatherworking', 186 => 'Mining', 197 => 'Tailoring',
    ];

    public const PATH = 'tools/wow-addon/MindCollectorObserver/Data.lua';

    public const COOLDOWNS = 'data/forever/recipe-cooldowns.json';

    /** @var array{recipes: list<array>, names: array<int, string>, vendor: array<int, int>}|null */
    private static ?array $data = null;

    /**
     * Every recipe that makes an item: {spell, name, profession, skill, out, yield, reagents: [[item, count]],
     * recipeItem (0 when a trainer teaches it), cooldown (seconds, or null), cooldownGroup (the cooldown
     * other recipes share, or '')}. Cooldowns are the game's own (data/forever/recipe-cooldowns.json,
     * forever:recipe-cooldowns).
     *
     * @return list<array>
     */
    public static function recipes(): array
    {
        return self::load()['recipes'];
    }

    public static function name(int $item): ?string
    {
        return self::load()['names'][$item] ?? null;
    }

    /** What a vendor sells the item for, in copper, when it's one of the reagents vendors stock. */
    public static function vendorPrice(int $item): ?int
    {
        return self::load()['vendor'][$item] ?? null;
    }

    /** Changes when Data.lua does, for cache keys. */
    public static function version(): string
    {
        $stamp = '';
        foreach ([self::PATH, self::COOLDOWNS] as $file) {
            $path = base_path($file);
            $stamp .= is_file($path) ? filesize($path).':'.filemtime($path).';' : 'none;';
        }

        return substr(md5($stamp), 0, 10);
    }

    private static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $path = base_path(self::PATH);
        $recipes = $names = $vendor = $wh = [];
        $section = null;
        $profession = null;
        $str = '"((?:[^"\\\\]|\\\\.)*)"';

        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (preg_match('/^ns\.(\w+) = \{/', $line, $m)) {
                $section = $m[1];

                continue;
            }
            if ($line === '}') {
                $section = null;

                continue;
            }
            switch ($section) {
                case 'recipes':
                    if (preg_match('/^  \[(\d+)\] = \{$/', $line, $m)) {
                        $profession = (int) $m[1];
                    } elseif (preg_match('/^\s+\{(\d+), '.$str.', (\d+), (\d+), (\d+), (\d+), (\d+), \{([\d, ]*)\}, (\d+), (\d+), (\d+), (\d+)\},?$/', $line, $m)) {
                        $counts = array_map('intval', array_filter(array_map('trim', explode(',', $m[8])), 'strlen'));
                        $reagents = [];
                        for ($i = 0; $i + 1 < count($counts); $i += 2) {
                            $reagents[] = [$counts[$i], $counts[$i + 1]];
                        }
                        $recipes[] = [
                            'spell' => (int) $m[1], 'name' => stripcslashes($m[2]), 'profession' => $profession,
                            'skill' => (int) $m[3], 'out' => (int) $m[7], 'yield' => max(1, (int) $m[12]),
                            'reagents' => $reagents, 'recipeItem' => (int) $m[10],
                        ];
                    }
                    break;
                case 'itemNames':
                    if (preg_match('/^\s+\[(\d+)\] = '.$str.',$/', $line, $m)) {
                        $names[(int) $m[1]] = stripcslashes($m[2]);
                    }
                    break;
                case 'vendor':
                    if (preg_match('/^\s+\[(\d+)\] = (\d+),$/', $line, $m)) {
                        $vendor[(int) $m[1]] = (int) $m[2];
                    }
                    break;
                case 'wh':
                    // {learn, orange, yellow, green, grey, "sources", fee, made min, made max}
                    if (preg_match('/^\s+\[(\d+)\] = \{(\d+), (\d+), (\d+), (\d+), (\d+), "[^"]*", (\d+), (\d+), (\d+)\},?$/', $line, $m)) {
                        $wh[(int) $m[1]] = ['learn' => (int) $m[2], 'madeMin' => (int) $m[8], 'madeMax' => (int) $m[9]];
                    }
                    break;
            }
        }

        // Wowhead's facts over the client's, as ui.lua does: the skill it's learned at, and how many one
        // craft makes (the average when it's a range).
        $cooldowns = [];
        $cdPath = base_path(self::COOLDOWNS);
        if (is_file($cdPath)) {
            $cooldowns = json_decode(file_get_contents($cdPath), true)['cooldowns'] ?? [];
        }

        $made = [];
        foreach ($recipes as $r) {
            if ($r['out'] === 0 || $r['reagents'] === []) {
                continue;
            }
            $w = $wh[$r['spell']] ?? null;
            if ($w) {
                if ($w['learn'] > 0 && $w['learn'] < 9999) {
                    $r['skill'] = $w['learn'];
                }
                if ($w['madeMin'] + $w['madeMax'] > 0) {
                    $r['yield'] = max(1, ($w['madeMin'] + $w['madeMax']) / 2);
                }
            }
            $cd = $cooldowns[$r['spell']] ?? null;
            $r['cooldown'] = $cd ? (int) $cd[0] : null;
            $r['cooldownGroup'] = $cd ? (string) $cd[1] : '';
            $made[] = $r;
        }

        return self::$data = ['recipes' => $made, 'names' => $names, 'vendor' => $vendor];
    }
}
