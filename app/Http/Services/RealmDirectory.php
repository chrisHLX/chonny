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
        $want = self::key($realm);

        foreach ($this->realms($region) as $r) {
            if (self::key($r['name']) === $want || $r['slug'] === mb_strtolower(trim($realm))) {
                return $r['slug'];
            }
        }

        return null;
    }

    /** The realm's display name for a slug, or null. */
    public function nameFor(string $slug, string $region): ?string
    {
        foreach ($this->realms($region) as $r) {
            if ($r['slug'] === $slug) {
                return $r['name'];
            }
        }

        return null;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $name));
    }
}
