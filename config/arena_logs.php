<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Arena Log Archive Path
    |--------------------------------------------------------------------------
    |
    | Root directory for raw combat logs (raw/{matchId}.log.gz) and match
    | metadata (metadata/{matchId}.json) — the bulky, ever-growing part of
    | arena-log analysis. Kept configurable so it can live outside this
    | project's own disk/git footprint (see wow-arena-archive/README.md).
    |
    | As of 2026-08-20, spell-usage/{class}/{spec}.txt and
    | kill-sequences/{class}/{spec}.jsonl WRITES also go here — direct user
    | instruction: new pulled-match data should land in the archive first,
    | as a staging/review area, and only get manually copied into this
    | project's own data/arena-logs/ tree once the user is confident it's
    | correct. That promoted copy is what the live app actually reads —
    | ArenaLogService::spellUsageIds() is DELIBERATELY still hardcoded to
    | this project's own data/arena-logs/ path, not this config value, so a
    | fresh unreviewed pull can never silently change what's live. Do not
    | "fix" that asymmetry without re-reading this note — it's intentional,
    | not leftover inconsistency. (kill-sequences/ has no live reader at all
    | as of 2026-08-27 — its one consumer, RatingTierAnalysisService, was
    | retired that day; see wow-arena-archive/README.md for why.)
    | comp-index.json, spec-index.json are untouched by any of this
    | (console-command-only, no live-read path to protect).
    |
    | Defaults to the in-repo path when unset, so this is a no-op for any
    | environment that hasn't configured ARENA_LOG_ARCHIVE_PATH.
    |
    */

    'archive_path' => env('ARENA_LOG_ARCHIVE_PATH', base_path('data/arena-logs')),

    /*
    |--------------------------------------------------------------------------
    | Local Combat Log
    |--------------------------------------------------------------------------
    |
    | Where WoW writes its own combat log, so `wow:ingest-combatlog` can be run
    | with no argument. This is how matches get into the archive now:
    | wowarenalogs.com turned match search off in September 2026 and then put
    | it behind a Battle.net sign-in, and everything their API returned is
    | already in this file (see CombatLogIngestService).
    |
    | Typically:
    |   Windows  C:/Program Files (x86)/World of Warcraft/_retail_/Logs/WoWCombatLog.txt
    |   macOS    /Applications/World of Warcraft/_retail_/Logs/WoWCombatLog.txt
    |
    | Advanced Combat Logging must be on (System > Network) or the log carries
    | no COMBATANT_INFO, and without that there are no specs and nothing
    | downstream can use the match.
    |
    */

    'combatlog_path' => env('WOW_COMBATLOG_PATH'),
];
