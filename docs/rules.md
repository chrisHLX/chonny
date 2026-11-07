# Rules and traps

The full text behind the one-line index in `CLAUDE.md`. Moved here word for word on 2026-11-01 to
keep that file a library. **Numbers never change, and a number is never reused:** code, data files
and docs cite rules by number, and "CLAUDE.md rule 17" anywhere in the repo means rule 17 here.

Read the group your task touches before writing anything (`CLAUDE.md`, "Before you start", names it).

## Test suite baseline

**12 pre-existing failures**, traced and understood — not noise to ignore, but not regressions:

- **11 are one config gap.** `phpunit.xml` sets `APP_ENV=testing`, but the reCAPTCHA skips in
  `RegisteredUserController::store()` / `LoginRequest` / `PasswordResetLinkController` and the
  bypass in `User::hasVerifiedEmail()` all check `local` / `!isProduction()` only. Affects
  `AuthenticationTest`, `RegistrationTest`, `PasswordResetTest`, `EmailVerificationTest`,
  `GuestDiagnosticClaimTest`, `RoadmapFunnelTest`. The fix is `environment(['local','testing'])`
  in those places; low-risk, just never prioritised.
- **1 is a real app bug.** `LearningPathTest` — `Collection::getLearningPathProperty()` gives the
  "next" badge to a stage that should read `future` before any context dimension is declared.

Say "same 12 pre-existing failures" only when you have actually confirmed the count.

## Rules that must not be regressed

### Spell data

1. **`spec_id = NULL` baseline spells are ambiguous.** The row means both "genuinely class-wide"
   (Leg Sweep) and "spec-restricted but unlabelled" (Mind Sear on Discipline). Every heuristic
   tried to split them has been disproven under testing — a SimC GitHub search, a
   `spell_relationships` pattern check, a cooldown/CC heuristic. One bulk attempt shipped and was
   pulled the same day. What works: hand-curated `data/spelldata/baseline-spec-overrides.txt` +
   `TalentSelectionService::verifiedBaselineAbilityIds()`, **one verified line at a time.**
   `alwaysAvailableAbilityIds()` remains in the file, unused, flagged **DO NOT WIRE IN**.
   When the evidence says an ability is genuinely class-wide, add every spec of that class at
   once — otherwise the same gap resurfaces spec by spec as separate reports.

2. **External vs internal spell ids.** `spells.id` is an internal auto-increment key, patch-scoped
   and reassigned on a rebuild. `spells.spell_id` is Blizzard's. `talent_node_entries.spell_id`
   and `pvp_talents.spell_id` are FKs to **`spells.id`** despite the column name. Guide blocks,
   curated override files and gating columns store **external** ids. This mismatch has caused at
   least three silent bugs that resolved cleanly while being wrong — check which space you are in
   before comparing.

3. **One visible ability is often several internal spell_id copies.** Prefer the pressable one
   (`is_passive = false`, `not_in_spellbook = false`, real cooldown/charges). Never filter a
   candidate pool by "has this metadata field populated" as a proxy for "is real" — that
   assumption has been found backwards twice; the real copy frequently has the *emptier*
   metadata. Sibling recovery (same display name, same patch) is the standard fallback for
   descriptions, effects, categorisation, icons, and base cooldown/charges.

4. **`spells.mechanic` is not `dr_category`.** Measured 50% disagreement on real curated spells
   (Fear to Flee, Cyclone to Banish). It is a hint shown next to an empty field, never a default.

5. **Curated override files are authoritative and re-applied on every import.** Every field is
   written on every run — **a blank field means null/false, not "leave alone"** — and a duplicate
   line silently wipes an earlier one (the importer warns on this now). The importer is
   **additive-only**: removing a line does not delete the row it wrote, so a correction needs a
   one-off delete query as well.
   - `baseline-spec-overrides.txt` — spec availability (`verified_override`)
   - `cc-synergies-overrides.txt` — `dr_category`, `chain_target`, `is_peel`, `is_interrupt`,
     `pvp_duration_seconds`, conditional DR gating
   - `cc-immunity-overrides.txt`, `school-immunity-overrides.txt`, `icon-name-overrides.txt`,
     `manual-spells.txt`, `icon-manifest.json`

6. **Read a curation file's own commentary before "correcting" it.** Redundant-looking lines are
   often deliberate and say so (an aura spell_id kept for log matching alongside the pressable
   copy kept for palettes). This has stopped at least two wrong "fixes".

6a. **`--auto-detect-live` can roll the spell data BACKWARDS.** `fetch-simc-dumps.php` picks the
    newest `data-update-live-*` branch, but SimC does not cut one per build: on 2026-09-24 the
    only live branch was 69283 while `midnight` carried 69933 and the DB already held 69814. Read
    the first line of a fetched dump ("... for World of Warcraft 12.1.0.69933 Live") and compare
    it with `patches.build_version` before importing. `midnight` is the expansion's active branch.

7. **`php artisan import:spelldata wow` with no patch argument is the normal form.** It resolves
   the current patch from the DB and relabels that row in place from the SimC dump headers.
   **The patch row is never forked** — everything game-related FKs to `patches.id`, so a new row
   orphans every curated build, guide reference and counter. A genuinely separate row needs
   `--new-patch`. Guides record their own `authored_build_version` separately.

8. **`spells.dr_category` is the base value and is never overwritten by talent-conditional
   resolution.** Log-derived consumers (`CcFormulaService`, `FindCcChains`, `SpellCounterIndexer`,
   `CcTargetingAnalyzer`, `/cc-review`, `SpellFinder`) read the column and are right to — a combat
   log records whichever variant landed, under its own aura id. Only build-aware *display*
   resolves the conditional, via `SpellProfile::drCategory()`.

9. **`chain_target = kill_target` requires a `dr_category` of Stun or Silence.** Everything else
   breaks on damage, so it can only ever be healer-directed or a peel. Enforced at import.

10. **Three independent categorisation axes.** `spells.category` (`categorize()`) drives the badge
    and Spell Explorer tabs; `spells.dr_category` (curated) drives Crowd Control and the CC
    chains; `ArenaLogService::offensiveDefensiveClassification()` (arena-log-verified, promoted
    JSON) drives **only** WoW Comps' Offensive/Defensive Cooldowns tabs. "Shows as offensive" can
    mean any of them. A spell legitimately appears as both an offensive cooldown and crowd control.

11. **Hand-promoted classification JSON.** `data/arena-logs/spell-classification/*.json` is
    reviewed and promoted by hand, not computed live. When re-promoting `classify-cooldowns.php`
    output, **keep the hand-written entries** (e.g. Gladiator's Medallion) or they drop out of
    every Defensive Cooldowns tab and guide palette. Two smaller files in the same folder decide
    what the match analysis SEES: `contextual-cooldowns.json` (spells read per press, Vanish) and
    `short-defensives.json` (defensives under the 45s timeline floor that still count, Feint).
    `tools/match-review/tagaudit.php` proposes changes to all of them from how spells are pressed;
    it never writes them (`match-review-operations.md`, "Making the tags better from play").

### Arena logs

12. **New matches come from YOUR OWN combat log** (`wow:ingest-combatlog`,
    `CombatLogIngestService`). The WoWArenaLogs pullers are dead and stay dead (search disabled
    from 2026-09-09, sign-in gated from 09-23: do not resume bulk pulling). Advanced Combat Logging
    must be on. A team comes only from `COMBATANT_INFO` field 2, never `reaction`. A Solo Shuffle
    lobby is six matches with one unusable END line, and each round's winner comes from its
    deaths: `UNIT_DIED`'s trailing `unconsciousOnDeath` filters a Hunter's Feign Death. Every
    measured offset and the checks behind them: `docs/combat-log-ingest.md`.

13. **Do not cull the archive again.** The 2026-09-05 cull of the oldest 500 matches is permanent
    and unrecoverable — those came from a feed that no longer serves us. New matches only ever
    arrive now by playing them.

14. **The raw archive is a build-time input, never a runtime dependency.**
    `data/arena-logs/metadata/*` is gitignored, so anything a page reads from it at render time
    works perfectly on every dev machine and is silently broken for every real user. Bake what
    pages need into a committed artifact (the way burst windows embed their talent build). Tests
    for such pages should force the archive absent — a normal dev run structurally cannot catch
    this.

15. **`wow:refresh-match-derived` after any match-data change.** It runs CC chains (`--json`, the
    flag that actually matters), burst-window regeneration and promotion, talent and mechanics
    enrichment, playstyle analysis, CC targeting, burst guides, and bumps the spell cache. It
    deliberately does **not** run `wow:extract-arena-spells` promotion, `classify-cooldowns.php`
    promotion, or the murlok importer — those are human-review gates, and it prints them as a
    checklist instead.

16. **`wow:import-murlok-defaults --all --apply` is on-demand only.** Every run wholesale replaces
    each spec's default build from murlok's current page, discarding hand curation, and hits a
    third-party site. Reach for it when something concrete motivates it (a patch, a reported gap),
    never on a cadence. It is deliberately not part of `wow:patch-update`.

### Caching

17. **`bumpSpellCacheVersion()` is a global counter** — it keys the `wow_spell_references:*` cache
    **and** all 40 precomputed kits. Bump it when the underlying spell *data* changed. **Do not**
    bump it to publish a smaller artifact change (a palette grouping, a burst-guide JSON) — that
    invalidates every kit and drops WoW Comps and Spell Explorer onto the slow path for nothing.
    Use a local signature instead: `PALETTE_SHAPE_VERSION`, `guideFilesSignature()`.

18. **The counter lives in `wow_spell_cache_state` (a DB table), not the cache store.** It used to
    be a `Cache::forever` key, which meant an ordinary `cache:clear` silently reset it to 1 and
    broke invalidation entirely. `optimize:clear` does not touch it.

19. **Ordering is load-bearing: bump the version FIRST, generate the kits SECOND.** Kits embed the
    version and the deployed-code fingerprint; generating before writing them stamps the old value
    and invalidates them seconds later.

20. **A cached payload's *shape* changing is not a data change.** Adding a key the blade reads
    bumps nothing on its own, and stale entries of the old shape then throw `Undefined array key`.
    Bump manually after deploying such a change.

21. **Precomputed kits embed resolved description text.** After a description-resolver fix, the
    code being right is not enough — regenerate the kits.

### Web / Livewire

22. **Never put `wire:key` on a Livewire component's root element.** Livewire sends a child back
    as a bare `<div wire:id>` placeholder on a parent re-render, and morph matches `wire:key`
    before `wire:id` — so the component is destroyed and re-initialised with no snapshot, and
    every later click on the page silently does nothing. Pass `:key` on the `<livewire:>` tag.

23. **Every public Livewire property is writable by anyone who posts to `/livewire/update`**,
    bound or not. Mark server-owned properties `#[Locked]`. This was a real exploit: an unlocked
    `$isDefaultEditor` / `$readOnly` on a public read-only page let an unauthenticated request
    write into a spec's admin default talent build. Anything deciding *where* something saves or
    *whose* data is shown must never be client-writable.

24. **`render()` must explicitly pass computed and public properties into the view.** Bare
    `$property` access in a Blade template does not reliably auto-inject; `$this->property` from
    PHP always works.

25. **A component's view needs one persistent, always-rendered root element** — a template whose
    entire body sits inside `@if` produces zero roots when it is false.

26. **Never read the DOM to decide what to persist.** The quiz ordering question keeps
    `wire:ignore` on its `<ul>` (client-authoritative between renders; Alpine pushes order into
    `$wire.answer`) and `submit()` reads `$this->answer`. The guide builder deliberately does the
    opposite — no `wire:ignore`, because it is server-authoritative and persists on every drop;
    copying `wire:ignore` there freezes the list against add and remove.

27. **Don't mutate cloned Eloquent models to hold view state.** It does not survive Livewire
    rehydration — the model is re-fetched and the change silently discarded. Use plain arrays
    keyed by id (`$shuffledOptions`).

28. **Every new user-facing route must be tracked twice:** a bare `PageViewEvent::log($page)` in
    `mount()` for the raw count, an *attributed* call on every real explicit selection (never on a
    default/landing value), **and** an entry in `Admin\PageUsage::PAGES`. Logging without the
    `PAGES` entry is indistinguishable from not tracking at all — a confirmed real gap. Sub-views
    that must not round-trip Livewire use an Alpine `fetch` beacon to `TrackController` with an
    allowlist, surfaced by their own `PageUsage` breakdown rather than a `PAGES` entry.
    **`is_bot = false` is not a person.** From 4 Oct 2026 a page view counts as seen by a browser
    only when the layout's signed beacon confirms it (`confirmed_at`,
    `PageViewEvent::scopeConfirmed()`). The 2–3 Oct "Google spike" was 154 Alibaba Cloud
    addresses faking the referrer, and none of them ran the page (`monetisation-read.md`, 1e).

29. **Guide URLs resolve slugs per author**, via an explicit binding in
    `AppServiceProvider::boot()` (not the routes file, so `route:cache` can't drop it). Implicit
    binding took the first global match and made every new player's first guide open a stranger's
    draft.

30. **Machine-drafted guides stay out of player listings.** `Browse`, `GuideFeed` and
    `Home::exampleGuide()` filter `humanAuthored()`; machine guides have their own page and their
    own byline.

### The Matchup Lab

31. **`wow:build-matchup-profiles` runs AFTER `wow:precompute-spell-kits`, never before.** A
    profile is a narrowing of the same kit, so with fresh kits a whole sweep is a few seconds of
    JSON reads and with stale ones it is the full ~7s-per-spec live computation forty times over.
    `deploy.sh` runs them in that order, and throws away both committed artifacts before the pull
    for the same reason.

32. **Matchup profiles carry EXTERNAL spell ids; spell kits carry internal ones.** That is what
    makes a committed profile the real artifact rather than a placeholder — internal `spells.id`
    values are reassigned on every rebuild, which is why the committed kits are regenerated per
    environment. See rule 2; this distinction has caused at least three silent bugs that resolved
    cleanly while being wrong.

33. **Nothing built on the cooldown graph may state a win probability, or call a window a kill.**
    There is no outcome corpus to fit a probability to (rule 12 killed match search; the comp
    index holds two entries), and an empty answer pool means the target has no button left, not
    that the damage is lethal — Part 11's damage-model blocker is unchanged. The page's
    honest-limits copy lives in one method, `MatchupLab::limitations()`, so it cannot be trimmed
    a line at a time by a layout change. `arena-structure.md` Part 19.1 records why the source
    that proposed the feature was wrong to call the crossing point "mathematically guaranteed".

34. **Every cadence the engine reports is an upper bound.** The data holds base and
    talent-modified cooldowns, not spend-driven reduction, so real goes come round sooner by an
    unknown amount that differs per spec. Stated on the page rather than corrected for; see
    `knowledge-gaps.md` (2026-09-23) and `arena-open-questions.md` C12.

### Generated questions

35. **A generated result never writes `UserConceptMastery`.** `MasteryService` scores a concept
    as `correct ÷ every question attached to it` — the whole authored bank, not what the player
    was asked. Feed that an unbounded generator and every player's percentage falls as the game
    data gets richer. Drill results are read back off `quiz_attempts` instead
    (`ConceptDrillRecord`, a window over the last 20 questions actually asked), which needs no
    table because an attempt already stores its questions as they were asked.

36. **A generated question states only what a field can confirm, and never compares to a field
    it does not hold.** The arena-duration question was first written with an explanation claiming the PvE
    duration is longer; 36 of the 111 crowd control spells carrying an arena figure have the same
    figure in PvE, so it would have been false a third of the time. `WowAbility` carries no PvE
    duration, so the claim is now general rather than per-spell.

37. **`usable_while_cc = NULL` means no, not unknown.** `SpellDataFileParser` reads every spell's
    whole `Attributes` line on every import and writes null when none of Blizzard's "Allow While …"
    codes are present. That is what makes a "which of these can you cast while stunned" distractor
    sound. Do not generalise this to other nullable columns — most of them *are* gaps.

38. **Adding a field to a cached `WowAbility` bumps `WowAbilityFacts::SHAPE_VERSION`, not the
    spell cache version.** Rule 17's reasoning: the global counter also keys all 40 kits. A stale
    entry of the old shape makes every question type reading the new field skip in silence.

### Empowered spells

39. **A buff reaches PvP Guides only through `data/empowered-casts/reviewed.json`.**
    `tools/match-review/empowered.php` proposes; it never decides. 254 of 297 candidates that passed
    every automatic check were false or unproven on 2026-11-01: combo points, crits and heals that
    depend on the target all look like multipliers. Approve a buff only when its tooltip states a direct
    increase to the hit measured and the number fits. To explain one spike, work it out from its
    tooltips first (`docs/class-mechanics.md`): one example can show how a spell works, and only
    comparisons need a sample.

## Traps that have bitten before

**PHP**
- **Arrow functions capture by value**, with no opt-out. `fn () => $tokens[$pos]` freezes `$pos`
  forever. Use a regular closure with `use (&$var)`. This silently truncated every multi-term
  arithmetic expression in the description resolver for months, and recurred later in
  `annotateChain()`.
- **`Collection::sortBy()` with an *array* of closures calls each as a two-argument comparator**
  (`$fn($a, $b)`) and uses the return value directly — a one-argument rank closure sorts by
  nonsense. Use `fn ($a, $b) => $x <=> $y`. A single-closure `sortBy` *is* a value extractor.
  Shipped wrong in `SpellCounterIndexer`; fixing it changed 362 of 1,754 counter rows.
- **`orderBy()` on a relation that already declares an order APPENDS** — the leading clause still
  decides, so the added one is dead. Use `reorder()`. A fixture needs at least two rows on the far
  side for the bug to be distinguishable from correct behaviour.
- **`Collection::offsetGet()` does not support chained nested mutation** — build nested structures
  as plain arrays and convert once at the end.
- **Eloquent `Collection::merge()` calls `getKey()` on incoming items** — it throws outright on a
  plain array of strings. `->toBase()` first.
- **A plain `throttle:N,1` shares ONE counter per IP (or per user) with every other plain throttle
  on the site.** The limit differs per route, the count does not. Always name it:
  `throttle:20,1,battlenet`. Unnamed, the desktop app's connect polling (every 2s) used up
  Battle.net sign-in's 20 from the same PC and a tester got "Too many requests" (2026-10-14).

**Blade**
- **A directive glued to a word character is not compiled** (the `\B` guard):
  `...diminished@endif` passes through as literal text while its partner compiles, unbalancing the
  block — and the directive counts still look balanced, because one of each is being ignored.
  Suspect this on an unbalanced-directive error that makes no sense.
- **A component tag with a multi-line attribute containing nested escaped quotes silently doesn't
  compile** — it renders as literal `<x-...` text, which then hides whatever error was inside it.
  Extract the expression to a variable in `@php`.
- **`@json([...])` with a multi-line literal breaks the directive-arg parser** — build the array
  in `@php` and `json_encode` it.

**Tailwind**
- **An arbitrary variant or value inside a Blade `{{ }}` ternary may silently not compile.**
  `[&_button]:pointer-events-none` produced no rule at all while plain classes did, which would
  have shipped a read-only view whose buttons still worked. Grep the built CSS for the generated
  rule before trusting it.

**Assets**
- **Use host-relative paths (`/storage/...`), not `Storage::disk()->url()` or `asset()`.** Those
  build an absolute URL from `APP_URL` (`http://localhost`) while Herd serves the site on a
  `.test` domain — so every image 404s in the browser while every CLI check passes.

**Migrations**
- **Never use a DB enum for an evolving vocabulary** — use a plain string column governed by a PHP
  enum. A raw `ALTER TABLE ... MODIFY COLUMN` is invalid on SQLite, and the suite runs on in-memory
  SQLite: one such migration broke all 167 tests at once. If you must change an existing enum,
  branch on `DB::connection()->getDriverName()`.
- Run `php artisan test` after any migration touching an existing enum column, not just after
  touching application code.

**Testing**
- **A request's `set_time_limit()` or `ini_set()` changes the whole suite**, which runs every test
  request in one PHP process. `CoachUploadController::roomToMeasure()` set 120s, so the first upload
  test started a clock that killed the run two minutes later ("Maximum execution time of 120 seconds
  exceeded", inside whatever test was running). Since 2026-11-08 it skips under `runningUnitTests()`;
  a new per-request limit needs the same guard.
- **Only the FIRST `Livewire::test()` of a given component in one PHP process returns full HTML**;
  later ones return a stub. Verify per-component renders in separate processes, or an empty render
  reads as a real page bug.
- `RefreshDatabase` gives an empty schema, and **spells only ever come from `import:spelldata`,
  never a seeder** — build fixtures, or verify against the live dev DB instead.
- `RefreshDatabase` does not reset the filesystem. A test that writes a fixture into a real data
  directory is contaminated by the real data — assert invariants (ordering, caps, absence of
  leaked names) rather than exact counts.
- **Never benchmark a write path against the real database.** A benchmark's own cleanup deleted
  five real steps from a live guide; they were recovered only because MySQL binary logging
  happened to be on. Read-only paths are enough to profile a component.

**Tooling**
- **Always pass Pint an explicit file list.** `./vendor/bin/pint app/` reformatted 138 unrelated
  files and buried the real diff.

**Shell and encoding**
- **PowerShell's `Set-Content` writes a BOM.** It broke `MindCollector.Desktop.csproj` on the 0.6.5
  bump; strip it with sed, or edit the file with the Edit tool.
- **Quotes in a Bash heredoc break an inline Python script.** Write the script to the scratchpad with
  the Write tool and run it through PowerShell's `python`.

**Layout**
- **A box with its own scroll bar hides what is below it on a phone, and reads as missing.** The Watch
  page's players' box was capped at 30vh; on a phone the teams stack, so the enemy team was reported as
  not being in the timeline at all (2026-10-30). All six players had been in the page the whole time.
  Check a page at phone width before calling it done.

## Working practice

- **Trace to the root cause; don't shotgun-patch.** Several fixes in the history log were applied
  at the wrong layer first (Garrote's double-counted silence was "fixed" once before the real
  mechanism turned up).
- **Flag, don't guess.** If a value can't be derived honestly, show `(varies)`, "no verified
  answer", or a named gap. A confidently wrong number is worse than a visible hole. This applies
  to curation, to AI-proposed classifications, and to prose.
- **Verify against something independent.** A round trip through your own encoder and decoder
  proves nothing — `encodeBuild()` passed exactly that test and shipped broken. Compare against
  real captured data, a real screenshot, a real in-game reading.
- **Quantify before and after.** "44 windows over-counted to 0", "1,015 spells affected, 694
  recoverable", "12,264 entry pairs, zero mismatches" — the numbers are what make a claim
  checkable, and they have caught several fixes that were solving the wrong problem.
- **Don't trust a subagent's self-report.** One reported success while its own numbers were
  internally contradictory (3,357 "processed", 39 files on disk); the real bug was an
  internal-vs-external id mismatch.
- **Check `git status` on sibling files before concluding something is pre-existing breakage.**
  Stash-and-re-run cannot distinguish "genuinely broken at HEAD" from "another writer reverted an
  uncommitted fix minutes ago" — that mistake produced a confidently wrong diagnosis.
- **Curated game facts come from the domain expert or real observation, never from AI research
  presented as ground truth.** Secondary sources (including `dr-categories-reference.md`) are
  hints to verify, not authorities — that file has been confirmed wrong on both a spell's category
  and the DR falloff step count.
- Arena-log *cast* evidence is direct observation (a real player, a real match, a spec recorded in
  the match's own metadata) and is a stronger tier than the structural inference this project has
  tried and reverted. `wow:diff-arena-spells --apply` writes on that basis deliberately.
