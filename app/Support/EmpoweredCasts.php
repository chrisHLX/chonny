<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * A spec's empowered spells for the PvP Guides box: one press made stronger by buffs built beforehand
 * (docs/class-mechanics.md).
 *
 * The numbers come from data/empowered-casts/{class}/{spec}.json, baked on the PC by
 * tools/match-review/empowered.php from buffs approved in reviewed.json. Nothing here reads the
 * archive (rule 14). Names are the key: internal spell ids change per environment (rule 2).
 */
class EmpoweredCasts
{
    public static function forSpec(?string $classSlug, ?string $specSlug): ?array
    {
        if (! $classSlug || ! $specSlug) {
            return null;
        }

        $path = base_path("data/empowered-casts/{$classSlug}/{$specSlug}.json");
        if (! File::exists($path)) {
            return null;
        }

        $data = json_decode(File::get($path), true);
        if (! is_array($data) || empty($data['empowers'])) {
            return null;
        }

        $data['icons'] = self::icons(self::names($data));

        return $data;
    }

    /** First letters, for the few buffs the spell data holds no icon for. */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim(preg_replace("/[^\p{L}\s]/u", '', $name)));

        return mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
    }

    private static function names(array $data): array
    {
        $names = [$data['payoff'] ?? ''];
        foreach ($data['empowers'] as $e) {
            $names = array_merge($names, [$e['buff']], $e['from'] ?? [], array_column($e['results'] ?? [], 'ability'));
        }

        return array_values(array_unique(array_filter($names)));
    }

    /** One icon per name, preferring the copy of a spell a player actually presses (rule 3). */
    private static function icons(array $names): array
    {
        $icons = [];
        $rows = DB::table('spells')->whereIn('name', $names)->whereNotNull('icon_name')
            ->orderByDesc('not_in_spellbook')->orderByDesc('is_passive')->get(['name', 'icon_name']);

        foreach ($rows as $row) {
            $icons[$row->name] = $row->icon_name;
        }

        return $icons;
    }
}
