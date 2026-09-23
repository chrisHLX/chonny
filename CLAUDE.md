# CLAUDE.md

Guidance for working in this repository. Rules and orientation only — **history lives in
`docs/history/engineering-log-2026.md`** (the old, 790KB version of this file, preserved
verbatim). When you need the *why* behind a rule below, search that log for the symptom,
file name, or date.

## Vision (full statement in `VISION.md`)

A website that gives a player everything they need to reach Gladiator or higher in WoW arena:
class/spell/talent/mechanics data pulled straight from the game, combined with player insight
and real match data, so an aspiring Gladiator can model and simulate what happens in arena.
There is no certain path to Gladiator in a given season — but the game itself is knowable, and
that is what the site captures.

Optimise for four things: **speed** for the user; **simple architecture** (fewest moving parts
that do the job); **clean formatting** (resolved, finished output — no broken icons, no
placeholder symbols, no `(varies)` or raw formula text, no duplicate spells); **clean data**
(accurate, current spells, abilities, talents, mechanics).

**Current focus: game plans.** Players build and share arena plans; machine-drafted guides
exist to be corrected. The spell/talent/match-data pipeline underneath must stay correct.

### What "done" looks like for the spell data

- **Nothing unresolved in displayed text** — no `(varies)`, `$s1`, `${...}`, `$lWord:Words;`,
  no raw formula fragments. If a true value can't be computed, get the data; never ship a
  placeholder and never fabricate a number.
- **No duplicate spells** from internal spell_id copies.
- **No broken icons** — every rendered spell resolves a real icon.
- **Accurate and current** — cooldowns, charges, talent modifiers, CC durations, mechanics all
  match the live game.

## Where things are written down

| File | What it is |
|---|---|
| `docs/history/engineering-log-2026.md` | **The archive.** Every dated feature write-up, bug trace, dead end and reversal through 2026-09-18. Not loaded into context. |
| `DEPLOY.md` | Production deploy runbook. |
| `game-data.md` | Spell-data import pipeline, folder-by-folder, with dated findings. |
| `spell-acquisition-model.md` | Architecture map of every acquisition script/command/service. |
| `arena-structure.md` | **The arena model (v2).** Go/anti-go cycle, the answer pool, overlap, globals-denied, rating ladder, and what a guide must answer before it has steps. Every claim tagged [OBS]/[DER]/[HYP] — a [HYP] may be *written* in a guide, never *asserted as settled*. The split is by confidence, not permission (Part 0, corrected 2026-09-23): propose a kill target, rank it, give the reason, say it is reasoning. Read before building anything that generates or scores a plan. |
| `arena-open-questions.md` | What the model still guesses at, with who can settle each one. Answered questions graduate into `arena-structure.md`. |
| `docs/arena/sources/chriso-scope-correction-2026-09-23.md` | **Why the model proposes rather than refuses.** Humility over prohibition; the game's own facts are the constraint, not prose in a doc. Read before adding any "we can't do X" line to the framework. |
| `docs/arena/sources/` | Verbatim sources behind the model, plus a distilled note per source. **Never edit or delete a raw file** — re-distillation runs against it. Tiers differ: the Kalvish transcript and the player's own review are players; `gemini-cooldown-graph-2026-09-23.md` is a language model that had `arena-structure.md` in its context, so its agreement is never corroboration. |
| `docs/arena/synthesis-process.md` | **How to fold a new prose source into the model.** Run this whenever the user adds a transcript. Raw → distilled note → diff against the model → update questions → report what changed. |
| `data/matchup-profiles/README.md` | The Matchup Lab's per-spec artifact: what a profile holds, why control and defensives read different duration columns, and the three things it structurally cannot say. |
| `data/brain/brain.md` | The reader-facing statement of the model, rendered at `/brain`. **Every machine-drafted guide is written from it.** |
| `knowledge-gaps.md` | Append-only ledger of module-prose vs spell-data discrepancies. |
| `wow-spells.md`, `wow-spell-data-model.md` | Spell data model notes. |
| `dr-categories-reference.md` | 2022-era community DR guide. **Stale — confirmed wrong twice.** A hint, never authority. |
| `arena-log-api.md` | WoWArenaLogs API shape. |
| `spellbook-verifier.md` | Addon export → snapshot → diff pipeline. |
| `app/Http/Services/playstyle-analysis.md` | Per-player talent-usage read. |
| `module-upload-format.md` | Shape for drafting module content. |

These are reference, not gates. Implementation decisions are yours. A note that something "can
break" is a warning to check before relying on it, not a rule that blocks the work.

## Commands

```bash
composer run dev          # server + queue + logs + vite in parallel
php artisan serve
php artisan queue:listen --tries=1
npm run dev
npm run build
php artisan pail --timeout=0

composer test             # or: php artisan test
php artisan test tests/Feature/ExampleTest.php
php artisan test --filter=test_name

./vendor/bin/pint app/Path/To/File.php   # ALWAYS pass an explicit file list — see Traps

php artisan migrate
php artisan migrate:fresh --seed
```

**Game data:**
```bash
php artisan import:spelldata wow                 # normal form — no patch arg, see Rules
php artisan wow:patch-update {build}             # orchestrates a full patch bump
php artisan wow:refresh-match-derived            # after ANY match-data change
php artisan wow:apply-icon-manifest              # icons without Blizzard credentials
php artisan wow:precompute-spell-kits            # after a resolver/display change
php artisan wow:build-matchup-profiles           # AFTER the kits, never before
php artisan wow:rebuild-spell-counters           # backfill only; import already does it
php artisan wow:import-murlok-defaults --all --apply   # on-demand only, see Rules
```

**Machine-drafted guides:**
```bash
php artisan guides:author data/machine-guides/            # a file or the whole directory
php artisan guides:author data/machine-guides/ --dry-run  # resolve + talent-check, write nothing
php artisan guides:author data/machine-guides/x.json --draft --model="Claude Opus 5"
php artisan guides:export-feedback                        # run on the SERVER — the loop back
```

### Test suite baseline

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

## Environment

### Windows / Herd (local)

- Run PHP/artisan through the **PowerShell tool**. The Bash tool is git-bash and has **no PHP on
  its PATH** — use it for file/git work only.
- Redis via Herd for queues, sessions, cache. MySQL.
- The site is served on a Herd `.test` domain (`chonny.test`), not `localhost` — see the
  host-relative asset rule in Traps.
- A full re-import may need `php -d memory_limit=512M artisan import:spelldata wow`.
- Treat destructive artisan commands (`migrate:fresh`, `db:seed` on a real DB, `queue:restart`)
  with confirm-first judgment.

#### Looking at a page in a browser

**`chonny.test` is often not resolvable** — Herd is not always running, and the host may not be
in `C:\Windows\System32\drivers\etc\hosts` at all. Do not conclude from that that a page
cannot be checked. Serve it directly:

```bash
php -S 127.0.0.1:8321 -t public     # run in the background, then Invoke-WebRequest the routes
```

Use the built-in server, not `php artisan serve` — the latter failed to bind here
("Failed to listen on 127.0.0.1:8123") while `php -S` on the same machine worked.

**Do this before calling any new page done.** `Livewire::test()` renders a component *without*
its layout, so a full-page component missing its `->layout('layouts.app', [...])` call passes
every Livewire assertion while the real URL returns a 500 (`MissingLayoutException`). That is
exactly how the Matchup Lab shipped its first green test run (2026-09-23). A `$this->get(route(
...))->assertOk()` alongside the Livewire tests catches the same class of error in CI.

### Production

Linux, Nginx + PHP-FPM, 2 Supervisor workers on the default queue (90s timeout each), same
Redis/MySQL/env requirements. **No scheduler runs** — anything scheduled must be triggered
another way.

**Box, measured: 1 vCPU, ~1,958 MB RAM, 5.3 GB swap.** The single core is the throughput ceiling
and it is the thing to remember before tuning anything else: `/` and `/wow-comps` are ~0.12s of
CPU, `/spell-counters` ~0.57s, and latency under concurrency scales linearly (12 concurrent
`/wow-comps` ≈ 1.45s each). No PHP tuning changes that; a second vCPU would. PHP-FPM is
`pm.max_children = 12`, `pm.max_requests = 500`. Size workers from **PSS** in
`/proc/<pid>/smaps_rollup`, never `ps` RSS — OPcache's 192 MB is shared, so RSS overstates
badly (52–62 MB RSS vs 11–20 MB private).

**Deploy with `cd /var/www/mindcollector && ./deploy.sh`, never a bare `git pull`.**
`opcache.validate_timestamps=Off` means a bare pull updates files while running workers keep
executing old bytecode; the queue workers and anything they wrote to Redis go stale the same way.
This caused real 500s for real users (2026-08-28). `deploy.sh` restarts php-fpm and the workers,
bumps the spell cache version, regenerates the spell kits, runs a smoke test, and logs to
`storage/logs/deploy-*.log`. Nginx config is not in the repo (backup:
`/root/nginx-mindcollector.bak-20260917`).

### Working on production (SSH, deploys, permissions)

**When the user asks you to deploy or check something on live, do it — this is the method.**
Don't stop at "I can't SSH from here".

**The box:** Ubuntu 22.04 on Vultr, `45.76.116.44` (== `mindcollector.com`), `root`, code at
`/var/www/mindcollector`, deployed from `github.com/chrisHLX/chonny` (no CI/CD — push to GitHub,
then run `deploy.sh` on the server). The password is in
`C:\Users\chris\Desktop\mytho\MINDCOLLECTOR.txt`. Read that file; don't guess usernames or keys.
The local `id_ed25519` key is **not** accepted.

**How to connect — paramiko, not `ssh`.** The Bash tool's native `ssh`/`scp` can't answer a
password prompt. Use `python -m pip install paramiko` and a small helper, `prod_ssh.py`, in the
scratchpad. It takes `(command, exec_timeout_seconds)` as argv, uses password auth, and prints
stdout and stderr. Put `sys.stdout.reconfigure(encoding="utf-8", errors="replace")` at the top,
or a `●` from `systemctl status` crashes it on the Windows console. The scratchpad is per-session,
so re-create the helper with the Write tool if it's missing.

- **SFTP is broken on this server** (`sftp.put()` → bare `FileNotFoundError`, even to `/tmp`).
  **Upload files by base64-over-exec**: base64 the local file in Python, then run
  `base64 -d > /path << 'B64EOF' ... B64EOF` through `exec_command`.
- **Complex remote PHP: never `tinker --execute`** through Bash → python argv → SSH. Backslashes
  and namespace separators get mangled. Upload a standalone script that bootstraps Laravel
  (`require vendor/autoload.php; $app = require bootstrap/app.php; ...`) and run `php /tmp/x.php`.
  `php artisan tinker file.php` hangs waiting on stdin.
- **Redis cache is DB 1**, not 0 (`predis`, no `REDIS_CACHE_DB` set). Use `redis-cli -n 1 ...` for
  anything `Cache::`-related, or it looks empty.

**Permissions / auto mode — the reason deploys have failed:**
- The user has allow rules for `Bash(python *prod_ssh.py*)` and `Bash(python *run.py*)` in
  `.claude/settings.local.json`. **The command must START with `python` to match them.** A leading
  `MSYS_NO_PATHCONV=1`, a `cd && python ...`, or a `cat > file <<EOF; python ...` compound does
  **not** match. It falls through to the auto-mode classifier, which denies production access.
- So: write helper files with the **Write** tool first, then run a bare
  `python "<scratchpad>/prod_ssh.py" "cd /var/www/mindcollector && ./deploy.sh" 600`. Start the
  remote command with `cd` (not a bare `/var/...` path) so git-bash's MSYS path conversion doesn't
  rewrite it to `C:/Program Files/Git/var/...`. Then you need no `MSYS_NO_PATHCONV` prefix.
- **Allowed this way:** reads (logs, `git status`, `systemctl status`, read-only DB queries) and
  `./deploy.sh`.
- **Blocked even with the rule:** one-off production DB writes (classifier: "Modify Shared
  Resources"). Give those to the user as a ready-to-paste `!` command instead of retrying.
- **Never add an allow rule for yourself.** The classifier denies it ("Instruction Poisoning").
  If a rule is missing, say so up front and ask the user to add it via `/permissions`.

**Deploying:**
- **Always `cd /var/www/mindcollector && ./deploy.sh`, never a bare `git pull`.** With OPcache's
  `validate_timestamps=Off`, workers keep running old bytecode after a pull. Stale queue workers
  and the Redis entries they wrote go stale the same way. This caused real 500s on `/wow-comps`
  (2026-08-28). Bumping the cache version alone did **not** fix it: the stale entry was already
  written under the current version.
- `deploy.sh` restarts php-fpm, restarts the queue workers, bumps the spell cache version,
  regenerates kits, runs migrations, runs composer/npm if lockfiles or assets changed, and
  smoke-tests `/`, `/wow-comps`, `/spells`. It logs to `storage/logs/deploy-*.log`. Full runbook:
  `DEPLOY.md`.
- Commit and push locally first (only when the user asks for a commit). Check the server's
  `git status` if a pull might conflict with untracked files.

**Queue workers:** 2 Supervisor-managed (`laravel-worker_00`, `mindcollector-worker`), 90s timeout
each, default queue, restarted by `deploy.sh`. **No scheduler:** root's crontab is empty, so every
`Schedule::command(...)` in `routes/console.php` (e.g. `next-steps:expire`) is inert on live.

**Performance ceiling:** the 1 vCPU is the bottleneck, not RAM. `/` and `/wow-comps` take about
0.12s of CPU and `/spell-counters` about 0.57s. Latency scales linearly under concurrency.
`pm.max_children = 12` is already at CPU saturation; more workers won't help, a second vCPU would.

### Required environment variables

```
OPENAI_API_KEY=            # AI question/content generation
GEMINI_API_KEY=            # Gemini web-search grounding (ResearchService)
STRIPE_KEY= STRIPE_SECRET=
BLIZZARD_CLIENT_ID=        # Game Data API + Battle.net OAuth
BLIZZARD_CLIENT_SECRET=
BATTLENET_REDIRECT_URI=    # optional override
GOOGLE_CLIENT_ID= GOOGLE_CLIENT_SECRET=   # Google sign-in; button hidden if unset
BUYMEACOFFEE_URL=          # support link; hidden if unset
RECAPTCHA_SITE_KEY= RECAPTCHA_SECRET_KEY=
ARENA_LOG_ARCHIVE_PATH=    # optional; defaults to in-repo data/arena-logs/
AUTH_REMEMBER_DAYS=30
DB_CONNECTION=mysql
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CACHE_STORE=redis
```

Battle.net and Google OAuth need their redirect URLs registered with the provider for **both**
`mindcollector.com` and `www.mindcollector.com` (both serve the site directly), plus the local
`.test` host.

### Security

- reCAPTCHA v3 on login/registration/password-reset, threshold 0.5, direct siteverify call.
- Google and Battle.net sign-in skip reCAPTCHA deliberately — the provider's own sign-in is a
  stronger bot barrier than a score.
- **No password breach check.** `->uncompromised()` was removed; `Password::defaults()` is
  `min(8)`. It turned away real people on every attempt.

## Architecture

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

- `/` — `Landing` (public front page: feed of game plans + comp shortcuts). Signed-in players
  redirect to `dashboard`.
- `/dashboard` — `Home` (feed, your guides, characters, friends).
- `/wow/matchup-lab` — `MatchupLab`. Two comps on one clock: whose kill window opens first,
  and why, read at three execution settings. The only page answering a question about a
  *matchup* rather than about one spec or one comp.
- `/wow-comps` — `WowComps`, the heaviest page. Tabs: Active Abilities, Offensive/Defensive
  Cooldowns, Crowd Control, Mobility, Burst Window, Example CC Chains.
- `/guides/{slug}/edit` — `Guides\Builder` + `Guides\Palette`; `/g/{username}/{slug}` —
  `Guides\Show`; plus `/browse-guides` and `/claudes-comp-guides` (machine-drafted).
- `/pvp-guides/{class}/{spec}` — `PvpGuides` shell over four panels (kit, burst, spells,
  counters), each also standalone at `/class-guide`, `/burst-guides`, `/spells`,
  `/spell-counters`.
- `/spell/{id}` — `SpellDetail`; shared `SpellDetailModal` everywhere else.
- `/wow/quiz` — `Quizzes\WowQuizIndex` / `WowQuizPlay`.
- `/characters` — `Battlenet\Characters` / `CharacterShow`.
- `/top-damage-rotations`, `/cc-chains`, `/cc-review`, `/friends`, `/guilds`, `/profile`.
- Admin: `/admin/content`, `/admin/talent-builds`, `/admin/page-usage`, `/admin/weak-areas`,
  `/admin/diagnostic-stats`, `/admin/api-usage`.

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

Guides a model wrote, **published to be corrected**. The correction is the point, not the guide:
the one input the game data cannot supply is what forces what (`arena-structure.md` Parts 6, 11),
and a specific, checkable, wrong-in-places plan is far cheaper to correct than a right one is to
author. 16 drafts live in `data/machine-guides/`.

- `user_guides.authored_by_model` is a **name** ("Claude Opus 5"), not a boolean — the byline
  prints it, and a second model later is plausible. NULL means a person wrote it.
  `scopeMachineAuthored()` / `scopeHumanAuthored()`.
- **`guides:author {path}`** (`--author=mindcollector`, `--model=`, `--draft`) publishes from a
  committed JSON draft — a single file or a whole directory. **Never author a guide through
  tinker:** a guide written in a REPL exists only in that database, can't be reviewed in a diff,
  can't be re-run after a patch changes an ability, and can't be reproduced on another
  environment. **Idempotent by slug** — re-running replaces sections and steps wholesale (the
  draft file is the source of truth) while keeping the guide row, so its URL, views and reader
  notes survive an edit. Abilities are referenced **by name** and resolved to an external spell id
  once, against the current patch; an unknown name **fails loudly** rather than writing a step
  that renders "ability no longer found". Nothing about a spell is frozen in — cooldowns, DR
  maths and immunities resolve live on every page load, same as a player's guide.
- **A draft must name a build one character could actually have.** `guides:author --dry-run`
  resolves every reference and prints talent conflicts without writing a row; a real publish runs
  the same check and **warns**. `TalentFeasibilityService` flags two abilities of one spec that sit
  on the same `CHOICE` node — Shadowfury/Howl of Terror, Mighty Bash/Incapacitating Roar,
  Avenging Wrath/Avenging Crusader. The first reader caught one of these by eye ("the idea is
  correct, the spells available is not"); the check then found **four impossible plans across the
  11 published drafts**. Matched **by name**, never by id: `talent_node_entries.spell_id` is an FK
  to `spells.id` while guide blocks store the external id, and the talent entry's copy is routinely
  not the pressable copy a step resolves to. It checks choice-node exclusivity only — not point
  totals or gate rows — because that is the constraint that makes a plan impossible rather than
  merely expensive. It is a warning, not a failure: a defensives section may legitimately name an
  alternative the enemy might have taken.
- **The data does not model who a spell can be cast on.** Banish (demons/elementals) and Shackle
  Horror (pets/NPCs) both shipped in published plans as control on players, hedged as "probably
  doesn't work, but if it does it's free". The hedging was the error. Nothing in the pipeline can
  catch this class of mistake — see `knowledge-gaps.md`, 2026-09-18.
- **`guides:export-feedback`** (`--out=`, `--all`) writes every machine guide, its steps and every
  note as markdown. **This is the loop**: corrections come back here and get folded into
  `arena-structure.md`, which is the only thing carrying knowledge between sessions. Markdown on
  purpose — a note is unreadable without the step it is attached to. Run it **on the server**,
  where the notes are.
- **Two different things are both called "notes", and readers were confusing them.** The
  *author's* annotation on a step (`payload.note` on the block, attributed by model name) and a
  *reader's* criticism of it (`user_guide_comments`). Keep them visually distinct — the first
  reader couldn't tell whether his note was overwriting the author's.
- **Reader comments anchor per section**, not per step. Step-anchored comments still exist, are
  still rendered and still exported; a block-anchored comment also records its section id.
  Un-anchored comments are the thread at the foot of the page. A comment's anchor is re-derived
  from the guide's own rows, never trusted from the request.
- **`/claudes-comp-guides` is not `/wow/claudes-guides`.** The first (`Guides\MachineGuides`) is
  machine-drafted `user_guides` — commentable, correctable. The second is hand-authored JSON per
  class/spec under `data/claudes-guides/`, read-only, no comments, carrying its own `"patch"`
  field unrelated to the DB.
- **Every machine-drafted guide is written from `arena-structure.md`, whose public statement is
  `/brain` (`data/brain/brain.md`, rendered by `App\Livewire\Brain`).** Before drafting a guide,
  read the model; a claim that is `[HYP]` there must not be asserted as fact in a guide. Comments
  on `/brain` are corrections to the model itself and outrank a correction to any single guide —
  they are the top of the same feedback loop `guides:export-feedback` sits at the bottom of.
  The brain document's section ids (`{#answer-pool}`) are comment anchors: **reword a heading
  freely, never change an id.**

- `Guides\Show` bylines a machine guide "{model} guide · drafted by a model" instead of
  "Player-written guide", and closes by saying the mechanics are derived while **the plan is a
  guess** — inviting correction. Do not let a model's draft render like a derived fact.

### Dormant: the learning platform

Categories → Subjects → Modules → Questions, quizzes, credits, AI generation, diagnostics, the
Next Step / Reflection loop, roadmaps. **Still live code, not the current direction.** It lives
at `/training`, `/modules`, `/collection` and is deliberately unlinked from the sidebar. See the
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
    every Defensive Cooldowns tab and guide palette.

### Arena logs

12. **Match search is DISCONTINUED upstream.** The WoWArenaLogs API returns `SEARCH_DISABLED`:
    scraping drove their hosting costs up and they turned it off deliberately. **Do not work
    around it** — not by retrying, reshaping the query, spoofing headers, or pacing requests.
    Every search-based puller (`wow:pull-latest-matches`, `wow:pull-scarce-specs`,
    `wow:discover-all-specs`, `wow:pull-low-rated-spec`, `wow:discover-spec-spells`) is dead and
    reports an empty feed. The 689-match archive is a **fixed corpus** now.

13. **Do not cull the archive again.** The 2026-09-05 cull of the oldest 500 matches is permanent
    and unrecoverable now that search is gone.

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

## Design System

### Tokens (`tailwind.config.js`)

Fonts: `font-sans` = Inter (300–800); `font-display` = Playfair Display (italic, 400–900).

| Token | Value | Use |
|---|---|---|
| `surface-0` | `#09090D` | Page background |
| `surface-1` | `#111116` | Cards, panels |
| `surface-2` | `#18181E` | Elevated elements |
| `surface-3` | `#1E1E26` | Modals, overlays |
| `ink` | `#F0F0F2` | Primary text |
| `ink-muted` | `#8A8A9A` | Secondary text |
| `ink-subtle` | `#52525F` | Disabled/placeholder |
| `gold` | `#C8952C` | Brand/CTA primary |
| `gold-light` | `#E8B84B` | Hover gold |
| `gold-dark` | `#8B6420` | |
| `gold-muted` | `#6B4E1A` | Subtle gold fills |
| `gold-subtle` | `#1E150A` | Faint gold tint |
| `violet` | `#7B6EE8` | Secondary/quiz |
| `violet-hover` | `#8B7EF8` | |
| `violet-muted` | `#4A3FA8` | |
| `violet-subtle` | `#18163A` | Faint violet tint |
| `accent` | `#C8952C` | Alias for `gold` (compat) |
| `line` | `#1E1E26` | Default border |
| `line-strong` | `#2C2C38` | Stronger border |
| `line-gold` | `#6B4E1A` | Gold-tinted border |

Gradients: `bg-gold-gradient`, `bg-gold-gradient-v`, `bg-violet-gradient`.
Shadows: `shadow-gold-sm|gold|gold-lg`, `shadow-violet-sm|violet`.
Class colours: `config/wow_classes.php` (Blizzard's own `RAID_CLASS_COLORS`).

### Component classes (`resources/css/app.css`)

`.linear-card` · `.sidebar-item(.active)` · `.form-input|select|textarea|checkbox` ·
`.page-section(-title|-desc)` · `.badge-green|amber|gold|blue|gray` ·
`.tab-btn`/`.tab-active`/`.tab-inactive` · `.btn-primary|secondary|ghost|danger` ·
`.ordering-list`/`.ordering-item` · `.prose-guide` (scoped on purpose — user-written content
must never restyle the site).

### Icons

`<x-mc-icon name="icon-compass" class="w-6 h-6 text-gold"/>` inlines SVG from
`public/images/icons/`, so `currentColor` and Tailwind `text-*` control the colour.

**`<x-icon>` is taken by `blade-ui-kit/blade-icons` — always use `<x-mc-icon>`**, or you get
"Svg by name ... not found".

Available: `icon-complete`, `icon-compass`, `icon-scroll`, `icon-hourglass`, `icon-leaf`,
`icon-starburst`, `icon-lightning-circle`, `icon-flask`, `icon-axis-hex`, `icon-diamond`,
`icon-delta`, `badge-wow`, `badge-sc2`, `badge-lol`,
`gem-mastered|strong|developing|weak|unknown`, `bg-arch`, `bg-constellation`.

Also: `<x-spell-icon>`, `<x-class-icon>`, `<x-spec-icon>` (self-hosted, filename-keyed, with a
plain placeholder — never a broken `<img>` — when `icon_name` is null), and
`<x-ornament.corner position="tl|tr|bl|br">`.
