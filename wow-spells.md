# WoW Spell & Talent Data System

How raw SimulationCraft/Blizzard data becomes the live, talent-aware "Spells" reference section on a canonical context module page (see CLAUDE.md's "Canonical Context Module Template"). This is a from-scratch architectural walkthrough of the current state — for the investigation history and specific data quirks found along the way, see `game-data.md`; for the day-by-day feature log, see CLAUDE.md's "Game Reference Data Import" / "Talent-Aware Spell Data" / "Blizzard Talent String Import" / "Module-linked talent builds" sections.

## The big picture

```
SimC dumps + Blizzard Game Data API
        │
        ▼
data/spelldata/raw/{class}.txt  ──split-by-tree.php──▶  data/spelldata/filtered/{class}/*.txt
        │                                                        │
        │                                              SpellDataFileParser
        │                                                        │
        ▼                                                        ▼
data/talenttrees/{class}.json                          php artisan import:spelldata
data/pvptalents/{class}.json  ─────────────────────────────────▶ │
                                                                  ▼
                                          spells, spell_effects, spell_relationships,
                                          spell_class_availability, talent_trees,
                                          talent_nodes, talent_node_entries,
                                          talent_node_edges, pvp_talents
                                                                  │
                    ┌─────────────────────────────────────────────┼─────────────────────────────┐
                    ▼                                             ▼                             ▼
        TalentSelectionService                        ModuleSpellReferenceService      BlizzardTalentStringCodec
        (which talents are "selected")                (what to show, given a           (import a real in-game
                    │                                   selection)                      export string)
                    └─────────────────────────────────────────────┘
                                          │
                                          ▼
                          Modules\Show — the "Spells" table on a module page
```

Everything below fills in one box at a time.

## 1. Raw data → filtered files (`data/spelldata/split-by-tree.php`)

SimC spell dumps (`data/spelldata/raw/{class}.txt`) are one flat, undifferentiated list of every spell record for a class. `split-by-tree.php` regroups them, verbatim, into `data/spelldata/filtered/{class}/{tree}.txt` (`baseline.txt`, `class-talents.txt`, `{spec}.txt`, `hero-{name}.txt`), based on each record's `Talent Entry` line(s).

**Record boundaries are `Name :` lines only, not blank lines.** This was a real, previously-unknown bug (fixed 2026-08-01): SimC's dump also uses blank lines to separate *paragraphs within* a single record's Description — not just between records. The old blank-line-delimited splitting silently truncated any spell whose Description had an internal paragraph break (confirmed systemic: dozens of records per class file, across every class checked). A recovered example: Protector of the Frail's Description has a second sentence — "Power Word: Shield reduces the cooldown of Pain Suppression by ... sec" — describing a real mechanic with no other structural representation anywhere in the data, that used to vanish before ever reaching the parser.

**`--specs`/`--heroes` are auto-derived, not hand-typed.** `deriveTreeNames()` scans the input itself for every `tree=spec`/`tree=hero` Talent Entry line and builds the allowlist from the data — no more risk of mistyping a class's exact spec/hero names. `data/spelldata/regenerate-filtered.php` loops this over every file in `raw/` in one command.

## 2. Filtered files → database (`SpellDataFileParser` + `ImportSpellData`)

`SpellDataFileParser::parseContent()` is a dumb, line-oriented reader (not a grammar) — it recognizes a fixed set of `Key : Value` field prefixes and an `Effects:` sub-block. Key behaviors:

- **Description continuation.** A literal-text Description can span multiple paragraphs (see above) — every non-blank line after it is appended (space-joined) until a recognized field, `Tooltip :`, `Effects:`, or the next `Name :` ends it.
- **Effect type extraction is two-part, not one.** An effect line looks like `#3 (id=X) : Apply Aura (6) | Modify Cooldown Charge (Category) (411)` — pipe-separated. Segment 0 (`Apply Aura (N)`) is a generic wrapper meaning "this applies a continuous aura" and carries no distinguishing information; segment 1 (`Modify Cooldown Charge (Category)`) is the actual AuraType that matters. This was a real, previously-undiscovered bug (fixed 2026-08-01): the parser only ever kept segment 0, so any code trying to match on the specific AuraType string (e.g. `"Modify Recharge Time (Category)"`) never matched anything. Fixed to redirect to segment 1 (stripping the trailing internal `(NNN)` id) whenever segment 0 is a bare `Apply Aura*(N)` wrapper *and* a segment 1 exists; effects with no aura wrapper at all (`"School Damage (2): physical"`, `"Direct Heal (10)"`) are untouched.
- **Charges/Cooldown/Duration** are plain scalar fields (`Charges : 2 (20 seconds cooldown)`, standalone `Cooldown :`, `Duration :`) — captured directly into columns, no relationship modeling needed.
- **Category / Affected Spells (Category)** are the *structural* charge/cooldown-modifier link — see §3.
- **`free=(...)` on a class-tree Talent Entry** — a shared `tree=class` talent can be restricted to a subset of the class's specs (e.g. Mind Blast: `free=(Discipline, Shadow)`, never Holy). Found missing entirely 2026-08-01 by cross-checking the real in-game Spellbook — captured into `free_specs`, matched independent of line prefix (a record can repeat this on continuation lines) the same way `replace="..."` already is. `ImportSpellData::resolveFreeSpecIds()` uses it to narrow `spell_class_availability`, the class-tree parallel to `resolveBaselineSpecIds()`.
- **`Not In Spellbook` (Attributes)** — flags a record as an internal SimC sub-spell (a channeled ability's own damage-bolt/heal-bolt/visual-effect helper, sharing the parent's display name but never independently player-facing) rather than a real ability. Found the same day, same cross-check: Penance and Ultimate Penitence are each several spell_id records sharing one name, only one of which is real. Captured into `not_in_spellbook`, consumed by `resolveSpellByName()` — see §4.

`ImportSpellData` (`php artisan import:spelldata {game} {patch}`) reads every class's filtered files, resolves spells (`spellIndex`, keyed by external `spell_id`), and writes everything via `upsertTrack()` (safe to re-run — re-importing unchanged files produces zero writes). It also runs a handful of **global passes**, once, after every class is loaded (because relationships can cross class files):

| Pass | Populates | From |
|---|---|---|
| `importRelationships()` | `spell_relationships` (`modifies`) | `Affecting Spells` / `Modified By` |
| `importCategoryRelationships()` | `spell_relationships` (5 types — see §3) | `Category` / `Affected Spells (Category)` |
| `importReplacesRelationships()` | `spell_relationships` (`replaces`) | Talent Entry's `replace="<name>"` annotation |
| `importPvpTalentRelationships()` | `spell_relationships` (`modifies_cooldown`) | Regex-parsed PvP talent description prose |
| `resolveDescriptionReferences()` | `spells.description` | Backfills `$@spelldesc<id>` pointers (~44% of all Description lines) |
| `resolveBaselineSpecIds()` | `spell_class_availability.spec_id` | `baseline.txt`'s own `Class:` field (that file isn't actually spec-filtered by filename alone) |

## 3. Spell relationships: what modifies what

`spell_relationships` (`source_spell_id`, `target_spell_id`, `relationship_type`, `modifier_value`, `modifier_unit`) is the structural graph — one edge per real, data-confirmed modifier. `relationship_type` is one of:

| Type | Source | Magnitude computed? |
|---|---|---|
| `modifies` | Affecting Spells / Modified By (effect-value modifiers, e.g. a talent's damage-% bonus) | No — no dedicated scalar column exists for arbitrary effect values |
| `modifies_charges` | Category, effect type `Modify Cooldown Charge (Category)` | Yes — flat `+N charges` |
| `modifies_cooldown` | Category, effect type `Modify Recharge Time (Category)`; *or* PvP talent prose (`"X cooldown is reduced by N sec/%"`) | Yes — flat seconds or percent |
| `modifies_cooldown` (not computed) | Category, `Modify Recharge Time% (Category)` / `Modify Cooldown Time (Category)` | No — correctly labeled, no verified conversion yet |
| `modifies_charge_rate` | Category, `Modify Charge Cooldown Recharge Rate% (Category)` | No |
| `hasted_cooldown` | Category, `Hasted Cooldown Duration/Regeneration (Category)` | No — an always-on mechanical tag ("this scales with haste"), not a per-talent modifier |
| `bypasses_cooldown` | Category, `Ignore Spell Charge Cooldown (Category)` | No |
| `replaces` | Talent Entry's `replace="<name>"` | N/A — an action-bar swap, not a magnitude |

**Why 8 Category effect types collapse to only 2 computed ones:** `Category:`/`Affected Spells (Category):` is one shared textual marker for all 8 (`ImportSpellData::categoryRelationshipMapping()` is what splits them apart, added 2026-08-01 — before that, all 8 were mislabeled `modifies_charges` regardless of which). Only the two with a hand-verified worked example (`Modify Cooldown Charge` → Pain Suppression/Protector of the Frail; `Modify Recharge Time` → Mind Blast's Discipline passive, `19000/1000 = 19s`) get a computed number — the rest are correctly typed but descriptive-only ("flag, don't guess," not a gap anyone forgot).

**`modifier_value` is a Laravel `decimal:2` cast** — always cast to `(float)` before arithmetic.

## 4. Rendering (and seed-time name resolution): `ModuleSpellReferenceService`

**`resolveSpellByName(name, ModuleGameBuild)` — seed-time only**, used once when a module's curated spell list is authored (e.g. `DiscPriestOracleModuleSeeder`), to turn a name from the module's prose into a concrete `spell_id`. A name isn't always unique: as of 2026-08-01, the first disambiguation step is `preferVisible()` — narrows same-name candidates to non-`not_in_spellbook` spells whenever at least one exists (never drops every candidate to zero). Found necessary by cross-checking the real in-game Spellbook: "Penance" resolves to **11** distinct `spells` rows (internal damage-bolt/heal-bolt/visual-effect sub-spells sharing the display name), and without this step a seeder could silently resolve to the wrong one. After that: prefer a copy that's an actual talent pick in the build's own trees, then one whose availability matches the build's spec, then one with cooldown/charge data, else the first — not expected to be perfect, a wrong resolution is a one-line fix in the seeder's `attach()` call, not a system failure.

The remaining methods are render-time — called on every page load so results always reflect current game data, never a frozen snapshot. Given a curated spell and a `ModuleGameBuild` (a module's own declared class/spec/hero-tree — see CLAUDE.md's Canonical Context Module Template), plus a set of currently-*selected* spell IDs (§5):

- **`resolveDescription(Spell, ModuleGameBuild)`** — resolves SimC's raw tooltip syntax (`$s1`, `$d`, `$<id>s1`, `${...}` arithmetic, `$?a<id>[A][B]` conditionals) into real text, scoped to the given build's own kit (a conditional is decided by checking whether the referenced spell/aura belongs to that build's own kit). Genuinely unresolvable tokens (`$?c<n>` condition codes, truncated source data) get honest copy ("varies by condition — check in-game"), never a guessed number, and are logged.
- **`modifiersFor(Spell, ModuleGameBuild, ?selectedSpellIds)`** — walks `incomingRelationships` (plus a text-mention scan for proc-style relationships with no `spell_id` link at all, e.g. Borrowed Time → Mind Blast via a shared Haste stat) and splits results into two buckets:
  - `'named'` — a specific modifier, gated by selection (`$selectedSpellIds->contains($candidate->id)`) *and* a stricter "confidently in this build" check (an actual talent pick in one of the build's own trees, or explicitly tagged to this class/spec — not the loose class-wide-no-spec-qualifier fallback, which was confirmed to leak Shadow-only mechanics into a Discipline module).
  - `'baseline'` — generic always-on class-wide passives (a spell literally named `"Priest"` / `"Discipline Priest"`) — never gated by selection, shown once at the bottom of the section instead of repeated under every row.
- **`effectiveCooldown()`** / **`effectiveCharges()`** — both thin wrappers around a shared private `effectiveScalarValue()`: start from the spell's base value (`cooldown_seconds` / `charges`), filter `modifiersFor()`'s `'named'` list to the matching `relationship_type` (`modifies_cooldown` / `modifies_charges`) with a non-null magnitude, apply flat-unit modifiers first, then percent. Returns `{value, base, applied}` so the UI can show a struck-through "was N" alongside the new number.

None of this is ever baked into stored content — it's computed fresh on every page load, which is the whole point (a patch changing a number can't silently go stale in prose the way it could in a hand-written guide).

## 5. Talent selection: `TalentBuild` and its three scopes

A `TalentBuild` is a full loadout: PvE tree picks (`talent_build_choices`, one row per `talent_node_id → chosen_entry_id`) plus PvP picks (`talent_build_pvp_choices`, a flat ordered list — PvP talents have no tree/node structure, so a build's whole PvP selection is replaced-not-appended via `syncPvpChoices()`, never tracked per-slot). A build is scoped exactly one of three ways:

| Scope | Columns | Written by |
|---|---|---|
| Personal | `user_id` + `spec_id` (unique — structurally one build per user per spec) | A user's own picks (no live UI currently mounts this — see §7) |
| Spec-wide default | `is_default = true`, `user_id = null` | `Admin\TalentBuildEditor` (`/admin/talent-builds`) |
| Module-linked | `module_id` (nullable, unique) | Curated per-module, e.g. a real Blizzard import (§6) |

`TalentSelectionService` is the single place that resolves "what's selected" and reads/writes choices:

- **`resolveActiveBuild(?User, specId)`** — user's saved build → spec's admin default → an unsaved, empty shell (falls back to base/unmodified data).
- **`resolveBuildForModule(Module)`** — the module's own linked build, if one exists *and has at least one choice* (an empty linked build is treated as "not linked," same discipline as the unsaved-shell fallback above) — else `null`.
- **`selectedSpellIds(TalentBuild)`** — flattens both PvE and PvP picks into one plain `Collection` of spell IDs. This is *all* `ModuleSpellReferenceService` ever consumes — it has no concept of nodes, ranks, or choices, only "is this spell id currently selected."

**Actual resolution order on a module page** (`Modules\Show::initSelectedSpellIds()`): **module's own linked build → viewer's saved build → spec's admin default → empty.** A module's own build wins because it represents the content author's deliberate choice for that specific guide — the same reasoning as why `ModuleGameBuild.hero_talent_tree_id` is locked to the module rather than left to the viewer's preference.

## 6. Importing a real loadout: `BlizzardTalentStringCodec`

Decodes the same "Export" string Wowhead/Raidbots/the in-game copy button produce — a literal port of Blizzard's own client Lua, not a reverse-engineering guess. Format: standard base64 alphabet, but bits are packed **LSB-first across 6-bit groups** (not `base64_decode()`-compatible — hand-rolled `BlizzardBitReader`). Header: 8-bit version, 16-bit spec ID, 128-bit tree hash (read and discarded — Blizzard's own client sanctions third-party tools zero-filling it). Content: one bit per talent node — is-selected → if selected: is-purchased → if purchased: is-partially-ranked (+ranks) → is-choice-node (+choice-index).

**The node ordering is the one part of this that's genuinely subtle, and was wrong until 2026-08-01.** `external_node_id` is only unique *within one tree*, not globally — confirmed directly in `data/talenttrees/priest.json`, where node id `94691` appears at four separate byte offsets (Priest's class tree, Holy tree, Discipline tree, *and* the Oracle hero tree, simultaneously). The original code queried every node across the whole class and sorted them in one global pass by that column — meaningless once IDs collide across trees, and confirmed as the actual cause of a real decode producing an impossible result (talents selected from Holy *and* Shadow *and* Discipline at once, in a string that can only ever be one spec).

**Fixed via `orderedNodesForSpec()`:** nodes are read in three separate ordered blocks — class tree (own order), then *only* the target spec's own tree (own order — never another spec's tree), then every hero tree available to that spec (own order per tree, trees tie-broken by `TalentTree.id` — the one remaining unverified assumption, flagged in the class docblock). Verified against a real string: now correctly resolves Protector of the Frail (previously absent entirely) among 85 total selections, all real Discipline/Oracle talents.

**Preview before apply, always.** `TalentSelector::previewImport()` decodes into a human-readable spell-name list; nothing is written to `talent_builds` until `applyImport()` is explicitly clicked. Two remaining unverified assumptions are flagged in the class docblock (choice-index → `talent_node_entries` ordering; hero-tree-selection bit semantics) — mitigated by this same preview step, not treated as proven.

## 7. Module-linked talent builds — current state and known gap

As of 2026-08-01, `TalentSelector` (the interactive picker UI) is mounted in exactly one place: `Admin\TalentBuildEditor`, in `isDefaultEditor` mode, authoring the spec-wide admin default. **It is no longer embedded on module pages at all** — removed because a saved build is a cross-module, per-spec preference, not something that belongs on any one module's page, and because it was the repro path for a client-side Alpine/Livewire DOM-morph bug (confirmed server-rendered HTML was correct both before and after a talent change — the bug was in the browser's paint, not the data).

**There is currently no interactive UI for linking talents to a specific module.** The Discipline Priest (Oracle) module's build was populated the way described in §6 — decode a real string, then:

```php
$service = app(TalentSelectionService::class);
$build = $service->getOrCreateModuleBuild($module, $specId, $patchId);
foreach ($decodedSelections as $row) {
    $service->saveChoice($build, $row['node'], $row['entry']);
}
```

run once, ad hoc. Building a real UI for this (e.g. a third `TalentSelector` mode pointed at a module's build instead of a user's/admin's, surfaced as a synthetic tab alongside a module's real `ModulePage`-driven tabs, gated to the module's creator) was analyzed but not built — a deliberate scope decision, not an oversight. `resolveBuildForModule()` doesn't care *how* the build was populated, so this is a pure UI gap, not a data-model one.

## 8. End-to-end: what happens when someone opens a module page

1. `Modules\Show::mount()` calls `initSelectedSpellIds()` — resolves the selected-spells `Collection` per §5's order, purely server-side, once, on page load. No live picker on this page anymore, so this is static for the page's lifetime.
2. `getModuleSpellReferencesProperty()` loops the module's curated `spellReferences` (a separate, seeder/creator-curated pivot — "spells named in this guide's prose," distinct from *which talents are selected*) and calls `resolveDescription()` / `modifiersFor()` / `effectiveCooldown()` / `effectiveCharges()` for each.
3. The blade table renders spell name, resolved description, computed cooldown (with a struck-through base value if a modifier changed it), computed charges (same), and a badge per named modifier (colored/labeled by `relationship_type`) — plus a once-at-the-bottom line for baseline class-wide passives.

Nothing here is cached or snapshotted — change the module's linked build, the admin default, or the underlying game data via a re-import, and the next page load reflects it immediately.

## Updating for new SimC/Blizzard data

Two different scenarios, with very different amounts of work.

### A. Same patch, refreshed/corrected data (no new `build_version`)

1. Replace `data/spelldata/raw/{class}.txt` with the new dump(s).
2. `php data/spelldata/regenerate-filtered.php` — regenerates every class's filtered files in one command; read each class's printed "Anomalies" section (an unmatched Talent Entry line — the record itself is never lost, it falls back to `baseline.txt`).
3. `php artisan import:spelldata wow <same-patch-version>` (no `--current` needed). `upsertTrack()` means unchanged rows produce zero writes, changed values update in place, new spells/effects get created — safe to re-run.
4. **Caveat, already hit twice this session (baseline.txt's spec-id fix, the `modifies_charges` relabeling):** this is only safe when the correction changes *values*, not *which natural key a row matches on*. If a fix changes how something gets *classified* (e.g. `relationship_type`), re-importing in place inserts the newly-correct row **alongside** the old, now-wrong one rather than replacing it (`upsertTrack()` only creates/updates, never deletes). In that case, `migrate:fresh` + re-import is the only safe path, not an in-place re-import.
5. Worth cross-checking the refresh against `knowledge-gaps.md`'s open, flagged discrepancies — a data correction sometimes resolves one of them.

### B. A genuinely new patch (new `build_version`)

1. **Talent trees + PvP talents**: `php data/talenttrees/fetch-talent-trees.php` — pulls fresh from Blizzard's Game Data API (needs `BLIZZARD_CLIENT_ID`/`BLIZZARD_CLIENT_SECRET` in `.env`). `--only=ClassName` for a fast partial run.
2. **Spell data has no fetch script** — unlike talent trees, `data/spelldata/raw/{class}.txt` has no automated pull; a new SimC dump has to be obtained manually. (`data/pvptalents/diff-against-simc.php`, referenced in code comments as the tool that originally validated PvP-talent coverage against SimC, is also not actually present in this repo — a real tooling gap if that audit needs re-running for a new patch.)
3. `php data/spelldata/regenerate-filtered.php`.
4. `php artisan import:spelldata wow <new-patch-version> --current`. `Patch::markCurrent()` atomically flips `is_current` off the old patch. Because `spells`, `talent_trees`, `talent_nodes`, `talent_node_entries`, and `pvp_talents` are all scoped by `patch_id` as part of their natural key (`resolveOrCreateSpell()` matches on `(patch_id, spell_id)`, for example), this step creates **entirely new rows** for the new patch — the old patch's rows are untouched, not deleted, just no longer current.
5. **The consequence, not yet fully handled anywhere:** almost everything that references a specific spell/node/tree by internal id is now pointing at the *old* patch's rows, and none of it re-links automatically:
   - `module_spell_references` (which spell a module's Spells section shows) points at the old patch's `spells.id` — needs re-resolving against the new patch (re-running whatever originally resolved each curated spell name, e.g. `ModuleSpellReferenceService::resolveSpellByName()`, against the new patch's spells).
   - `ModuleGameBuild.hero_talent_tree_id` points at the old patch's `talent_trees.id` — needs updating to the new patch's equivalent tree.
   - The admin-default `TalentBuild` lookup (`resolveActiveBuild()`) *does* filter by `patch_id` — so after a new patch goes current, an old default build simply stops being found (falls through silently to "empty," not an error) until someone creates a new one for the new patch via `/admin/talent-builds`.
   - **`TalentSelectionService::resolveBuildForModule()` and the personal-build half of `resolveActiveBuild()` have no `patch_id` filter at all** (confirmed by re-reading both — this is a real, previously-unnoticed gap, not something fixed as part of today's module-linked-build work). A module-linked build (like the Discipline Priest Oracle one) would keep being "found" after a new patch import, but its `talent_build_choices` still reference the *old* patch's `talent_node_id`/`chosen_entry_id`. Since the new patch's `spell_relationships` graph has no overlap with those stale, old-patch spell ids, this doesn't crash — every modifier/charge computation for the new patch just silently comes back as "nothing selected," which is a quiet, easy-to-miss regression on every canonical module the first time a new patch goes live. Worth fixing (either a patch-filtered lookup, or a re-link step in the new-patch process) before this project has more than one canonical module riding on it.

## Known gaps (not fixed here, not forgotten)

- No UI for linking talents to a module (§7) — the data model and resolution fully support it, only the picker-mode UI is missing.
- `modifies_cooldown`'s two non-computed Category effect types, `modifies_charge_rate`, `hasted_cooldown`, `bypasses_cooldown` — all correctly labeled, none have a verified magnitude conversion yet (needs its own hand-verified worked example per type before adding one — see `game-data.md`'s "flag, don't guess" precedent).
- `modifies` (arbitrary effect-value modifiers, e.g. a talent's flat damage-% bonus) has no magnitude computation at all — there's no scalar column on `spells` to compute an "effective" value into the way there is for cooldown/charges, and a chunk of these are proc-gated (inherently non-deterministic) rather than flat modifiers, so this isn't just an oversight to close.
- Automated extraction of recovered multi-paragraph description text (§1) into new structured `spell_relationships` rows — currently just visible as prose on the spell's own row, not turned into a new relationship.
- Haste-mediated cooldown scaling (a spell's category tagged `Hasted Cooldown Duration`) is detected and shown descriptively (`hasted_cooldown`) but never folded into `effectiveCooldown()`'s computed number — doing so would need the viewer's actual Haste%, which isn't tracked anywhere in this system.
