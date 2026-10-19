<?php

namespace App\Support;

use App\Http\Services\RealmDirectory;

/** The player page's address (/wow/player/{region}/{realm}/{name}) from a name in any form a page holds. */
class PlayerUrl
{
    /** From "Name-Realm-Region", as the combat log writes it; null when it is not in that form. */
    public static function fromLogName(?string $logName, bool $absolute = true): ?string
    {
        [$name, $realm, $region] = array_pad(explode('-', (string) $logName, 3), 3, null);

        return $name && $realm && $region ? self::to(strtolower($region), $realm, $name, $absolute) : null;
    }

    /** $realm in any form: a slug, a display name or the log's. */
    public static function to(string $region, string $realm, string $name, bool $absolute = true): string
    {
        return route('players.show', [
            'region' => $region,
            'realm' => app(RealmDirectory::class)->slugOrGuess($realm, $region),
            'name' => mb_strtolower($name),
        ], $absolute);
    }
}
