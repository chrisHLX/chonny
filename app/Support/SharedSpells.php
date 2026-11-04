<?php

namespace App\Support;

/**
 * Spells any class can press that the spell data does not hold: PvP trinkets, racials, item effects
 * (2026-11-04, Chriso: "timeline should show trinkets and racials"). SimC's class dumps carry none of
 * them, so they had no icon and no cooldown anywhere.
 *
 * data/arena-logs/shared-spells.json, written by `wow:shared-spells`: the list comes from the games
 * (pressed by players of three or more classes, absent from the spell data), each spell id from the
 * raw logs and each icon from Blizzard's media API. `cooldown` is hand-filled from the domain expert,
 * never guessed: an entry without one gets its icon everywhere but is no tracked button on the
 * Watch page's frames, which would otherwise count down a made-up number.
 */
class SharedSpells
{
    private static ?array $all = null;

    public static function path(): string
    {
        return base_path('data/arena-logs/shared-spells.json');
    }

    /** @return array<string, array{spellId: int, icon: ?string, cooldown: ?float, charges?: int}> by name */
    public static function all(): array
    {
        if (self::$all === null) {
            $file = self::path();
            self::$all = is_file($file) ? (json_decode((string) file_get_contents($file), true)['spells'] ?? []) : [];
        }

        return self::$all;
    }

    public static function icon(string $name): ?string
    {
        return self::all()[$name]['icon'] ?? null;
    }

    /** Its cooldown in seconds, once someone who knows has filled it in; null until then. */
    public static function cooldown(string $name): ?float
    {
        $cd = self::all()[$name]['cooldown'] ?? null;

        return is_numeric($cd) && $cd > 0 ? (float) $cd : null;
    }

    /** For tests and the command that writes the file. */
    public static function forget(): void
    {
        self::$all = null;
    }

    /** For tests: these spells in place of the file's, until forget(). */
    public static function use(array $spells): void
    {
        self::$all = $spells;
    }
}
