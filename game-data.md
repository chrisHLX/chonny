# Game Reference Data Import (`data/spelldata`, `data/talenttrees`, `data/pvptalents`)

Raw WoW reference data pulled from SimulationCraft-format spell dumps and the Blizzard Game Data API, imported into the relational schema via `php artisan import:spelldata {game} {patch} [--current] [--only=class1,class2]` (`app/Console/Commands/ImportSpellData.php`). This is the "Raw Game Data" input referenced in CLAUDE.md's Canonical Context Module Template section — it exists to ground/verify expert-dictated module content (e.g. the Feral Druid SimulationCraft cross-check), not to generate modules itself.

## Three source folders, one class-based layout, joined by `spell_id` (not filename)

- **`data/spelldata/filtered/{class}/*.txt`** — SimulationCraft spell query dumps, one folder per class (`priest`, `demonhunter`, etc.), split into `baseline.txt` (always-available spells), `class-talents.txt`, `hero-*.txt` (one per hero talent tree), and one file per spec (`discipline.txt`, `holy.txt`, `shadow.txt`, ...). Parsed line-by-line by `App\Http\Services\SpellDataFileParser` (deliberately dumb/line-oriented, not a grammar — see its docblock) into `spell_id`, `name`, `school`, `description`, `charges`, `cooldown_seconds`, `effects[]`, plus two *pairs* of structural relationship fields: `affecting_spells`/per-effect `modified_by` (effect-value modifiers, e.g. "this talent increases that spell's damage") and `category_refs`/per-effect `affects_category` (charge-count modifiers, e.g. "this talent grants that spell an extra charge") — all four are `Name (id=X)` references to *other* spells, not free text. This is the **primary source of spell records** — `spells` + `spell_effects` are written from here.
- **`data/talenttrees/{class}.json`** — fetched from the Blizzard Game Data API (`fetch-talent-trees.php`). Has `class_talents.nodes`, `specs.{SpecName}.nodes`, `hero_talent_trees[].nodes`. Each node has `ranks[].choices[].spell_id` — this is a **structural overlay**, not a second source of spell data: `resolveOrCreateSpell()` first checks the in-memory spell index built from spelldata, and only creates a stub `spells` row if that `spell_id` wasn't already found in the `.txt` files. Drives `talent_trees` / `talent_nodes` / `talent_node_entries` / `talent_node_edges`.
- **`data/pvptalents/{class}.json`** — also Blizzard API. `specs.{SpecName}.pvp_talents[]`, each with a `spell_id` resolved the same way (via `resolveOrCreateSpell()`) into `pvp_talents` + a `spell_class_availability` row (`source = 'pvp_talent'`).

## The join key

**Everything normalizes onto one `spells` table keyed on `(patch_id, spell_id)`** — `spell_id` is Blizzard's external spell ID, the sole join key across all three folders. There is no filename-based or class-name-based join between spelldata/talenttrees/pvptalents beyond fuzzy folder/file matching (see below) — the actual data relationship is spell_id-based, resolved in memory during a single import run (`ImportSpellData::$spellIndex`, keyed by external `spell_id`, accumulated across the whole run so a spec-tree spell defined in one class's baseline can resolve against a pvp-talent reference imported later).

**Filename matching across folders is fuzzy, not exact.** `data/talenttrees/demon-hunter.json` vs `data/spelldata/filtered/demonhunter/` — `loadMatchingJson()` uses `normalizeSlug()` (strip non-alphanumerics, lowercase) to pair up folder/file names across the three sources rather than requiring identical naming.

## Downstream tables and what each represents

| Table | What it links |
|---|---|
| `spells` / `spell_effects` | Canonical spell record + effect breakdown, from spelldata. `spells.charges`/`spells.cooldown_seconds`/`spells.duration_seconds` are nullable scalars (a spell has at most one Charges-or-Cooldown line and at most one finite Duration line in the dump) — null means "not present/finite in the dump" ("Duration: Aura (infinite)" also parses to null), not zero. `duration_seconds` exists specifically to resolve the "$d" tooltip token in descriptions — see `ModuleSpellReferenceService` |
| `spell_class_availability` | Many-to-many: which spells are available to which class/spec, tagged with `source` (`baseline`/`talent`/`pvp_talent`). For `talent`/`pvp_talent` rows, `spec_id` is filename-derived via `classifyFileSource()` — *except* a `tree=class` talent whose own Talent Entry line carries a `free=(SpecA, SpecB)` annotation, which `resolveFreeSpecIds()` narrows the same way baseline records get narrowed (see below and the "Class-availability gaps" section further down). For `baseline` rows, `spec_id` is refined per-record by `resolveBaselineSpecIds()` from that record's own `Class:` field — see note below |
| `talent_trees` / `talent_nodes` / `talent_node_entries` / `talent_node_edges` | Talent tree structure (class/spec/hero trees), from talenttrees JSON, entries point at `spells.id` |
| `pvp_talents` | Spec + spell + unlock level, from pvptalents JSON |
| `spell_relationships` | Three `relationship_type` values, all spell_id-to-spell_id and cross-class-aware: **`modifies`** (effect-value modifiers, from `affecting_spells`/`modified_by`, built by `importRelationships()`), **`modifies_charges`** (charge-count modifiers, from `category_refs`/`affects_category`, built by `importCategoryRelationships()`), and **`replaces`** (action-bar spell replacement, from `replaces_refs`, built by `importReplacesRelationships()`) |
| `talent_nodes.type` | `PASSIVE` / `CHOICE` / `ACTIVE`, straight from Blizzard's own `node_type` field — no inference. See "Talent choice nodes are already modeled" below |
| `talent_tree_specializations` | Pivot: which specs a **hero** tree is available to (always exactly 2, by game design — e.g. Voidweaver is Discipline+Shadow). Populated by `scanHeroTreeSpecs()`, not derived from the imported talenttrees JSON — see note below |

## Four global passes

Run once, after every class is imported (not per-class), because a reference can point at a spell defined in a different class's file:
- `importRelationships()` — populates `spell_relationships` (`modifies`) from `affecting_spells` / `modified_by`. Both fields are self-declared on the *target* spell's own record.
- `importCategoryRelationships()` — populates `spell_relationships` (`modifies_charges`) from `category_refs` / `affects_category`. Unlike the pair above, these two ends live on **different** records: the target spell's own `Category:` line names its source(s) (e.g. Pain Suppression's `Category:` line names Protector of the Frail), while the granting spell's own effect names its target(s) (e.g. Protector of the Frail's effect has `Affected Spells (Category): Pain Suppression`). Both directions are read and de-duped in memory before writing, so the pass is robust to either side of a pair being incomplete in the dump.
- `importReplacesRelationships()` — populates `spell_relationships` (`replaces`) from `replaces_refs`, parsed from a Talent Entry line's `replace="<name>" (id=<id>)` annotation (e.g. Beacon of Virtue's own Talent Entry names Beacon of Light). Both ends live on the *same* record here (the talent's own spell_id is unambiguously the source) — closer to `importRelationships()`'s single-record pattern than the Category pass's two-record one.
- `resolveDescriptionReferences()` — SimC prints `$@spelldesc<id>` instead of duplicate text (~44% of Description lines in this dataset) — this follows that pointer chain (bounded to 5 hops, cycle-guarded) to backfill real description text after the whole cross-class spell index exists.

**Scope of the `replace=` annotation** (checked 2026-07-25): 66 occurrences across the full dataset — 61 on the primary `Talent Entry` line, 5 on indented continuation lines belonging to shared/multi-tree talents (e.g. Frostfire Bolt replaces Frostbolt in its Frost-tree variant but Fireball in its Fire-tree variant — different targets per line, which is why `replaces_refs` is a ref list, not a scalar). Matched independent of line prefix rather than anchored to `^Talent Entry`, since `replace=` is confirmed to never appear anywhere else in the dataset (66 total substring occurrences = 61 + 5 exactly).

**Scope of the charges/cooldown/category fields** (checked 2026-07-24, before adding these columns): across the full dataset (12,569 spell records), 217 have an explicit `Charges:` line and 925 have a standalone `Cooldown:` line (~9.1% combined) — a small tail, not another 44%-style situation. `Category:` lines appear on 420 records, of which 196 carry a source-spell ref list; `Affected Spells (Category):` appears on 431 effect entries. Both are comfortably smaller than the description-pointer case that motivated the "parse first, resolve in a final pass" pattern these two new passes reuse.

## `modifies_charges` is a coarser label than the data actually supports (found 2026-07-24, not yet fixed)

`Category:` / `Affected Spells (Category):` is the shared textual marker for **8 distinct SimC effect types**, not one mechanism — confirmed by counting every `Apply Aura | ... (Category)` effect type across the dataset:

| Effect type | Rows | Meaning |
|---|---|---|
| Modify Cooldown Charge (Category) | 155 | grants an extra charge — what `modifies_charges` was actually built for |
| Modify Recharge Time (Category) | 88 | flat time added/removed to the cooldown |
| Modify Recharge Time% (Category) | 64 | percentage cooldown change |
| Hasted Cooldown Duration (Category) | 58 | cooldown scales with haste |
| Modify Charge Cooldown Recharge Rate% (Category) | 48 | percentage recharge-rate change |
| Ignore Spell Charge Cooldown (Category) | 34 | bypasses cooldown under some condition |
| Modify Cooldown Time (Category) | 12 | direct cooldown-time modifier (non-charge spells) |
| Hasted Cooldown Regeneration (Category) | 1 | rare haste-scaling variant |

`ImportSpellData::importCategoryRelationships()` currently labels **every** one of these `modifies_charges` regardless of which. That means roughly 305 of ~460 relationships currently tagged `modifies_charges` are actually cooldown-duration/rate/haste modifiers, not charge-count grants — a mislabeled majority, not a minor miss. The data to fix this already exists (`SpellEffect.type` already stores the exact string for the source spell's own effect); the write path just doesn't thread it through to `relationship_type` yet. Flagged and deliberately deferred (explicit user decision, 2026-07-24) — do not rely on `modifies_charges` to mean "grants a charge" until this is split.

## A spell's effective cooldown isn't one column — worked example (Mind Blast, Discipline Priest, confirmed 2026-07-24)

Traced end-to-end from data alone (no AI, no guessing) after a real in-game discrepancy report (data said 9s, player saw ~30s):
- `spells.cooldown_seconds` holds the **base** value only (9s) — before any spec/talent/haste modifier.
- Discipline Priest's own spec-passive (`137032`) has a `Modify Recharge Time (Category)` effect, `Base Value: 19000` (19s), pointed at Mind Blast's cooldown category — today written as a `modifies_charges` relationship (see the limitation above; it's actually a duration modifier). 9s + 19s = 28s baseline for Discipline specifically.
- The universal Priest baseline passive (`137030`) separately tags that same category `Hasted Cooldown Duration (Category)`, `Base Value: 100` — meaning the 28s recharges 100% with haste. This tag is *why* haste matters for Mind Blast at all; not every cooldown is haste-scaled, so this has to be checked per-spell, not assumed.
- Borrowed Time (Discipline talent, 2/2 ranks, 5% per rank confirmed from the talent's own effect data) is a wholly separate spell with **no reference to Mind Blast anywhere** — it grants +10% Haste for 4s on a Power Word: Shield cast. It only affects Mind Blast because both happen to touch the Haste stat, not because of any spell_id link. Math checked against the player's real numbers: `24s / 1.10 ≈ 21.8s`, matching their reported "21s" almost exactly.

**Modeling implication:** "what affects this spell's cooldown" splits into two genuinely different query shapes, both fully derivable from data with no AI: a `spell_relationships` graph walk for anything that references the spell directly, and a `spell_effects.type LIKE '%Haste%'`-style tag join for anything that only connects through a shared stat with no spell_id link at all. Confirmed empirically that every haste-related effect type across the dataset (9 variants, ~220 rows) literally contains the word "Haste" in its type string, so the stat-sharing case is a plain substring match, not a hardcoded list.

## baseline.txt is not actually spec-filtered (fixed 2026-07-24)

`classifyFileSource()` tags every record in `baseline.txt` as class-wide (`spec_id = null`) purely from the filename — but SimC's baseline dump isn't spec-aware: it includes spec-restricted baseline passives (e.g. Discipline/Holy/Shadow Priest's own signature abilities) mixed in with genuinely universal ones, indistinguishable by file alone. Each record's own `Class:` field (parsed by `SpellDataFileParser` into `class_field`, e.g. `"Shadow Priest"`, `"Discipline Priest, Holy Priest"`, `"Paladin, Priest"`, or the generic `"Priest"`) carries the real signal, and `ImportSpellData::resolveBaselineSpecIds()` uses it to write spec-specific `spell_class_availability` rows instead of a single class-wide one whenever it can confidently resolve one or more named specs of the current class.

**Deliberately conservative:** falls back to `spec_id = null` (today's behavior) whenever the field is the bare class name, unparseable, or names only other classes (shared consumables/racials/oddities like `"Unknown Priest (1452)"`) — a false-narrow reading would make a spell silently disappear from a spec that should see it, which is worse than the over-broad status quo it replaces. Only refines `baseline.txt`; `class-talents.txt`/`hero-*.txt`/spec files were already correctly attributed by `classifyFileSource()` and are untouched.

**Re-importing after this change onto an already-populated DB will leave stale `spec_id = null` rows** alongside the new spec-specific ones for any spell this refines (upsertTrack only creates/updates, never deletes) — run `migrate:fresh` + re-import rather than importing in place if the DB already has baseline data from before this fix.

**The data fix alone wasn't enough — the query reading it had the same bug independently (found and fixed 2026-07-25).** `GameDataBrowser::getBaselineSpellsProperty()` (the "Baseline Abilities" section) predates `resolveBaselineSpecIds()` — it was written back when every baseline row was uniformly `spec_id = null`, so filtering on `spec_id` there would've been a no-op at the time. It was never revisited after the fix above landed, so it kept doing `whereHas('classAvailability', fn ($q) => $q->where('class_id', ...)->where('source', 'baseline'))` with no `spec_id` condition at all — meaning Discipline's Baseline Abilities section kept showing Shadow-only spells like Vampiric Embrace (correctly tagged `spec_id = Shadow` in the DB) regardless, because the query simply never looked at that column. `getPassiveLikeSpellsProperty()` inherited the same leak, since it's built by aggregating `$this->baselineSpells`. Fixed to filter `whereNull('spec_id')->orWhere('spec_id', $specId)`, the same pattern `getTopCooldownSpellsProperty()` (written after the data fix, so it got this right from the start) already used. **Lesson generalized:** fixing a data-shape bug at the write side doesn't retroactively fix read-side queries written against the old shape — each consumer needs checking individually, not just the pipeline that produces the data.

## baseline.txt pet/summon spells can also leak across hero trees (found 2026-07-24, not yet fixed)

The `Class:` field fix above only disambiguates by *spec* — it doesn't know about hero trees at all. `baseline.txt` also contains pet/summon "cast" spells for hero-talent mechanics (e.g. Voidweaver's Voidwraith), generically tagged `Class: Priest`, that leak into every Discipline/Shadow view regardless of which hero tree (Voidweaver vs Oracle) is actually selected. `GameDataBrowser::getTopCooldownSpellsProperty()` compounds this — it doesn't filter by `heroTreeId` at all yet, a separate, simpler bug.

**A real structural signal exists to fix the attribution, not yet built:** hero-talent descriptions embed direct spell_id references via SimC's `$<id><field>` variable syntax — e.g. Voidweaver's Voidwraith talent (`451234`) has description text `"...a Voidwraith is summoned... for $451235d"`, where `451235` is the baseline pet's own id. This is the same token family as `$@spelldesc<id>` (already parsed for description-pointer resolution, see above) — extending the parser to also extract plain `$<id>` references from description text would let baseline pet spells be attributed back to whichever hero-tree talent summons them.

**Known dead end, for contrast — not every gap has a data trail to chase.** Premonition of Clairvoyance (`440725`, baseline, generic `Class: Priest`) is gated behind an `Override Triggered Action Spell` effect pointing at spell `428924` — which does not exist anywhere in our dataset, including the raw unfiltered dump (`data/spelldata/raw/priest.txt`). The only way to know it's actually Archon-only (and therefore Holy/Shadow-only — confirmed via Archon's own `Talent Entry` lines, which list only `(Holy)` / `(Shadow)` / `(Holy, Shadow)`, never Discipline) was recognizing "Premonition" as an Archon mechanic from general knowledge, not from anything derivable in our data. This is the concrete example behind the "AI-assisted modeling" note in CLAUDE.md — some gaps here genuinely have no structural trail to follow, no matter how the parser is extended.

## Hero-tree-to-spec mapping (fixed 2026-07-25)

Hero trees are class-wide in `talent_trees` (`spec_id` is always `null` there — see `importTalentTrees()`), but in reality a hero tree belongs to exactly two specs by game design (e.g. Voidweaver is Discipline+Shadow, never Holy). Two candidate fixes were investigated and rejected before landing on the one that works:

1. **Re-fetch from Blizzard's Game Data API** — rejected. Confirmed live (called `/data/wow/talent-tree/{treeId}/playable-specialization/{specId}` directly for all three Priest specs) that `hero_talent_trees` returns the *identical* list regardless of which spec is in the URL — Discipline includes Archon, which is provably wrong (Archon's own node text confirms Holy/Shadow only). This is a genuine Blizzard API limitation, not a bug in `fetch-talent-trees.php` — re-fetching reproduces the same wrong data every time, so `data/talenttrees/{class}.json`'s `hero_talent_tree_ids` field can never be trusted for this and is not used anywhere in the importer.
2. **One-time hand-patch of the JSON files** — rejected. Would go stale silently the next time `fetch-talent-trees.php` re-fetches for a new patch, with nothing to catch it.

**What's actually used:** `ImportSpellData::scanHeroTreeSpecs()` regex-scans `hero-*.txt` files directly (bypassing `SpellDataFileParser` — this is a per-*file* aggregate, not a property of any individual spell record). Every `tree=hero` `Talent Entry` line carries a `(SpecList)` qualifier immediately after the hero tree's name — confirmed 100% consistent across all 753 such lines in the dataset (e.g. `Archon (Holy) [tree=hero, ...]`, `Voidweaver (Discipline, Shadow) [tree=hero, ...]`) — the union of every spec name seen across a file is that hero tree's full eligible-spec set. Written to the new `talent_tree_specializations` pivot (`TalentTreeSpecialization` model), resolved during `importTalentTrees()`'s hero-tree loop by matching the scanned hero-tree-name slug against each `heroData['name']` from the JSON (same `normalizeSlug()`-based fuzzy matching `loadMatchingJson()` already uses).

`GameDataBrowser::getHeroTreesProperty()` now requires a spec to be selected and filters through this pivot; `updatedSpecId()` clears `heroTreeId` if the previously-selected hero tree isn't valid for the newly-selected spec (necessary now that the filtering is real — before this fix, keeping the same id selected across a spec change was harmless since hero trees were shown unfiltered either way).

## Talent "choice" nodes are already modeled — just not surfaced everywhere (confirmed 2026-07-24)

`talent_nodes.type` (Blizzard's own `node_type` field: `PASSIVE` / `CHOICE` / `ACTIVE` — 4,468 / 1,014 / 731 across the dataset, no inference involved) already distinguishes toggle/either-or talent picks from ordinary single-pick nodes. E.g. Warrior's Intimidating Shout vs Piercing Howl share one `CHOICE` node with a single rank containing both as alternative `choices[]` — picking one is what swaps the spellbook slot in-game. `resources/views/components/admin/talent-tree-section.blade.php` already renders this (groups `talent_node_entries` by rank, labels any rank with more than one entry `"Choice (rank N) — pick one"`, shows the node's `type` badge) — built well before this session, just not something anyone had gone looking for. **Gap:** `GameDataBrowser::getTopCooldownSpellsProperty()` queries `Spell` directly and bypasses `talent_nodes` entirely, so a spell that happens to come from a `CHOICE` node loses that context in that section specifically — not yet fixed.

## Matching a trusted ability list against this data (validated 2026-07-24, not automated)

Rather than trying to make the importer autonomously *exclude* noise (the baseline/hero-tree/Premonition problems above), matching named abilities from an external trusted source (e.g. an actual in-game Spellbook screenshot) against `spells` and *enriching* from there worked cleanly in a spot-check: 8 of 10 Arms Warrior abilities from a real screenshot resolved to an unambiguous spell_id and correct cooldown/charges data. The other 2 (Bladestorm — 4 duplicate ids across files; Execute — 7 duplicate ids) are resolvable the same general way, not one-off exceptions:
- **When the ability is a talent pick**, `talent_node_entries.spell_id` (from the talent tree JSON, already imported) gives the exact id for that spec with zero ambiguity — sidesteps duplicate-id problems entirely rather than guessing from the name.
- **When it's a baseline/class-wide ability**, prefer whichever duplicate is tagged with the current spec specifically, then whichever duplicate actually carries a `Charges`/`Cooldown` line.
- **When neither disambiguates** (Execute's 7 ids, none with cooldown data), it usually doesn't matter for cooldown/charges display purposes — there's no value on any duplicate to get wrong.

Once a trusted spell_id is resolved, walking `spell_relationships` / `talent_node_entries` / `pvp_talents` outward from it for modifiers is the same direction that's worked reliably all session (Pain Suppression → Protector of the Frail, Mind Blast → Discipline's aura) — the fragile direction was always starting from nothing and inferring what's real, not enriching a known-good spell. **Built 2026-07-25** as `ModuleSpellReferenceService`, backing the "Spells" section on canonical context module pages — see below.

## Descriptions still contain raw SimC tooltip syntax (`$s1`, `$d`, `${...}`, `$?a/$?s/$?c[...][...]`) — partially resolved 2026-07-25

`spells.description` was always stored verbatim, including SimC's unevaluated tooltip placeholders — real values require substituting `$s1`/`$s2`/... (the spell's own `spell_effects` at that index), `$d` (its own `duration_seconds`), cross-spell references (`$<id>s1`, `$<id>d`), `${...}` arithmetic, and `$?a<id>[A][B]`/`$?s<id>[A][B]` conditional branches. `ModuleSpellReferenceService::resolveDescription()` resolves these **for a specific build's context only** (conditionals are decided by checking whether the referenced spell/aura belongs to that build's own kit — sound for a page that's about one fixed build, not sound as a context-free general-purpose resolver).

**Known, deliberate limits, not bugs:**
- `$?c<n>[A][B]` condition codes aren't confidently interpretable (unclear SimC-format semantics) — rendered as `"(varies by condition — check in-game)"` and logged, never guessed.
- A `spell_effects` row with `base_value = 0` **and** `scaled_value = 0` is treated as unresolved, not "the effect is zero" — confirmed real case: Mind Blast's own damage effect is `0`/`0` in our schema because the actual number lives in `SP Coefficient` (`0.78336`), a field we don't capture at all. Silently rendering "0 damage" would be a confidently wrong number, worse than an honest placeholder.
- At least one description in this dataset is genuinely truncated **at the source**, not by our parser — Mind Blast's raw record ends mid-conditional (`...$?s137033[`) with no closing brackets, verified directly against the raw file (a blank line, then the next spell record, immediately after). `resolveDescription()` detects and cleanly truncates a dangling unterminated trailing conditional rather than showing broken syntax, and flags it.
- `t`/`w`/`m`/`A`/`u` token suffixes are never resolved (only `s`/`d`) — genuinely unconfirmed semantics, left as `"(varies)"` rather than guessed.

## Modifier lists need a stricter in-build check than general kit membership (fixed 2026-07-25)

`buildKitSpellIds()`'s general "is this spell available to this class/spec" test is deliberately loose (a spell tagged generically `Class: Priest` with no spec qualifier counts as available to every spec — the right call for `GameDataBrowser`'s admin-exploration use case, where under-showing is worse than over-showing). Confirmed this leaks real noise into a *player-facing* modifier list: Shadowy Insight, Twilight Equilibrium, and most Voidform copies are Shadow-only in practice but exist **only** in `baseline.txt` with a bare `Class: Priest` tag — the same data limitation as Vampiric Embrace/Premonition, not a new bug. `ModuleSpellReferenceService::isConfidentlyInBuild()` requires either an actual `talent_node_entries` pick in this build's own trees, or an availability row explicitly tagged to this spec (not the ambiguous fallback) — anything that fails this is silently dropped from the "named" bucket rather than shown as unexplained noise. A small denylist (`isKnownJunk()`) also filters tier-set-bonus entries like `"Priest - Midnight PrePatch - 11.2 Class Set 2pc"` — not talents, would show with zero useful explanation regardless of spec-scoping. `buildKitSpellIds()` itself is untouched; only this stricter player-facing path is new.

## `replaces` relationships — where this came from (2026-07-25)

Investigated three candidate sources for spec→spell→override data (the internal `SpecializationSpells` DB2 table's shape) before building anything:
- **Blizzard's Game Data API** (`/data/wow/playable-specialization/{id}`, `/data/wow/playable-class/{id}`) — confirmed empty via live API calls (full raw response inspected for Discipline Priest). Two Blizzard forum threads confirm this is a known, long-unfulfilled community feature request, not just undocumented.
- **Blizzard's Profile API** — only exposes a real character's selected-talent loadout (different auth model, OAuth user flow), not a static reference table, and currently has an open bug where even that's missing.
- **wago.tools' `SpecializationSpells` export** (`https://wago.tools/db2/SpecializationSpells/csv`, public, no auth) — real and live, exact shape (`SpecID`, `SpellID`, `OverridesSpellID`), but narrow: 633 rows across 54 specs, only 44 with a non-zero override, and it tracks **baseline spec-identity kit assignment**, not elective talent-choice replacements. Beacon of Virtue/Beacon of Light — the test case — isn't in it at all.

The `replace=` annotation on `Talent Entry` lines (this section) turned out to be the right source for the case that actually mattered — talent-driven action-bar replacements — and it was sitting in data already imported, needing zero new fetching.

## Idempotency

Every write goes through `upsertTrack()`, keyed on each table's natural unique constraint — re-running the same patch against unchanged files produces zero writes (tracked and printed as created/updated/unchanged counts per table).

**Two convergence bugs found and fixed 2026-07-24, both only visible via a genuine two-consecutive-runs comparison** (a single run's counts can't reveal either — both existed silently before this session's charges/cooldown/category work, the second one predates it entirely):
1. `spells.cooldown_seconds` is a `decimal(8,2)` column with no Eloquent cast — MySQL returns it as a string ("180.00") while the parser fills a PHP float (180.0), and `upsertTrack()`'s `isDirty()` falls back to `strcmp()` for uncast numeric attributes, so it never matched. Fixed with explicit `$casts` on `Spell` (`charges` → `integer`, `cooldown_seconds` → `decimal:2`).
2. **Bigger effect, pre-existing:** `importClassSpells()` used to unconditionally write `description` from the freshly-parsed record — but a pointer-form record (`description_ref !== null`) always parses to `description = null` (re-parsing the same file can never yield anything else; resolving the pointer needs the full cross-class index that only exists after `resolveDescriptionReferences()` runs at the end). So every run, the main pass clobbered a previously-resolved description back to `null`, and the final pass wrote the real text back — a permanent Updated/Updated flap on ~44% of the dataset that never reached Unchanged. Fixed by omitting the `description` key entirely from `importClassSpells()`'s write when `description_ref !== null`, leaving an already-resolved value alone.

## `split-by-tree.php` was silently truncating multi-paragraph descriptions (found and fixed 2026-08-01)

Found while chasing why Pain Suppression didn't show 2 charges in the browser even after selecting Protector of the Frail (see the `modifies_charges` split below — that alone wasn't the whole story). The **raw** dump for Protector of the Frail (`data/spelldata/raw/priest.txt:9259-9261`) has a Description with two paragraphs:

```
Description      : Pain Suppression gains an additional charge.

Power Word: Shield reduces the cooldown of Pain Suppression by ${$abs($s2/1000)} sec.
```

The second paragraph — a real mechanic (effect #2, `Base Value: -3000`, with **no** structural `Category:`/`Affected Spells (Category)` representation anywhere, unlike the charge grant in effect #3) — was completely absent from the **filtered** file (`filtered/priest/discipline.txt`), which jumps straight from the first sentence to the next `Name:` record.

**Root cause:** `split-by-tree.php` split the raw dump into records purely on blank lines. SimC's dump also uses blank lines to separate paragraphs *within* a single Description/Tooltip, not exclusively between records. When a Description had an internal paragraph break, the splitter flushed the record early; the orphaned continuation-paragraph became its own "block" that didn't start with `Name :`, silently failed the record-shape check, and was dropped with no anomaly logged.

**Scope — checked every class's raw dump, conservative count only** (continuation paragraph itself ends in a period *and* is immediately followed by the next `Name :` record — misses 3+ paragraph cases and ones followed by `Tooltip :`):

| Class | Truncated records |
|---|---|
| Priest | 15 |
| Deathknight | 36 |
| Paladin | 28 |
| Druid | 19 |
| Warrior | 14 |

Systemic, not Priest-specific — several hundred records across all twelve classes by extrapolation. Sample of what else was being lost (priest alone): `"Prayer of Healing reduces the cooldown on Holy Word: Sanctify by $34861s3 sec."`, `"Talents that affect Holy Word: Sanctify instead affect Holy Word: Serenity."` (a redirect relationship with no `relationship_type` equivalent today), `"Can accumulate up to $390993U charges."` (Lightweaver).

**Fix, two layers:**
1. `split-by-tree.php` — block boundaries are now driven only by a line matching `/^Name\s*:/` (the same signal `SpellDataFileParser::parseContent()` already uses), never by a bare blank line. Trailing blank lines are trimmed per block for tidiness; blank lines *within* a block are left untouched.
2. `SpellDataFileParser` — even with the text surviving the split, the parser's `Description` handling only ever captured the single line immediately after `Description :`. Now tracks `$inDescriptionContinuation`: after a literal (non-pointer) Description line, every following non-blank line is space-joined onto the same `description` value until a recognized field, `Tooltip :`, `Effects:`, or the next `Name :` record ends it. Scoped to literal-text descriptions only — a `$@spelldesc<id>` pointer has no local text yet to append onto (resolved separately, cross-record, by `resolveDescriptionReferences()`).

**Deliberately not built in this pass:** an automated extraction of these now-visible continuation sentences into new `spell_relationships` rows (e.g. turning "X reduces Y's cooldown by N sec" into a structured `modifies_cooldown` row the way `importPvpTalentRelationships()` already does for PvP talent prose). The recovered text is now visible wherever a spell's own description renders (e.g. Protector of the Frail's row in a module's Spells section), which is itself the honest, non-guessed improvement — turning arbitrary recovered prose into new structured relationships needs its own confirmed phrasing patterns first, same discipline as the PvP-talent regex, not blanket pattern-matching over newly-unlocked free text.

**Re-import note:** regenerating `filtered/*.txt` via `split-by-tree.php` and re-running `import:spelldata` will pick up the recovered text automatically (`spells.description` is a plain `upsertTrack()`-managed column, updates in place — no stale-row caveat here, unlike the relabeling below).

## `modifies_charges` split into correctly-typed relationships (fixed 2026-08-01)

Closes the gap flagged 2026-07-24 above ("`modifies_charges` is a coarser label than the data actually supports") — `ImportSpellData::categoryRelationshipMapping()` now maps each of the 8 Category effect types to its own `relationship_type`, rather than lumping all of them under `modifies_charges`:

| Effect type | New `relationship_type` | Magnitude computed? |
|---|---|---|
| Modify Cooldown Charge (Category) | `modifies_charges` | Yes — `+N charges` (verified: Pain Suppression/Protector of the Frail, `Base Value: 1` == +1 charge) |
| Modify Recharge Time (Category) | `modifies_cooldown` | Yes — flat seconds (verified: the Mind Blast worked example, `base_value/1000`) |
| Modify Recharge Time% (Category) | `modifies_cooldown` | No — not yet verified |
| Modify Cooldown Time (Category) | `modifies_cooldown` | No — not yet verified |
| Modify Charge Cooldown Recharge Rate% (Category) | `modifies_charge_rate` (new) | No |
| Hasted Cooldown Duration (Category) | `hasted_cooldown` (new) | No — always-on mechanical tag, not a per-talent modifier |
| Hasted Cooldown Regeneration (Category) | `hasted_cooldown` (new) | No |
| Ignore Spell Charge Cooldown (Category) | `bypasses_cooldown` (new) | No |

When a pair is only known via the non-magnitude-bearing "Category:" direction (no matching source-side effect found — see `importCategoryRelationships()`'s de-dup comment), the type can't be determined and falls back to the original `modifies_charges` label with no magnitude, same as before this fix.

`ModuleSpellReferenceService` gained `effectiveCharges()` alongside `effectiveCooldown()` — both are now thin wrappers around a shared private `effectiveScalarValue()` (previously `effectiveCooldown()`'s logic was copy-paste-able but not generalized). Each filters `modifiersFor()`'s `'named'` list to its own `relationship_type` before applying flat-then-percent, so a spell with both a `charges`-unit and a `seconds`-unit selected modifier can't cross-contaminate the other's computation. `Modules\Show::getModuleSpellReferencesProperty()` and the Spells table now show a computed effective charge count (with a struck-through "was N" indicator, matching how a changed cooldown already renders) instead of the raw, talent-blind `spells.charges` column.

**Re-import note — stale rows will remain:** `relationship_type` is part of `spell_relationships`' unique key (`source_spell_id`, `target_spell_id`, `relationship_type`). Re-running `import:spelldata` in place after this change will **insert** new correctly-typed rows alongside the old `modifies_charges` rows for the ~305/460 pairs that get relabeled — it won't delete the stale ones (`upsertTrack()` only creates/updates, never deletes), so `modifies_charges` would still show the old, wrong entries duplicated next to the new, correct ones. Run `migrate:fresh` + re-import rather than importing in place onto an already-populated DB, same precedent as the `baseline.txt` spec-filtering fix above.

## Class-availability gaps found by cross-checking the real in-game Spellbook (fixed 2026-08-01)

Pulled real screenshots of the in-game Priest Spellbook (the "Priest" page — always-known abilities — and the "Discipline" page — spec-only baseline abilities) and cross-checked every name against `spell_class_availability`, the same "trusted external list, enrich from there" pattern as the Arms Warrior spot-check above. Most of it matched cleanly (both pages' spells resolved to the right `spec_id`/`NULL` split), but three real, distinct problems surfaced:

**1. Mind Blast was importing as class-wide when it should be Discipline+Shadow only.** Its raw record has a `Talent Entry` line with a `free=(Discipline, Shadow)` annotation — Blizzard's own signal that this shared class-tree talent is auto-granted to only those two specs, never Holy. Confirmed nothing in `SpellDataFileParser` or `ImportSpellData` parsed or used `free=(...)` at all before this fix — every `tree=class` talent was written as `spec_id = NULL` regardless. Not Priest-specific: `free=` appears in every class's raw dump (1–12 occurrences per class, 0 for Warlock).

**Fixed:** `SpellDataFileParser` now captures `free_specs` from any `Talent Entry` line/continuation that has both `tree=class` and a `free=(...)` clause (matched independent of line prefix, the same trick already used for `replace="..."` — confirmed `free=` never appears outside a Talent Entry line anywhere in the dataset). `ImportSpellData::resolveFreeSpecIds()` resolves those names to spec ids and narrows `spell_class_availability`, the same way `resolveBaselineSpecIds()` already narrows baseline.txt records via `Class:` — same "never guess narrower than confidently supported" fallback to `[null]` when a name doesn't resolve.

**2. Penance and Ultimate Penitence are each several spell_id records sharing one display name — only one is the real, player-facing talent.** Queried the DB: "Penance" is 11 distinct `spells` rows, not one; only 2 correctly resolve to Discipline. Checked the raw data for the other 9 — every one has a bare, unqualified `Class : Priest` (never "Discipline Priest"), and most carry `Not In Spellbook (143)` in Attributes. Same pattern on Ultimate Penitence: of its 4 spell_ids, 3 (`421434`, `421543`, `421544` — the channel-visual effect, the damage bolt, the heal bolt) are `Not In Spellbook` internal sub-components; only `421453` is the real, selectable talent.

This is **not** a `resolveBaselineSpecIds()` bug — its conservative fallback (documented, deliberate: never guess a narrower spec than the data confidently supports) is working exactly as designed; these sub-spells' own `Class:` field genuinely doesn't say "Discipline Priest." The actual gap was that nothing recognized `Not In Spellbook` as the signal that a same-name duplicate is internal noise, not a second real ability.

**Fixed:** `SpellDataFileParser` now captures `not_in_spellbook` from each record's own `Attributes` line. `ModuleSpellReferenceService::preferVisible()` (called from both `resolveSpellByName()` and `resolveSpellByNameAnyClass()`) narrows a same-name candidate set to non-hidden spells first, whenever at least one exists — never drops every candidate to zero. Verified against the real data post-fix: `resolveSpellByName('Penance', ...)` now resolves to `47540` (the real one) instead of risking one of the 9 internal duplicates; `resolveSpellByName('Ultimate Penitence', ...)` resolves to `421453`.

**Re-import required** — both fixes change how existing rows are classified (a new `spec_id` for Mind Blast-shaped talents; a new `not_in_spellbook` column), so `migrate:fresh` + re-import is needed rather than an in-place re-import, same caveat as every other classification fix in this document. Verified end-to-end after the rebuild: Mind Blast now shows exactly `Discipline` + `Shadow` availability rows (no `NULL`, no `Holy`); the Discipline Priest Oracle module's Spells table still renders correctly (Pain Suppression's `180s · 2 charges` fix from earlier the same day survived the rebuild).

## `not_in_spellbook` misses a second phrasing: "Do Not Display (Spellbook, ...)" (found 2026-08-02, FIXED 2026-08-02)

The 2026-08-01 `not_in_spellbook` fix (see above) only recognized the literal string `Not In Spellbook (143)`. A live diff run of the spellbook-verifier pipeline (`spellbook-verifier.md`) against a real Discipline Priest's in-game export flagged `spell_id 197419` ("Penance") as a `NOT_IN_SPELLBOOK_CANDIDATE` — correctly available per `spell_class_availability` (Discipline baseline), correctly absent from the real spellbook export, but its `spells.not_in_spellbook` was `false`. Its raw record (`data/spelldata/filtered/priest/baseline.txt:2822`) explains why:

```
Name       : Penance (id=197419) [Spell Family (6), Passive, Hidden]
Attributes : Is Ability (4), Passive (6), Do Not Display (Spellbook, Aura Icon, Combat Log) (7), Allow Class Ability Procs (416)
```

`Do Not Display (Spellbook, Aura Icon, Combat Log)` is semantically the same signal as `Not In Spellbook` (this is an internal Atonement-healing sub-effect of the real Penance, not a second player-facing ability — same shape as the 9 already-caught Penance duplicates) but a different literal phrase, so the existing regex/string match didn't catch it. The real, player-facing Penance (`spell_id 47540`) is unaffected and correctly resolves — this is the same kind of internal-duplicate noise the 2026-08-01 fix already handles for 9 other Penance rows, just a 10th one with different wording.

**Fixed same day**, bundled with the `not_in_spellbook` false-positive fix below (both touch the same `SpellDataFileParser` line) — `not_in_spellbook` now also matches `Do Not Display (Spellbook`. Verified only one exact variant of this phrase exists across the whole dataset (3511 occurrences, `grep`-checked for stray variants first) — no ambiguity risk from the broader match. The earlier note here about this being out of scope for the spellbook-verifier plan no longer applies — this fix landed as part of a separate investigation (a module's Hunter spell references resolving to the wrong duplicate spell, see below), which touched the same parser code for an unrelated, more serious reason.

## `not_in_spellbook` had a systemic false-positive: "Not In Spellbook Until Learned" ≠ "Not In Spellbook" (found and fixed 2026-08-02)

Found while investigating why a Discipline Priest matchup-timing module showed no cooldown for Hunter's Intimidation and Freezing Trap (both real, well-known abilities with real cooldowns). Traced to `ModuleSpellReferenceService::resolveSpellByName()` resolving to the wrong one of several same-named `spells` rows — the same duplicate-name shape as Penance/Ultimate Penitence, but with a different, more serious root cause this time.

**The wrong candidate wasn't marked hidden by design — it was marked hidden by a bug.** Hunter's real "Intimidation" (`spell_id 19577` — has its own `Talent Entry` for Beast Mastery/Survival and `Cooldown: 60 seconds`) had `not_in_spellbook = true`, which made `ModuleSpellReferenceService::preferVisible()` wrongly exclude it in favor of `spell_id 24394` (an internal pet-stun-effect sub-spell referenced only inside 19577's own description text, no cooldown at all). Same story for Freezing Trap: the real throw-able ability (`spell_id 187650`, `Cooldown: 30 seconds`, has real travel/velocity mechanics) was correctly visible, but only by luck — the module still resolved to `spell_id 3355` (the stun-aura effect applied once trapped, no cooldown) because `resolveSpellByNameAnyClass()` (the cross-class opponent-ability fallback) had no equivalent to `resolveSpellByName()`'s own-class `$withCooldown` disambiguation tier — just `preferVisible()` then `->first()`. Fixed by adding the same `$withCooldown` tier to the any-class fallback.

**But why was 19577 — a real, legitimate talent — marked `not_in_spellbook = true` at all?** Its `Attributes` line contains `Not In Spellbook Until Learned (269)` — a real Blizzard attribute, but a *different* one (different numeric code) from the genuine hide-this-spell marker `Not In Spellbook (143)`. `SpellDataFileParser`'s check was `str_contains($m[1], 'Not In Spellbook')` — a bare substring match that also matched the `... Until Learned (269)` variant, which is true of nearly every real, normal talent (you don't have any un-learned talent "in your spellbook" — that's not a hidden-duplicate signal, it's baseline talent-tree behavior). Quantified across the full dataset before fixing: **646** occurrences of `Not In Spellbook Until Learned (269)` were being conflated with **2777** genuine `Not In Spellbook (143)` occurrences — a real, systemic false-positive affecting every class, not a Hunter-specific edge case.

**Fixed:** the check now matches the exact `Not In Spellbook (143)` string (numeric code included), not a bare substring. Bundled in the same fix: the `Do Not Display (Spellbook, ...)` phrasing from the entry above.

**Re-imported and re-verified end-to-end** (same `migrate:fresh` + re-import + re-seed requirement as every other classification fix in this document): Intimidation now resolves to `spell_id 19577` (`cd=60`, `hidden=false`), Freezing Trap to `spell_id 187650` (`cd=30`, `hidden=false`). Also re-verified nothing regressed on the Discipline Priest Oracle module: Mind Blast still `28s`, Evangelism still `45s` (Ultimate Radiance applied) after rebuilding both `TalentBuild`s from the same real captured loadout string and PvP selections.

## Not yet built

- An equivalent import path for a non-WoW game — the flat-file *reader* (`SpellDataFileParser`, `classifyFileSource()`) is SimC/WoW-shaped by necessity of the source data; the schema underneath (`spells`, `spell_effects`, etc.) is intentionally game-agnostic (see `games` table), so a future game would need its own parser/import-command variant reusing the same tables, not a rewrite of this one.
- Verified magnitude conversions for `Modify Recharge Time% (Category)`, `Modify Cooldown Time (Category)`, and `Modify Charge Cooldown Recharge Rate% (Category)` — correctly typed now, but no computed number until a hand-verified worked example exists for each (see above).
- Automated extraction of recovered multi-paragraph description text into new structured `spell_relationships` rows (see above) — currently just visible as prose, not structured.
- Hero-tree attribution for baseline pet/summon spells via embedded `$<id>` description tokens, and filtering `getTopCooldownSpellsProperty()` by the selected `heroTreeId` (see above).
- Threading `talent_nodes.type`/`CHOICE` context into the Top Cooldowns section (see above).
- Any automation of the trusted-ability-list matching pattern (see above) — currently a validated-by-hand approach, not a built feature.
