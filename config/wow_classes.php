<?php

/**
 * WoW class color scheme (Blizzard's own RAID_CLASS_COLORS, unchanged for well over a
 * decade — used in the game client, armory, and effectively every third-party WoW tool).
 * Keyed by our own classes.slug (see ImportSpellData::importClass()). Used purely for
 * presentational accents (icon fallback rings/badges) — never fetched from an API, since
 * this is stable, canonical reference data with no meaningful "current" version to import.
 */
return [
    'colors' => [
        'warrior'     => '#C79C6E',
        'paladin'     => '#F58CBA',
        'hunter'      => '#ABD473',
        'rogue'       => '#FFF569',
        'priest'      => '#FFFFFF',
        'deathknight' => '#C41F3B',
        'shaman'      => '#0070DE',
        'mage'        => '#69CCF0',
        'warlock'     => '#9482C9',
        'monk'        => '#00FF96',
        'druid'       => '#FF7D0A',
        'demonhunter' => '#A330C9',
        'evoker'      => '#33937F',
    ],

    // Blizzard's numeric playable-class ids (stable since Legion), keyed by our own classes.slug.
    // Same table data/spelldata/fetch-class-spec-icons.php hardcodes; here so the app can map a
    // Battle.net character's `playable_class.id` onto a class without matching on its name.
    'blizzard_ids' => [
        'warrior'     => 1,
        'paladin'     => 2,
        'hunter'      => 3,
        'rogue'       => 4,
        'priest'      => 5,
        'deathknight' => 6,
        'shaman'      => 7,
        'mage'        => 8,
        'warlock'     => 9,
        'monk'        => 10,
        'druid'       => 11,
        'demonhunter' => 12,
        'evoker'      => 13,
    ],
];
