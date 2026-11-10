# CLAUDE.md

The library: what every session needs, and where everything else is. **Loaded in full every session,
so keep it small.** A fact goes here only if most sessions need it; anything else goes in the doc for
its area, with a line here pointing to it. `docs/README.md` maps every doc in the repo.

**"CLAUDE.md rule N" anywhere in the code or docs means rule N in `docs/rules.md`.** A named section
this file no longer has ("Canonical Context Module Template", "Synergies tab"...) is in
`docs/history/engineering-log-2026.md`: search it for the heading, or for a symptom, file or date, to
find the *why* behind a rule.

## Vision (`VISION.md`)

A website that gives a player everything they need to reach Gladiator or higher in WoW arena:
class, spell, talent and mechanics data pulled straight from the game, combined with player insight
and real match data. There is no certain path to Gladiator in a season, but the game itself is
knowable, and that is what the site captures.

Optimise for four things: **speed** for the user; **simple architecture**; **clean formatting**
(finished output: no broken icons, placeholder symbols, `(varies)`, raw formula text or duplicate
spells); **clean data** (accurate, current spells, abilities, talents, mechanics).

**Current focus: understanding your own games.** Read each player's own arena games against the
players they meet, and show what happened and where they stand. Coaching is a prospect, not the
claim (Chriso, 2026-10-18), so no "coach" copy. Where a page does name something to work on, it leads
with what decides games (damage, defensive use, crowd control; `ImprovementService::PRIORITY`).

**Validation is part of the product.** A read has three separate tests: is it right, does it change
what the player does, does that change improve results. A rating gain proves none of them.

## Before you start: read the docs for the task

Find the task and read what it names **before** writing anything. Each is there because a session
that skipped it got something wrong the doc had already settled. These are reference, not gates: a
note that something "can break" is a warning to check, not a rule that blocks the work.

**Big docs are read by section, not whole** (size in brackets; each opens with what to read for which
question). `Grep` for `^##` or a spell, player or date, then `Read` with an offset.

| The task | Read first | Then, as the task needs |
|---|---|---|
| **Review games**: "look at my games", "why did we lose", comparing a player | The `review-games` skill, `match-review.md` | `match-review-analysis.md` (120KB) and `match-review-operations.md` (77KB) by section. Characters and teammates are in memory. Read defensives through the three questions; never call a count a fault |
| **Change a measure** (`RoundAnalysisService`, `ArenaMomentService`, `CooldownLedgerService`) | `match-review.md`, the "You changed / Run" table | Operations by section. Bump `RoundAnalysisService::VERSION` and say what it adds; re-measure with `wow:sync --skip-ingest --fresh` |
| **Change the desktop app or its pages** | `tools/desktop-app/README.md`, `match-review.md` (traps) | Pages are drawn by WebView2 and the site, so grid and flex are fine. `tools/log-manager/` is the retired PowerShell app |
| **Tags, classification, short defensives, a cooldown missing from goes** | Operations, "Making the tags better from play"; rule 11 | Run `tagaudit.php` and `hiddencds.php`; record decisions in `reviewed.json`; bump the cache, then kits, then profiles, then re-measure |
| **Write or correct a guide** | `guide-writing.md`, `data/brain/brain.md`, `docs/guides/reader-corrections-2026-09-23.md` | `docs/machine-guides.md` (authoring, feedback export first); `arena-structure.md` by Part. From played games: `guides-from-play.md` |
| **The arena model, the Brain, a new prose source** | `docs/arena/structure-summary.md`, `arena-open-questions.md`, `docs/arena/synthesis-process.md` | `arena-structure.md` (75KB) by Part; `docs/arena/sources/chriso-scope-correction-2026-09-23.md` before any "we can't" line |
| **Matchup Lab** | `data/matchup-profiles/README.md`, rules 31–34 | `arena-structure.md` Part 19 |
| **Spell data, import, a patch** | `docs/rules.md` rules 1–11, `spell-acquisition-model.md` | `game-data.md` (75KB) by section, `knowledge-gaps.md`; `dr-categories-reference.md` is stale, a hint only |
| **Class mechanics**: empowered casts, how buffs and talents combine, what a patch changed | `docs/class-mechanics.md` | Rule 39. Work one spike out from its tooltips first; pool only to ask how often or what is better |
| **A page of the site** | `docs/site-pages.md` by section, rules 22–30, `docs/design-system.md` | Check the real URL serves, at phone width too (Looking at a page, below) |
| **Deploy or check production** | `DEPLOY.md` | When asked, do it. Production runs the branch checked out there: push it, then `./deploy.sh` |
| **Training**: a lesson, notes, the reasoning check, claims | `docs/training.md` | Label invented figures as illustrative; a claim about the game cites the archive |
| **Quizzes, modules, diagnostics** (dormant) | `system-integration.md`, rules 35–38 | `docs/architecture.md`, "Dormant"; `docs/archive/` |
| **WoW: Forever**: the Observer addon, /ask, market data | `docs/forever/observer-handoff-2026-11-10.md` | The addon's README, `docs/forever/market-research-2026-11-07.md` |
| **Product, usage, pricing** | `VISION.md`, `monetisation-read.md` | |
| **How something fits together** | `docs/architecture.md` | |

### What "done" looks like for the spell data

Nothing unresolved in displayed text (no `(varies)`, `$s1`, `${...}`, `$lWord:Words;`, raw formula
fragments): if a true value can't be computed, get the data, and never ship a placeholder or
fabricate a number. No duplicate spells from internal id copies. Every rendered spell has a real
icon. Cooldowns, charges, talent modifiers, CC durations and mechanics match the live game.

## The library

| For | Read |
|---|---|
| Every doc, by area and status | `docs/README.md` |
| Rules and traps in full, the test baseline | `docs/rules.md` |
| How the pieces fit, security, smaller services and commands | `docs/architecture.md` |
| Tokens, components, icons, pickers | `docs/design-system.md` |
| The current work end to end | `match-review.md` |
| The arena model (public at `/brain`) | `docs/arena/structure-summary.md`, then `arena-structure.md` |
| The spell-data pipeline | `spell-acquisition-model.md`, `game-data.md` |
| Production, env vars, working on the box | `DEPLOY.md` |
| Every page of the site | `docs/site-pages.md` |
| Why a rule exists | `docs/history/engineering-log-2026.md` (not loaded), `docs/history/deploy-incidents-2026.md` |
| Finding abilities by curated property | `AbilityFinder`, `wow:abilities --vocabulary` (fields only, never descriptions) |

## Commands

```bash
composer run dev          # server + queue + logs + vite in parallel
composer test             # or: php artisan test --filter=test_name
./vendor/bin/pint app/Path/To/File.php   # ALWAYS an explicit file list
npm run build
php artisan migrate
```

**Test baseline: 12 pre-existing failures** (11 are one reCAPTCHA config gap, 1 is `LearningPathTest`;
`docs/rules.md`). Say "same 12" only after confirming the count.

**Game data:**
```bash
php artisan import:spelldata wow                 # normal form, no patch argument (rule 7)
php artisan wow:patch-update {build}             # a full patch bump
php artisan wow:refresh-match-derived            # after ANY match-data change (rule 15)
php artisan wow:apply-icon-manifest              # icons without Blizzard credentials
php artisan wow:precompute-spell-kits            # after a resolver or display change
php artisan wow:build-matchup-profiles           # AFTER the kits, never before (rule 31)
php artisan wow:rebuild-spell-counters           # backfill only; import already does it
php artisan wow:import-murlok-defaults --all --apply   # on-demand only (rule 16)
```

**Your games:**
```bash
php artisan wow:sync                             # ingest + review + measure new games
php -d memory_limit=2G artisan wow:sync --skip-ingest --fresh   # re-measure everything (~8 min)
php artisan wow:game-cards --dir="$APPDATA/MindCollector/cards" --notes="$APPDATA/MindCollector/notes.json"   # the app's pages
php -d memory_limit=3G tools/match-review/tagaudit.php    # tags against how spells are pressed; proposes only
php -d memory_limit=2G artisan wow:go-cooldowns  # what each spec presses in its goes; commit the file
php -d memory_limit=2G artisan wow:refresh-kits  # stored rounds' buttons, after a talent-resolution change
php artisan wow:translate-spell-names            # Chinese/Korean names in stored rounds -> English (docs/international.md)
php -d memory_limit=3G artisan wow:population    # data/population/norms.json; --fresh after VERSION changes
```

**Guides:** `guides:author {path}` with `--dry-run` first; `guides:export-feedback` on the server before
re-authoring, because re-authoring destroys reader comments (`docs/machine-guides.md`).

## Environment

**Windows / Herd (local).** Run PHP and artisan through the **PowerShell tool**: the Bash tool is
git-bash, with no PHP on its PATH, so use it for file and git work only. Redis via Herd, MySQL. A full
re-import may need `php -d memory_limit=512M`. Destructive artisan commands (`migrate:fresh`, `db:seed`
on a real DB, `queue:restart`) are confirm-first.

**Looking at a page.** `chonny.test` is often not resolvable (Herd not running, host not in `hosts`).
That does not mean a page can't be checked:

```bash
php -S 127.0.0.1:8321 -t public     # in the background, then Invoke-WebRequest the routes
```

Not `php artisan serve`, which failed to bind here. **Do this before calling a new page done.**
`Livewire::test()` renders without the layout, so a full-page component missing `->layout(...)` passes
every Livewire assertion while the real URL is a 500; a `$this->get(route(...))->assertOk()` catches
it. Chrome can't screenshot a `file://` page: render the HTML to the scratchpad and serve it with a
small `php -S` router. Check phone width too, by resizing the window.

**Production.** Linux, one vCPU, Nginx + PHP-FPM, 2 queue workers, no scheduler. `mindcollector.com`:

```bash
ssh -i ~/.ssh/mindcollector_deploy -o BatchMode=yes root@45.76.116.44 "cd /var/www/mindcollector && ./deploy.sh"
```

- **Only `./deploy.sh`, never a bare `git pull`** (OPcache keeps old bytecode; this caused real 500s).
- Start the remote command with `cd`, so git-bash does not rewrite the path. SFTP is broken: upload by
  base64 through stdin. Redis cache is DB 1.
- One-off production DB writes are blocked: hand the user a ready-to-paste command. Never add an allow
  rule for yourself.
- Everything else, including env vars: `DEPLOY.md`.

## Rules index

One line each; the full text and the reason are in `docs/rules.md`. Numbers never change.

**Spell data**
1. `spec_id = NULL` baselines are ambiguous: hand-curate `baseline-spec-overrides.txt` a verified line at a time; no heuristic has survived testing.
2. `spells.id` is internal, `spells.spell_id` is Blizzard's; `talent_node_entries.spell_id` and `pvp_talents.spell_id` are FKs to `spells.id`. Guide blocks and override files hold external ids.
3. One ability is often several id copies: prefer the pressable one; never filter by "this metadata is populated".
4. `spells.mechanic` is not `dr_category`.
5. Curated override files are re-applied every import: a blank field means null, a duplicate line wipes the earlier one, removing a line deletes nothing.
6. Read a curation file's own comments before "correcting" it. 6a. `--auto-detect-live` can roll data backwards: compare the dump's header with `patches.build_version`.
7. `import:spelldata wow` takes no patch argument; the patch row is never forked.
8. `spells.dr_category` is the base value; only build-aware display resolves a conditional.
9. `chain_target = kill_target` needs a `dr_category` of Stun or Silence.
10. Three separate axes: `spells.category` (badge), `spells.dr_category` (CC), `offensiveDefensiveClassification()` (Comps' cooldown tabs).
11. Classification JSON is hand-promoted: keep the hand-written entries; `tagaudit.php` only proposes.

**Arena logs**
12. New games come from your own combat log only; the WoWArenaLogs pullers stay dead. Teams from `COMBATANT_INFO`, never `reaction`.
13. Never cull the archive.
14. The raw archive is a build-time input, never read at render time: bake a committed artifact.
15. `wow:refresh-match-derived` after any match-data change; it prints the human-review gates and does not run them.
16. `wow:import-murlok-defaults` is on-demand only; it discards hand curation.

**Caching**
17. `bumpSpellCacheVersion()` also invalidates all 40 kits: bump for data changes only; use a local signature otherwise.
18. The counter lives in `wow_spell_cache_state`, not the cache store.
19. Bump first, generate kits second.
20. A cached payload's shape changing needs a manual bump.
21. Kits embed resolved descriptions: after a resolver fix, regenerate them.

**Web / Livewire**
22. No `wire:key` on a component's root element; pass `:key` on the `<livewire:>` tag.
23. Every public property is writable by anyone: `#[Locked]` for anything server-owned.
24. `render()` passes computed and public properties explicitly.
25. One persistent, always-rendered root element.
26. Never read the DOM to decide what to persist.
27. Don't mutate cloned models for view state; use plain arrays keyed by id.
28. Every new user-facing route is tracked: `PageViewEvent::log()` in `mount()`, attributed calls on real selections, and an `Admin\PageUsage::PAGES` entry. `is_bot = false` is not a person.
29. Guide URLs resolve slugs per author, by a binding in `AppServiceProvider::boot()`.
30. Machine-drafted guides stay out of player listings.

**Matchup Lab**
31. Profiles build after kits.
32. Profiles carry external ids, kits internal ones.
33. Nothing on the cooldown graph states a win probability or calls a window a kill.
34. Every cadence is an upper bound.

**Generated questions**
35. A generated result never writes `UserConceptMastery`.
36. State only what a field can confirm; never compare to a field not held.
37. `usable_while_cc = NULL` means no (not true of other nullable columns).
38. A new field on a cached `WowAbility` bumps `WowAbilityFacts::SHAPE_VERSION`.

**Empowered spells**
39. A buff reaches PvP Guides only through `data/empowered-casts/reviewed.json`; `empowered.php` only proposes.

## Traps index

Full text in `docs/rules.md`.

- **PHP:** arrow functions capture by value; `sortBy([closures])` calls two-argument comparators; `orderBy()` on an ordered relation appends, use `reorder()`; `Collection::merge()` on an Eloquent collection needs `->toBase()` for plain arrays; name every throttle (`throttle:20,1,battlenet`), since plain ones share a counter.
- **Blade:** a directive glued to a word character isn't compiled; multi-line attributes with nested escaped quotes and multi-line `@json([...])` break, so build the value in `@php`.
- **Tailwind:** an arbitrary variant inside a `{{ }}` ternary may not compile: grep the built CSS.
- **Assets:** host-relative paths (`/storage/...`), never `asset()` or `Storage::url()`.
- **Migrations:** no DB enums, string columns only; the suite runs on SQLite.
- **Testing:** a request's `set_time_limit()` applies to the whole suite (one process), so guard it with `runningUnitTests()`; only the first `Livewire::test()` of a component in a process returns full HTML; spells only come from `import:spelldata`; never benchmark a write path against the real DB.
- **Tooling:** PowerShell's `Set-Content` adds a BOM; quotes in a Bash heredoc break inline Python, so write the script with Write.
- **Layout:** a box with its own scroll bar hides what's below it on a phone, and reads as missing.

## Working practice

- **Trace to the root cause; don't shotgun-patch.**
- **Flag, don't guess.** A confidently wrong number is worse than a visible hole, in curation and in prose.
- **Verify against something independent.** A real screenshot, real captured data, a real in-game reading; a round trip through your own encoder proves nothing.
- **Quantify before and after.** The numbers make a claim checkable.
- **Don't trust a subagent's self-report.**
- **Check `git status` on sibling files** before calling something pre-existing breakage: another session may be writing here.
- **Curated game facts come from the domain expert or real observation**, never AI research. Arena-log cast evidence is direct observation.
