# MindCollectorArenaLog

Turns WoW's combat logging on when an arena starts and off when it ends, so
`WoWCombatLog.txt` holds your games and nothing else. That file is the input to
`php artisan wow:ingest-combatlog`.

**Status: WRITTEN, NOT YET LIVE-TESTED.** Unlike `MindCollectorExport` (confirmed working
in-game), nothing here has been run in a real arena yet. The API calls it uses —
`LoggingCombat`, `IsInInstance`, `SetCVar("advancedCombatLogging")`, `PVP_MATCH_ACTIVE` /
`PVP_MATCH_COMPLETE` — are all long-standing, but "long-standing" is not "verified". First
run should confirm: the chat line appears on zoning in, the file grows, and a match imports.

## Install

Copy the `MindCollectorArenaLog` folder into:

```
<WoW>/_retail_/Interface/AddOns/MindCollectorArenaLog/
```

Restart WoW (or `/reload`). `/mcarenalog` prints the current state.

## Solo Shuffle

Works the same way — the addon keys off being in an arena instance and never looks at the
bracket. A lobby imports as **six matches, one per round**, each with its own teams and its own
result; `wow:ingest-combatlog` lists them `r1`..`r6`.

One thing here is still unverified, because the addon has never been run in a real arena: if
`PVP_MATCH_COMPLETE` fires at the end of each *round* rather than each lobby, this stops and
restarts logging six times a lobby. The rounds themselves would still be logged — `PVP_MATCH_ACTIVE`
turns it back on — but the saved-variable counter would count rounds, and whether a
`LoggingCombat` toggle opens a fresh `WoWCombatLog-*.txt` is untested. Point the ingester at the
whole `Logs/` directory if it does.

## Why not just leave `/combatlog` on

Two reasons. It resets every session, so you would have to remember it every time. And left on
permanently it logs everything — questing, dungeons, raids — which grows the file by gigabytes
a week and makes the import slow for no benefit.

## Advanced Combat Logging is the part that matters

Without it the log has **no `COMBATANT_INFO` lines**, which means no specs, which means the
ingester cannot use the match at all — it will say so and skip it. The addon sets the CVar
itself every time it starts logging, rather than assuming it is on, because it is per-account
and a UI reset clears it.

To check by hand: **System → Network → Advanced Combat Logging**.

## The workflow

1. Play arena with the addon installed.
2. `php artisan wow:ingest-combatlog "<WoW>/_retail_/Logs/WoWCombatLog.txt"`
3. `php artisan wow:refresh-match-derived`

Step 2 is safe to re-run over the same growing file: a match's id comes from its start instant,
arena and roster, so anything already imported is skipped rather than duplicated.

Set `WOW_COMBATLOG_PATH` in `.env` and step 2 needs no argument.

## What it writes

`MindCollectorArenaLogDB` in SavedVariables, holding a count and a timestamp. Nothing reads it —
it exists so `/mcarenalog` can tell you whether the thing has been doing anything.
