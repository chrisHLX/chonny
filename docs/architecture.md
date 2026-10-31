# Architecture

How the pieces fit: the match review, the game-data pipeline, the spell object, talent builds, kits,
pages, guides, the dormant learning platform and the player model. Moved here word for word from
`CLAUDE.md` on 2026-11-01. "Smaller pieces" at the end is new: every service, page and command no
other doc named.

### The match review and the desktop app (the current work)

Your own combat log → `wow:ingest-combatlog` → the archive (`D:/MindCollector/arena-logs`,
gitignored) → `wow:sync` → `RoundAnalysisService` measures each round once into
`arena_rounds.payload` (at `RoundAnalysisService::VERSION`) → `wow:game-cards` renders the desktop app's pages (game cards,
Improve per character, the comp library) as HTML files → the app shows them. **Since 2026-10-25 the
app on Chriso's PC is the downloadable one (`tools/desktop-app`)**: it sends games to the site, which
builds the same pages, and in developer mode (`LocalSyncDir` in its settings.json) runs the ingest and
`wow:sync` locally after each game, the job `tools/log-manager/MindCollectorLogs.ps1` did. That
PowerShell app is retired from the PC but kept in the repo. `/wow/match-analysis` reads the same
payloads on the site. **Read `match-review.md` before changing any of it**: what to re-run after a
change, the data files that decide what is seen, the reading rules and the traps.

Laravel + Livewire 3 + Alpine + Tailwind. Redis queues.

### The game-data pipeline (the foundation)

```
SimulationCraft dumps ──┐
Blizzard Game Data API ─┼─→ import:spelldata ─→ spells / talent_* / pvp_talents / spell_*
Addon spellbook export ─┤        (+ curated override files, applied every run)
Arena match logs ───────┘
```

- `ImportSpellData` + `SpellDataFileParser` read `data/spelldata`, `data/talenttrees`,
  `data/pvptalents`, all joined on Blizzard's external `spell_id`.
- Curated override files in `data/spelldata/` are re-applied on every import and are the
  authoritative source for anything the raw data can't say (see Rules).
- Global post-import passes build `spell_relationships`, resolve description references,
  materialize the `SpellProfile` shape, and rebuild `spell_counters`.
- Arena logs (`data/arena-logs/`, external archive via `ARENA_LOG_ARCHIVE_PATH`) feed CC chains,
  burst windows, playstyle analysis, CC targeting, and offensive/defensive classification.

### One spell object

`App\Support\SpellProfile` + `SpellProfileBuilder` are **the** representation of a spell.
Build-independent facts (school, `dr_category`, duration, mechanic, category, immunities,
counters, base cooldown, `is_*` flags) come from materialized columns; build-dependent ones
(talent-modified cooldown/charges, resolved description, active modifiers) are nullable and
absent without a `TalentBuild`, distinguished by `hasBuildContext()`. It implements
`ArrayAccess` as a bridge for older array-shaped consumers — **new code uses the typed
properties.** `SpellProfileBuilder::forDetail()` (one spell) and `forKitEntry()` (batched) are
the only constructors.

`config/spell_display.php` holds the single definition of category/DR badge colours and
`usable_while_cc` labels. There were once ten copies, and they had drifted.

### Talent builds

`TalentSelectionService::resolveActiveBuild()` resolves in order: the module/guide-linked build
→ the viewer's own saved build → the spec's admin default (`is_default`) → an empty shell.
Scopes are `user_id` (personal), `is_default` (spec-wide meta), `module_id`, and guide-slot
builds (`user_id NULL` + `is_default false`, invisible to both default lookups).
`/admin/talent-builds` is where meta defaults are curated. `TalentSelector` is the one
calculator component — read-only mode, default-editor mode, and guide-slot mode
(`#[Locked] $buildId`).

### Spec kits

`SpecKitComputer` answers "every spell this spec can press, categorised". Precomputed into
`data/spell-kits/{class}/{spec}.json`, keyed by the spell cache version plus a deployed-code
fingerprint; falls back to a live compute when stale (6,964ms/3,042 queries vs 970ms/146 for a
3-spec WoW Comps render — safe but slow, so keep the kits fresh).

### Pages

`/` MindCollector's home, a card per game (`PlatformController`, `config/platform.php`) · `/wow`
WoW's home (`Landing`; signed-in players go to `/dashboard`) · `/sc2`, `/lol` coming games, with
"tell me when it's ready" (`game_interests`) · `/sc2/quiz` and `/sc2/diagnostic`, SC2's first content
(quizzes from the game's unit data, `App\Quiz\Sc2`, `data/sc2/`) · the sidebar's game switcher shows each game's own menu ·
`/wow/pvp/{class}/{spec}` spec guides written for search · `/wow/comps` (the heaviest page) ·
`/wow/matchup-lab` · `/wow/coach` (**"Your games"**: the desktop app's pages, uploaded by the app with
the player's key or from the browser, plus the same-spec reviews at `/wow/game-review/{id?}`;
`/wow/match-analysis` redirects there) — a signed-in player's OWN games: auth,
scoped to the viewer, never public · the guide builder and readers
(`/guides/{slug}/edit`, `/g/{user}/{slug}`, `/browse-guides`, `/claudes-comp-guides`) ·
`/pvp-guides/{class}/{spec}` · `/spell/{id}` · `/wow/quiz` · `/characters` · admin pages ·
`/training` (Training: reasoning lessons with notes that come back, beside Home in the sidebar,
across every game; `docs/training.md`).
What each does, and the decisions behind it: `docs/site-pages.md`.

### Guides

`user_guides` (type `comp`|`class`, status draft/published, visibility public/invited) →
`user_guide_sections` (kind `Sequence`|`Defensives`|`Text`, placed by `(row, column)` so two can
sit side by side) → `user_guide_blocks` (each stores an **external** spell id plus
`source_spec_id`). Roster in `user_guide_members` (up to 3, with per-slot talent builds).
`UserGuideChainService` is the single resolution path — the builder and the read view must
render identically. Collaboration via `friends_can_edit` / `guild_can_edit`, with per-block and
per-section attribution. Guests get a real plan via `GuestPlanService` (cookie token, claimed on
sign-up).

### Machine-drafted guides

Guides a model wrote, **published to be corrected**: drafts in `data/machine-guides/`. Everything
about them is in `docs/machine-guides.md`. The rules that bite:
- **Author only with `guides:author {path}`**, `--dry-run` first (resolves every ability by name,
  flags talent conflicts). Never through tinker. Idempotent by slug.
- **Re-authoring DESTROYS anchored reader comments** (cascade delete). Run
  `guides:export-feedback` on the server and commit its output first.
- `guides:author` rewrites `status` on every run: `--draft` unpublishes.
- A draft must name a build one character can have (`TalentFeasibilityService`). Talent node ids
  are not stable across environments; names are the key.
- The data cannot model who a spell may be cast on (Banish and Shackle Horror shipped as player CC).
- Every guide is written from `arena-structure.md` (public at `/brain`); a [HYP] is never asserted.
- `/claudes-comp-guides` (machine `user_guides`, commentable) is not `/wow/claudes-guides`
  (hand-authored JSON, read-only). Machine guides stay out of player listings (rule 30).

### Dormant: the learning platform

Categories → Subjects → Modules → Questions, quizzes, credits, AI generation, diagnostics, the
Next Step / Reflection loop, roadmaps. **Still live code, not the current direction.** It lives
at `/learning-profile` (at `/training` until 2026-10-23, when that address went to Training, which
is a separate system: `docs/training.md`), `/modules`, `/collection` and is deliberately unlinked from the sidebar. See the
history log for its full design. If you return to any of it, decide first whether it should
become part of class quizzes rather than restoring it as it was.

Things still true about it:
- Subject-scoped queries **must** filter by `$currentSubjectId`, never "most recent across all
  subjects" — that silently shows the wrong subject's data and won't surface with one subject
  seeded. Category/subject selection is remembered in `session('context.*')`.
- `NextStepService` is the only "what's next" system; `SuggestionJob` and
  `diagnostic_profile.recommended_module` were both retired.
- `Module` uses `slug` as its route key.
- `AiService`'s four private call methods each already write an `AiRequest` and deduct credits —
  **never** add a second write in calling code.

### Player model

All player data is **behaviour** (inferred from interactions), **context** (declared: class,
race, role, rating, goals), or **evidence** (observed performance). Context filters and flavours
content; **it never partitions mastery.** Concepts stay universal — one "Cooldown Management",
not a Mage version. Mastery earned through Rogue-flavoured modules persists if the player
rerolls; accept that, don't "fix" it by splitting concepts. If a new field is none of the three,
it probably doesn't belong.

## Security

- reCAPTCHA v3 on login/registration/password-reset, threshold 0.5, direct siteverify call.
- Google and Battle.net sign-in skip reCAPTCHA deliberately — the provider's own sign-in is a
  stronger bot barrier than a score.
- **No password breach check.** `->uncompromised()` was removed; `Password::defaults()` is
  `min(8)`. It turned away real people on every attempt.

## Smaller pieces

What the area docs do not cover, so that nothing in the code is undocumented. Each file's own
docblock has the detail.

**Services**
- `BattlenetClient`: every HTTP call to Blizzard for sign-in and the profile API (plain HTTP, no Socialite).
- `SocialSignupService`: an account from Google or Battle.net, step for step with the email form.
- `GuestResultsClaimService`, `IntendedCompService`: carry a guest's results, and a comp they picked on
  `/wow-comps`, across sign-up.
- `FriendshipService`: the only writer to `friendships`; one of the two ways into editing someone's guide.
- `SubjectContextService`: the only writer of declared context (`user_subject_context`).
- `PatchResolver`: which `patches` row an import writes to, and its label (rule 7).
- `SpellChangeRecorder`: what an `import:spelldata` run changed, written as one `SpellDataUpdate`.
- `SpellSynergyService`: what changes an ability and by how much, for a guide's Synergy section.
- `CharacterTalentResolver`: a synced character's talents onto the current patch's tables.
- `CcChainStatsService`: lookups over the chain corpus (`wow:find-cc-chains --json`).
- `PlayerGamesService`: the games a player was seen in, for their public page.
- `RoundPlayerIndexer`: a round's players into `arena_round_players`, which the Leaderboards read.
- `VersioningService`: a module's next version string; nothing calls it now.

**Commands** (the everyday ones are in `CLAUDE.md`)
- Default builds: `wow:export-default-talents` writes every admin default to a committed file,
  `wow:apply-default-talents` reproduces them anywhere with no outside calls.
- CC chains: `wow:find-cc-chains --json` scans the archive, `wow:cc-chain-patterns` reports openers and
  transitions, `wow:cc-formula` builds a chain for a comp as the WoW Comps page does.
- One match: `wow:analyze-kill`, `wow:kill-sequence`.
- Rotation exports: `wow:enrich-rotation-talents`, `wow:enrich-rotation-mechanics` (both run by
  `wow:refresh-match-derived`), `wow:repair-rotation-talent-ids`.
- Research: `wow:key-offensive-abilities`, `wow:test-opener-theory`, `wow:experience` (players'
  arena experience as JSON).
- Upkeep: `wow:prune-patch` (a stale patch row, remapping curated references first),
  `guides:prune-guest-plans`, `battlenet:sync-characters`, `roadmap:backfill` (dormant).

**Admin and data-review pages** (not in `docs/site-pages.md`): `Admin\ContentManager`,
`Admin\DiagnosticStats`, `Admin\LogViewer`, `Admin\WeakAreas`, `ContentQa`, `JobDashboard`,
`GenerationProgress`; `/wow/cc-immunity-review` (`CcImmunityReview`, read-only), `/wow/cc-review` (`CcReview`);
`/wow/game-review/analysis` (`GameReviewAnalysis`, the review tools' raw output). Spec reading views: `ClassGuide`,
`TopCcChains`, `TopDamageRotations`. The dormant platform's: `GuestRoadmap`, `SubjectContextForm`,
`TimedQuiz`.
