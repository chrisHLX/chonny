<?php

namespace App\Support;

use App\Http\Services\TalentSelectionService;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Answers derived from the spell data, kept across requests (2026-10-11).
 *
 * WHY. The website measures each uploaded round in its own request, and every request started cold:
 * resolving each player's cooldowns from their own talents (CooldownLedgerService) and loading the
 * spell maps the timeline reads (ArenaMomentService) cost 2,358 queries and about 2 seconds a round,
 * against 16 queries once warm. A 450-round upload paid that 450 times on a one-core box. The same
 * players, builds and maps come round in every round of a lobby and every game after it.
 *
 * WHEN IT GOES STALE. Never silently: every key carries
 *  - the spell cache version, which a data change and every deploy bump (deploy.sh; rule 17);
 *  - the deployed commit (storage/app/deployed-commit.txt, "dev" locally);
 *  - the modification time of each file whose code or data decides the answer, so an edit on a dev
 *    machine, where there is no deploy, invalidates it too.
 * Bump VERSION to drop every entry by hand.
 */
class SpellDataCache
{
    public const VERSION = 1;

    private const TTL_SECONDS = 7 * 24 * 3600;

    private static ?string $base = null;

    /**
     * @param  array<int, string>  $files  absolute paths whose content decides the value
     */
    public static function remember(string $key, array $files, Closure $compute): mixed
    {
        $stamp = implode('.', array_map(fn ($f) => is_file($f) ? filemtime($f) : 0, $files));
        $full = self::base().':'.substr(md5($stamp), 0, 8).':'.$key;

        // Wrapped, so a legitimately null answer is remembered too.
        return Cache::remember($full, self::TTL_SECONDS, fn () => ['v' => $compute()])['v'];
    }

    private static function base(): string
    {
        if (self::$base === null) {
            $talents = app(TalentSelectionService::class);
            self::$base = 'spelldata:'.self::VERSION.':'.$talents->spellCacheVersion().':'.$talents->deployedCodeFingerprint();
        }

        return self::$base;
    }

    /** For tests and long-running processes that bump the version mid-run. */
    public static function forget(): void
    {
        self::$base = null;
    }
}
