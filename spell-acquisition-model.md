# Spell Acquisition Model

How MindCollector builds and continuously corrects its own model of "what spells actually exist, for which class/spec, doing what" — four distinct sources feeding one schema, plus a standing AI-calibration layer sitting on top of all of them. Read this alongside `game-data.md` (the deep, dated findings log for the import pipeline itself) and `wow-spell-data-model.md` (why WoW's own data has no "spec membership" table at all, the root reason most of this exists). This document is the map of *what pulls from where* — every script, command, service method, and file involved — not a findings log.

## The shape of it

```
┌─────────────────┐   ┌──────────────────────┐   ┌───────────────────────────────────────┐
│   SimC raw       │   │  Blizzard Game Data  │   │        Real-world verification         │
│   dumps          │   │  API                 │   │  ┌─────────────────┐ ┌────────────────┐│
│                  │   │                      │   │  │ Addon spellbook │ │ Arena match logs││
│  bulk, mostly     │   │  talent trees +      │   │  │ snapshots        │ │                ││
│  reliable, one    │   │  PvP talents —       │   │  │                  │ │ broad, growing  ││
│  known ambiguity  │   │  structurally        │   │  │ complete, narrow │ │ automatically,  ││
│  (spec_id=NULL    │   │  unambiguous spec    │   │  │ — one real       │ │ real match      ││
│  baseline bucket) │   │  attribution         │   │  │ character at a   │ │ evidence across  ││
│                  │   │                      │   │  │ time             │ │ many characters  ││
└────────┬─────────┘   └──────────┬───────────┘   │  └────────┬─────────┘ └────────┬───────┘│
         │                        │                │           │                    │        │
         └────────────────────────┴────────────────┴───────────┴────────────────────┘        │
                                   │                                                            │
                                   ▼                                                            │
                      ┌─────────────────────────┐                                              │
                      │   spells / spell_effects /│  ◄── AI calibration layer sits over all of  │
                      │   spell_relationships /   │      this permanently (see CLAUDE.md's      │
                      │   spell_class_availability │      "AI-Assisted Game Data Modeling") —    │
                      │   / talent_* / pvp_talents │      cross-referencing screenshots,          │
                      │                            │      recognizing game systems from general  │
                      └─────────────────────────┘      knowledge, verifying a heuristic's        │
                                                          assumptions before it ships.             │
```

**Trust tiers, stated plainly, because they get conflated easily:**
- **Blizzard Game Data API data (talent trees, PvP talents)** is structurally unambiguous — a `talent_node_entries` row IS the spec attribution, no inference involved. The most trustworthy tier.
- **SimC's raw dump** is bulk and mostly reliable, but has one specific, well-understood ambiguity: its `baseline.txt` isn't actually spec-filtered, so a baseline spell's true spec ownership is often genuinely unknown from the dump alone.
- **Addon-captured spellbook snapshots** are ground truth for the one real character they came from — complete (every known spell, not just what got cast), but coverage is tiny and depends entirely on someone manually running the addon.
- **Arena match logs are also ground truth, not an inference** — this is worth stating explicitly because it's easy to lump them in with the *reverted* heuristics (Mind Sear's leak via `spells.mechanic`/`spell_relationships`, the abandoned `alwaysAvailableAbilityIds()`). Those were guesses built from *indirect, structural* signals already sitting inside our own imported data. An arena log is a direct recording of what a real player, in a real match, whose spec is unambiguously identified in the match's own metadata, actually cast. The only real failure modes are data corruption or a hacked/spoofed client — not "this signal turned out to be unreliable," which was the actual root cause of every reverted heuristic. This is why `wow:diff-arena-spells --apply` already writes without per-line manual re-verification (a deliberate, explicit trust decision made 2026-08-14) and why CC discovery (see below) should be designed the same way, not as a human-gated proposal tool the way an *inferential* heuristic would need to be.

## Layer 1 — SimC raw dumps

| Step | File / Method |
|---|---|
| Pull raw per-class dumps from SimC's GitHub (`data-update-live-*` branch) | `data/spelldata/fetch-simc-dumps.php` |
| Split/filter raw dumps into per-class working files | `data/spelldata/split-by-tree.php`, `data/spelldata/regenerate-filtered.php` → `data/spelldata/filtered/{class}/*.txt` |
| Parse filtered `.txt` into structured records (cooldown, charges, description, effects, mechanic, is_passive, not_in_spellbook, cast_type, variables, `free=(...)`/`replace=` annotations) | `App\Http\Services\SpellDataFileParser` |
| Master import — orchestrates everything below | `App\Console\Commands\ImportSpellData` (`php artisan import:spelldata {game} {patch}`) |
| Per-class spell import | `ImportSpellData::importClass()` → `importClassSpells()` |
| Classify a `.txt` file's spec scope (baseline/class-talents/hero/spec-named) | `classifyFileSource()` |
| Narrow a baseline record's spec scope from its own `Class:` field | `resolveBaselineSpecIds()` |
| Narrow a class-tree talent's spec scope from its `free=(...)` annotation | `resolveFreeSpecIds()` |
| Build `spell_relationships` (four passes: Affecting-Spells text, Category effects, `replace=` swaps, PvP talent cooldown mods) | `importRelationships()`, `importCategoryRelationships()`, `importReplacesRelationships()`, `importPvpTalentRelationships()` |
| Resolve `$@spelldesc<id>`-style inherited description pointers | `resolveDescriptionReferences()` |
| **Hand-authored correction files, applied every import** ||
| Spells absent from SimC's dump entirely | `data/spelldata/manual-spells.txt` → `importManualSpells()` |
| Resolve the ambiguous `spec_id=NULL` baseline bucket, one verified fact at a time | `data/spelldata/baseline-spec-overrides.txt` → `importBaselineSpecOverrides()` |
| Synergies-tab curation (`dr_category`/`chain_target`/`is_peel`/`is_interrupt`/`pvp_duration_seconds`) | `data/spelldata/cc-synergies-overrides.txt` → `importCcSynergyOverrides()` |
| Direct field-level corrections (cooldown/duration/mechanic typos, etc.) | `data/spelldata/scalar-corrections.txt` → `importScalarCorrections()` |
| **Defensive, always-run cleanup passes** ||
| Delete stale class-tree nodes that duplicate a spec-tree talent | `App\Http\Services\TalentSelectionService::cleanupClassTreeBloat()` |
| Delete same-position ACTIVE-node double-picks in talent builds | `TalentSelectionService::cleanupSamePositionCollisions()` |
| Bump the Redis spell-reference cache version | `TalentSelectionService::bumpSpellCacheVersion()` |
| Sanity-check the freshly imported patch against every spellbook snapshot on file | `ImportSpellData::runSpellbookDiffCheck()` |

**The one-shot patch-update orchestrator**, wiring the fetch scripts through the import in order: `App\Console\Commands\PatchUpdate` (`wow:patch-update {build} --branch=...`).

## Layer 2 — Blizzard Game Data API

| Step | File / Method |
|---|---|
| Fetch class/spec/hero talent trees, filtering out the class-tree-bloat duplication | `data/talenttrees/fetch-talent-trees.php` |
| Fetch PvP talents (bundled into the same per-spec API response) | same script — see its own `--skip-pvp` flag |
| Cross-check PvP talents against SimC's own PvP-tagged records | `data/pvptalents/diff-against-simc.php` |
| Import specializations | `ImportSpellData::importSpecializations()` |
| Import talent trees + nodes | `importTalentTrees()`, `importTreeNodes()` |
| Import PvP talents | `importPvpTalents()`, `resolvePvpTalentTargetSpell()` |
| Self-hosted spell/class/spec icons (Blizzard's media API), never hotlinked | `data/spelldata/fetch-spell-icons.php`, `data/spelldata/fetch-class-spec-icons.php` |
| Apply the committed icon manifest without needing Blizzard credentials on every machine | `App\Console\Commands\ApplyIconManifest` (`wow:apply-icon-manifest`) |
| Refresh/backfill icons for newly-added spells | `App\Console\Commands\RefreshSpellIcons` (`wow:refresh-icons`) |
| Fix duplicate-pick talent-build data (same-position collisions, standalone entry point) | `App\Console\Commands\FixTalentCollisions` (`wow:fix-talent-collisions`) |
| Import a third-party site's (murlok.io) curated default builds — the one *non*-Blizzard, non-first-party source, explicitly scoped and always preview-before-apply | `App\Http\Services\MurlokTalentImportService`, `App\Console\Commands\ImportMurlokDefaults` (`wow:import-murlok-defaults`) |

## Layer 3a — Addon-captured spellbook snapshots

| Step | File / Method |
|---|---|
| The addon itself (`/mcexport` slash command) | `tools/wow-addon/MindCollectorExport/main.lua` |
| Parse the exported SavedVariables `.lua` (a small hand-rolled parser — no general Lua parser in this codebase) | `App\Http\Services\SavedVariablesLuaParser` |
| Import a snapshot (append-only — a new export never overwrites) | `App\Console\Commands\ImportSpellbook` (`wow:import-spellbook {path}`) → `spellbook_snapshots` / `spellbook_snapshot_entries` |
| Diff a snapshot against `spell_class_availability` (Direction A/B/C/D — missing spells, not-in-spellbook candidates, `spec_id=NULL` bucket checks, spec-history noise) | `App\Console\Commands\DiffSpellbook` (`wow:diff-spellbook {snapshot_id?}`) |
| Decode/encode a real Blizzard "Export" talent-loadout string | `App\Http\Services\BlizzardTalentStringCodec` |
| Serve a snapshot's real, spec-conditional-resolved description text in place of the template resolver, when a build is explicitly linked to one | `TalentSelectionService::resolvedDescriptionsFor()` |

## Layer 3b — Arena match logs

Not from an addon — pulled from wowarenalogs.com's public, unauthenticated GraphQL API (a third-party site players upload full combat logs to). See `arena-log-api.md` for the reverse-engineered schema reference.

| Step | File / Method |
|---|---|
| **Central service** — every responsibility below lives here | `App\Http\Services\ArenaLogService` |
| Fetch/store one known match ID (raw log gzipped + metadata JSON) | `fetchMatch()`, `storeMatch()` |
| Search for the highest-rated recent win by an exact comp signature | `searchCompWins()`, `pullBestWinForComp()`, `sortSpecIdsForQuery()` |
| Search for/pull the highest-rated recent match containing a given spec (win or loss) | `searchMatchesForSpec()`, `pullHighestRatedMatchForSpec()`, `gatherSpecCandidates()` |
| Pull the top N matches for a spec / low-rated matches for contrast | `pullTopMatchesForSpec()`, `pullLowRatedMatchesForSpec()` |
| Parse a stored match for what each real player actually cast | `extractCastSpellsByPlayer()` |
| Accumulate cast-spell evidence into `data/arena-logs/spell-usage/{class}/{spec}.txt` | `mergeSpellUsage()`, `mergeByCanonicalName()`, `pickCanonicalSpell()` |
| Roster-composition check for a given match (any real player playing spec X?) | `matchRosterHasSpec()` — new 2026-08-17, generic, not Oppressing-Roar-specific |
| Reconstruct the pre-kill window / full causal timeline for one match | `findPreKillWindow()`, `recordKillSequence()`, `analyzeKillCausally()`, `parseLogTimestamp()` |
| **Commands wrapping the service** ||
| Pull one match by ID | `App\Console\Commands\FetchArenaLog` (`wow:fetch-arena-log`) |
| Pull the best win for a comp | `App\Console\Commands\PullCompArenaLog` (`wow:pull-comp-log`) |
| Extract a stored match's cast spells | `App\Console\Commands\ExtractArenaSpells` (`wow:extract-arena-spells {matchId}`) |
| Pull matches to fill out a low-rated or scarcely-represented spec | `App\Console\Commands\PullLowRatedSpec`, `App\Console\Commands\PullScarceSpecs` |
| Pull matches specifically for specs with CC spells still missing `pvp_duration_seconds` | `App\Console\Commands\PullMissingCcMatches` (`wow:pull-missing-cc-matches`) |
| **Diff/discovery against `spell_class_availability`** — the "does the DB match what real games show" check ||
| Diff one spec's accumulated usage file against the DB (`CONFIRMED`/`PROMOTION_CANDIDATE`/`CONTRADICTION`/`MISSING_ENTIRELY`), `--apply` writes straight to `baseline-spec-overrides.txt` and re-imports | `App\Console\Commands\DiffArenaSpells` (`wow:diff-arena-spells {classSlug} {specSlug} [--apply]`) |
| One-shot per-spec pipeline: find a match, pull, extract, merge, diff | `App\Console\Commands\DiscoverSpecSpells` (`wow:discover-spec-spells`) |
| Loop the above across every spec | `App\Console\Commands\DiscoverAllSpecs` (`wow:discover-all-specs`) |
| Find spells with real cast evidence + a CC-adjacent `mechanic` tag but no `dr_category` yet — `--apply` writes the empirically-validated unambiguous mappings straight to `cc-synergies-overrides.txt` and re-imports | `App\Console\Commands\DiscoverCcSpells` (`wow:discover-cc-spells [--apply]`) |
| **PvP-specific number resolution** — the newest capability, arena logs deriving numbers no other layer can (raw `duration_seconds`/tooltip values are PvE-scoped and known-unreliable for PvP) ||
| Resolve a spell's real PvP CC duration from observed `SPELL_AURA_APPLIED`→`REMOVED` windows: histogram/mode across every instance on file, outliers checked against Preservation Evoker roster presence (Oppressing Roar) before being trusted or discarded | `App\Console\Commands\FindCcDuration` (`wow:find-cc-duration {spellId}`) — report-only, never writes |

**Downstream consumers of the same raw log data — analysis tools, not acquisition** (listed for completeness, out of scope for this doc's actual subject): `App\Console\Commands\KillSequence`, `RecordKillSequences`, `CommonPreKillSpells`, `KeyOffensiveAbilities`, `AnalyzeKill`, `AnalyzeRatingTiers`. These read already-stored matches to answer gameplay questions (what led to this kill, what do high-rated comps do differently) — they don't correct or extend the spell data model itself.

## CC discovery — built 2026-08-17

`App\Console\Commands\DiscoverCcSpells` (`wow:discover-cc-spells [--apply]`) closes the gap described above: scans every arena log on file for real cast/aura evidence of spells with no `dr_category` yet but a CC-adjacent `spells.mechanic` tag. Trusted and written the same way `wow:diff-arena-spells --apply` already is — real arena-log evidence, not gated behind per-line human review.

**The classification-signal question this section originally left open (which `dr_category` a given mechanic maps to) was answered empirically, not guessed**: every spell in the current patch already carrying both `mechanic` and a hand-curated `dr_category` was queried and grouped by mechanic. Most map to exactly one `dr_category` with zero exceptions (`Stun`→Stun, `Root`→Root, `Flee`→Disorient, `Silence`→Silence, `Sap`/`Shackle`/`Polymorph`→Incapacitate, `Charm`/`Turn`→Disorient, `Snare`→Slow). Two mechanics are genuinely mixed even in real curated data (`Banish`, `Bleed`) and are always surfaced for review, never auto-mapped; `Sleep` was moved into that same review-only tier after a direct textual contradiction was caught before shipping (see CLAUDE.md's "`wow:discover-cc-spells` built" section for the full trace).

**Two safety guards, both found necessary by real output, not added speculatively:**
- A same-*display*-name duplicate guard (reusing `Spell::getDisplayNameAttribute()`'s `"(desc=...)"`-suffix stripping) — without it, 14 of the first 32 raw candidates would have been internal duplicate copies of already-correctly-tagged real abilities.
- A literal `"slow"`-substring requirement on any `Snare→Slow` mapping, plus a small hardcoded exclusion list for spells this project already investigated and declined to tag (Fatal Flourish, Divine Hammer, Numbing Poison, Frozen Orb, Searing Dialogue) — `Snare` is the one mechanic already confirmed to carry real spurious tags on abilities unrelated to movement speed.

Verified end-to-end 2026-08-17: 12 real candidates applied (10 via `--apply`, 2 added by hand after independently confirming their description text), then run through `wow:find-cc-duration` to resolve `pvp_duration_seconds` for all 12 — 8 resolved cleanly, 2 as weaker-but-real plurality winners, 2 deliberately left unresolved (one too weak/likely measuring the wrong duration component, one genuinely tied between two values).



LATEST COMMIT CHANGE

git fetch origin
git checkout specificity
git reset --hard origin/specificity


What to run on live, in this order
1. Schema — apply any migrations not yet run:


php artisan migrate --force
--force skips the "you're in production, are you sure?" prompt. Safe/idempotent — it only runs migrations not already recorded in the migrations table, whatever that number turns out to be. Do not use migrate:fresh — that wipes the whole DB.

2. Storage symlink (only needed once per server — safe to re-run, no-ops if it exists):


php artisan storage:link
Without this, every spell/class/spec icon 404s even though the files and DB columns are correct.

3. The big one — re-import spell data:


php artisan import:spelldata wow 12.0.7.68453
⚠️ That exact version string, not the real current WoW patch. This project deliberately freezes the "current" patch at 12.0.7.68453 so re-imports update data in place instead of forking a new, disconnected patch row (see CLAUDE.md's "frozen patch version" note — this bit someone earlier this session). Before running it, it's worth double-checking the DB agrees:


php artisan tinker --execute="echo App\Models\Patch::where('is_current', true)->first()->build_version;"
It should print 12.0.7.68453. If it prints anything else, stop and tell me before running the import.

This one command is what actually applies almost everything from this session: baseline-spec-overrides.txt (Turtle, Evoker CC fixes), cc-synergies-overrides.txt (dr_category tagging), scalar-corrections.txt (Strangulate's cooldown), the class-tree-bloat/duplicate-node/same-position-collision cleanup passes, and it automatically bumps the spell cache version — no separate cache-clear step needed. If it's slow/runs out of memory (it has before, after several consecutive re-imports), use:


php -d memory_limit=512M artisan import:spelldata wow 12.0.7.68453
4. Icons, if this server has no Blizzard API credentials (most production setups don't need them for this):


php artisan wow:apply-icon-manifest
Reads the committed data/spelldata/icon-manifest.json + the committed icon files under storage/app/public/, fills in icon_name — zero API calls, safe to always run.

5. Assets, if your deploy doesn't already do this:


composer install --no-dev --optimize-autoloader
npm run build
Not needed:

talent-spec-exclusions.txt (the Hunter Intimidation fix) — read live at request time, not import time. Works the instant the code deploys, no command required.
Arena-log spell-usage/kill-sequence/rating-tier data — already committed to git as plain files, arrives with the code pull. The raw/metadata folders are gitignored now and only matter if you're pulling new matches on that server, which isn't required for the site to work.
Any db:seed — nothing new needs seeding for this session's work.
Run 1→2→3→4 in that order (4 only matters after 1 has created the icon_name columns), then spot-check the live site.


You're right, and I want to be straight about what happened: the reason this took multiple rounds wasn't that the process is inherently messy — it's that this specific server had accumulated three separate real bugs (a stray un-flagged second patch from an old accidental version bump, two query methods that weren't patch-scoped like their siblings were, and curation values that had only ever been written to someone's local DB and never committed). All three are now fixed in code/files, not patched around by hand.

With everything just pushed, the actual answer to "wipe this server and deploy fresh" is now genuinely one command for data, plus the one-time-per-machine setup steps every Laravel app needs:


git pull
composer install --no-dev --optimize-autoloader
npm run build
php artisan migrate --force
php artisan storage:link
php artisan import:spelldata wow 12.0.7.68453
php artisan wow:apply-icon-manifest
php artisan optimize:clear
sudo systemctl restart php8.2-fpm
That's it — no manual patch-flipping, no hand-written diagnostic scripts, no per-spell fixes. import:spelldata is now the single source of truth for every fix from this whole session, because the fixes live in the committed override files and the query code, not in anyone's local DB.

One thing left over from this specific server's history, not from the general process: it still has the old stray 12.0.7.68887 patch row sitting inert in the DB (harmless now that the query fix stops it from being read, but dead weight). Since you're already talking about wiping this server, that makes it moot — a fresh deploy never creates that row in the first place, since nothing will ever again run import:spelldata against anything but 12.0.7.68453. If you want to clean the current DB instead of wiping the server, say so and I'll give you the one delete command; otherwise this is a non-issue once you redeploy.

Run the sequence above and check WoW Comps — should be duplicate-free with everything present now.
