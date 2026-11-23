<?php

namespace App\Support;

/**
 * WoW Forever's class abilities, sorted by retail's categories: the committed
 * data/forever/abilities.json that `forever:abilities` builds from the game's own tables. Read once
 * per request; the page never touches the tables or the spell database.
 */
class ForeverAbilities
{
    /** Retail's order for the groups on its Spells table (components/spells/table.blade.php). */
    public const CATEGORIES = ['Crowd Control', 'Defensive', 'Mobility', 'Utility', 'Offensive', 'Other'];

    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data === null) {
            $path = base_path('data/forever/abilities.json');
            self::$data = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
        }

        return self::$data;
    }

    /** @return array<int, array{slug: string, name: string, abilities: array}> */
    public static function classes(): array
    {
        return self::data()['classes'] ?? [];
    }

    public static function find(string $slug): ?array
    {
        foreach (self::classes() as $class) {
            if ($class['slug'] === $slug) {
                return $class;
            }
        }

        return null;
    }

    public static function build(): ?string
    {
        return self::data()['build'] ?? null;
    }

    /** For tests: forget what was read. */
    public static function flush(): void
    {
        self::$data = null;
    }
}
