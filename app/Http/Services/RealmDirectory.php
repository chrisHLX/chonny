<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Blizzard's realms per region, from /data/wow/realm/index: the names a player types or a combat
 * log writes, to the slugs the profile API wants.
 *
 * A log writes a realm without its spaces and hyphens (BleedingHollow, AzjolNerub, Jubei'Thos), and
 * that cannot be split back reliably: splitting at capitals got 24 US and 38 EU realms wrong
 * (2026-10-19: Azjol-Nerub is azjolnerub, Altar of Storms is altar-of-storms, DieewigeWacht is
 * die-ewige-wacht). Names are matched with everything but letters and digits taken out.
 *
 * Cached a week per region. A failed fetch is not cached and gives an empty list, so callers fall
 * back to their own rule until it works.
 */
class RealmDirectory
{
    public function __construct(private BattlenetClient $client) {}

    /**
     * Every realm in the region, sorted by name.
     *
     * @return list<array{name: string, slug: string}>
     */
    public function realms(string $region): array
    {
        $key = 'battlenet:realms:v1:'.$region;

        if (is_array($hit = Cache::get($key))) {
            return $hit;
        }

        try {
            $list = $this->client->gameData($region, '/data/wow/realm/index', 'dynamic')['realms'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Realm list lookup failed', ['region' => $region, 'error' => $e->getMessage()]);

            return [];
        }

        $realms = [];
        foreach ($list as $realm) {
            if (! empty($realm['name']) && ! empty($realm['slug'])) {
                $realms[] = ['name' => (string) $realm['name'], 'slug' => (string) $realm['slug']];
            }
        }
        usort($realms, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        if ($realms !== []) {
            Cache::put($key, $realms, now()->addDays(7));
        }

        return $realms;
    }

    /** The slug for a realm as a player or a log writes it, or null when the region has no such realm (or the list is unavailable). */
    public function slugFor(string $realm, string $region): ?string
    {
        return $this->maps($region)['slugs'][self::key($realm)] ?? null;
    }

    /** The realm's display name for a slug, or null. */
    public function nameFor(string $slug, string $region): ?string
    {
        return $this->maps($region)['names'][$slug] ?? null;
    }

    /**
     * The slug for a realm in any form, falling back to splitting the log's name at its capitals
     * when the list cannot be had: right for the common realms (measured on the 26 Sep games).
     */
    public function slugOrGuess(string $realm, string $region): string
    {
        return $this->slugFor($realm, $region)
            ?? strtolower(preg_replace(['/(?<=[a-z])(?=[A-Z0-9])/', '/\s+/', "/'-?/"], ['-', '-', ''], $realm));
    }

    /** Key to slug and slug to name, built once per region per process: a page linking a hundred players reads the list once. */
    private function maps(string $region): array
    {
        if (! isset(self::$memo[$region])) {
            $slugs = $names = [];
            foreach ($this->realms($region) as $r) {
                $slugs[self::key($r['name'])] = $r['slug'];
                $slugs[self::key($r['slug'])] ??= $r['slug'];
                $names[$r['slug']] = $r['name'];
            }
            if ($slugs === []) {
                return ['slugs' => [], 'names' => []];
            }
            self::$memo[$region] = ['slugs' => $slugs, 'names' => $names];
        }

        return self::$memo[$region];
    }

    /** @var array<string, array{slugs: array<string, string>, names: array<string, string>}> */
    private static array $memo = [];

    /** For tests: forget the per-process maps. */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /** A realm with everything but its letters and digits taken out, lowercased: the log's "AzjolNerub", Blizzard's "Azjol-Nerub" and its slug "azjolnerub" all meet here. */
    public static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $name));
    }
}
