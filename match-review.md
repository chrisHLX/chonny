# Match review and the desktop app

The current work. MindCollector reads each player's own arena games and tells them what to work
on: a coach for one player, measured against the players they actually meet (the direction set on
2026-10-02: `VISION.md`, stage 5). This page is the map of that area: what runs where, what is
stored, what to re-run after a change, and which docs hold the detail.

| Doc | Holds |
|---|---|
| this file | the map |
| `match-review-operations.md` | **the method**: how every measure is taken, the combat log's field offsets, the three questions for defensives, the tag audit |
| `match-review-analysis.md` | **the findings**, dated, with the numbers each rests on |
| `match-review-tools.md` | **the research tools** in `tools/match-review/`, and what each was built to answer |
| `tools/log-manager/README.md` | the desktop app, page by page |
| `guides-from-play.md` | how played games become guides, and the level-of-play rule |
| `docs/reviews/`, `docs/ai/` | one-off session reviews; the "Ask about this game" design (not built) |
| `.claude/skills/review-games/SKILL.md` | the workflow for "look at my games" requests: find and sync the games, use the tools, read them honestly, write the findings down |

---

## The pipeline

```
WoW, with the MindCollectorArenaLog addon (turns combat logging on in arena, Advanced logging on)
  │  WoWCombatLog-*.txt
  ▼
Desktop app (tools/log-manager/MindCollectorLogs.ps1) watches the Logs folder; when an arena ends:
  ├─ php artisan wow:ingest-combatlog  → CombatLogIngestService → the ARCHIVE
  │     D:/MindCollector/arena-logs/raw/{match}.log.gz + metadata/{match}.json  (gitignored)
  ├─ php artisan wow:sync --skip-ingest  → SyncArena
  │     ├─ LobbyReviewService (arena_reviews, /wow/game-review)
  │     └─ for each round: LobbyReviewService::deriveRound → RoundAnalysisService::analyse
  │           → arena_rounds.payload {metadata, throughput, combatants, moments, analysis}
  │           (MySQL; this is the ONLY step that reads a raw log)
  └─ php artisan wow:game-cards --dir=%APPDATA%\MindCollector\cards  → BuildGameCards
        ├─ GameCardService      → {lobby}.html        one card per game (Matches page)
        ├─ ImprovementService   → improve-{hash}.html one per character (Improve page)
        ├─ CompLibraryService   → comp-{hash}.html    one per enemy comp (Comps page)
        └─ index.json            what the app lists; each set redrawn only when its signature changes
  ▼
The app shows those HTML files in Windows' browser control (IE11: tables only, no CSS variables).
```

The website reads the same `arena_rounds` payloads: `/wow/match-analysis` ("Your analysis",
`MatchAnalysisService`) for any signed-in player, who can also upload games in the browser
(`ArenaReviewIngestService`; the raw log is discarded once derived).

**The same pages on the website: `/wow/coach` ("Your coach", from 5 Oct 2026).** One copy of every
page, drawn for two readers:
- **Upload:** with a key from `/wow/coach`, the app runs `wow:push-rounds` after each sync. Each
  round's archived slice goes to `/api/coach/round` (`Api\CoachUploadController`), in 768 KB pieces
  when larger, since nginx takes 1 MB a request. It goes through the same `ingestRound()` as
  `wow:sync`, so the server measures it exactly as the PC does. The server never takes a measurement
  from the client.
- **Build:** `/api/coach/done` queues `BuildCoachPages`, which runs `wow:game-cards --web` into
  `storage/app/coach/{user}`. A full build peaked at 324 MB, so it never runs inside a page view. It
  must finish inside the queue's 90s `retry_after`.
- **Serve:** the page lists the games, characters and comps from that folder's `index.json`, and
  shows each page in a frame, read only from the viewer's own folder.
- **What a change needs:** a page or service change reaches the app at the next card build and the
  site at the next deploy. A measure change (`VERSION` up) makes the app send every round again,
  because the server keeps no raw log.
- **Limits:** a large round can need 170 MB to measure, so both upload endpoints raise the request's
  128 MB limit (`CoachUploadController::roomToMeasure()`). Images go through `App\Support\DesktopAsset`:
  `file:///` for the app, `/storage/...` for the site.

**Nothing is calculated when a page opens.** A change to a measure needs a re-measure; a change to
how a page looks needs only `wow:game-cards`.

| You changed | Run |
|---|---|
| `RoundAnalysisService`, `ArenaMomentService`, `CooldownLedgerService`, a classification file | `php -d memory_limit=2G artisan wow:sync --skip-ingest --fresh` (about 8 minutes for 260 rounds), then `wow:game-cards` |
| A classification file (it also feeds the site) | first bump the spell cache version, then `wow:precompute-spell-kits`, then `wow:build-matchup-profiles` (rules 17, 19, 31), then re-measure |
| A desktop view, `GameCardService`, `ImprovementService`, `CompLibraryService` | `wow:game-cards` (it notices the code change); `--fresh` redraws everything |
| `MindCollectorLogs.ps1` | restart the app: tray icon, **Exit**, reopen |
| Anything the website's `/wow/coach` shows | deploy (`./deploy.sh`); the next upload redraws a player's pages |
| A re-measure, a measure change, or a big batch of new games | `php -d memory_limit=3G artisan wow:population` (every archived game, both sides; `--fresh` after a VERSION change, about 8 minutes), then commit `data/population/norms.json`: the Basics tab's norms and the evidence every tip quotes (`docs/learning/population-findings-2026-10-06.md`) |
| The same | `php -d memory_limit=2G artisan wow:go-cooldowns`, then commit `data/comp-playbook/go-cooldowns.json`: the site's comp page ("How to play it") reads from it which buttons each spec presses in its goes, each spec's usual control combo on the healer, and where each stun lands |

---

## What is measured, and where

`RoundAnalysisService` defines every stored measure for one round. `"us"` is always the logging
player's side. Its `VERSION` says what a stored round holds:

| Version | Added |
|---|---|
| 5 | `breakdown` (damage, healing, absorbs by ability; time not pressing) and `checks` |
| 6 | `dispels`, each player's `debuffs` from the other side, `cover` on every go (the cooldown ledger) |
| 7 | `answers` on our first death (the answer sheet), `beforeLockout` on defensive rows |
| 8 | spells used both ways read per press; short defensives (Feint) in the timeline; the 2026-10-04 tag promotions |
| 9 | each defensive row says whether it was needed: whom it went on, health, time to live, warrant.php's reasons, `needed` (danger or breaking crowd control) |
| 10 | `habits` per player: presses per ability, seconds free to act, control and kicks off the damage target (the macro signal), kicks given and casts kicked by spell, a pet's hits on its owner's target; for the logger only, failed casts by Blizzard's reason (range, line of sight, facing, moving, Medallion not ready). Feeds each game's **Basics** tab, Improve's kicked and line-of-sight habits, and `wow:population` |

| Piece | File | What it decides |
|---|---|---|
| The timeline | `ArenaMomentService::readTimeline()` | which casts are *commitments*: a base cooldown of 45s or more, plus `short-defensives.json` from 15s |
| Goes, kill read, defensives, kicks | `RoundAnalysisService` | a go is chained offensive cooldowns; the definitions are in the operations file |
| The cooldown ledger | `CooldownLedgerService` | each player's cooldowns resolved from their OWN talents (`COMBATANT_INFO`); which answers were back at a moment |
| The answer sheet | `RoundAnalysisService::withAnswers()`, `kits()` | every button the team had for the go that killed: ready, pressed, or on cooldown |
| Per-press classification | `RoundAnalysisService::classifyContextual()` | Vanish and the like count as defensive only when pressed in danger or under their go |
| The loss rules | `MatchAnalysisService::lossItems()`, `FAULT_WEIGHTS` | "worth a look" items. The overlap rule was removed on 2026-10-03 |
| Difficulty | `GameCardService::difficultyOf()` | harder: their MMR 50+ above yours, or 3+ more Gladiator seasons |
| Experience | `PlayerExperienceService` | Blizzard profile lookups, cached 7 days; the index keeps older ones |

## The data files that decide what is seen

Every feature sees a button only through these. A spell missing from them is invisible to the
goes, the deaths, the answer sheets and the comp library.

| File | Decides |
|---|---|
| `data/arena-logs/spell-classification/{offensive-spells,offensive-buffs,defensive-cooldowns,mixed-cooldowns}.json` | offensive, defensive or mixed. Hand-promoted (rule 11); the site's WoW Comps tabs read them too |
| `data/arena-logs/spell-classification/contextual-cooldowns.json` | spells read per press (Vanish, Mass Invisibility, Master's Call...) |
| `data/arena-logs/spell-classification/short-defensives.json` | defensives under the 45s floor that still count (Feint, Crimson Vial, Fade...) |
| `spells.dr_category` (curated, `cc-synergies-overrides.txt`) | what counts as crowd control, and which kind |
| `data/matchup-profiles/{class}/{spec}.json` | each spec's answers, control and interrupts (default build); the answer sheet starts here |
| `data/population/norms.json` (`wow:population`) | every spec's norms this season (output per free minute, presses per ability, time controlled, kicks), the habit and go outcome tables across every player in the archive, and the `evidence` table each Basics line quotes; a line with under 20 rounds on either side states no number |
| `data/comp-playbook/go-cooldowns.json` (`wow:go-cooldowns`) | what each spec presses in its goes, counted from every measured round; the comp page's burst reads it. Under 20 goes or 5 players, a spec is "not enough measured" |

**Improve them from play with `tools/match-review/tagaudit.php`.** It sets every spell's tags
beside how it is pressed, and writes proposals. It never applies them. The loop and the 2026-10-04
decisions: `match-review-operations.md`, "Making the tags better from play".

---

## Reading games: the rules this work has learned

Each one is here because breaking it produced a wrong answer at least once.
1. **A count is a question, never a fault.** Read defensives in order: what was pressed, was it
   needed (`warrant.php`), and what it left for the next go (`cdledger.php`). Holding Pain
   Suppression because "overlaps are bad" lost a game on 3 Oct.
2. **Divide by the chance before comparing players.** Dispels per minute of something to dispel;
   kicks per kickable cast with the kick ready.
3. **A pattern must hold across sessions, and against the pooled games, before it is advice.**
   "Burst on their healer's crowd control" held in two sessions and failed over 325 goes.
4. **Judge a decision by its situation**: the enemy go live, their crowd control ready for you.
   Neither the outcome nor the target's health alone decides it.
5. **Every comparison carries its context**: difficulty, your teammates, your sample. Under 10
   games it is a lead (`MatchAnalysisService::LEAD_BELOW`).
6. **Describe; do not assign blame.** The coach asks what the player could control next time.
7. **Test a player's own explanation before accepting it, and say when it fails.** Lower dispels
   were not caused by pressure; Doubletapz's kicks were not slow.

## The research tools

`tools/match-review/` (indexed, with the question each answered, in `match-review-tools.md`):
`patternread` (patterns over every stored game) · `cdledger` (the ledger) · `warrant` (was each
defensive needed) · `dispelread` · `kickread` · `tagaudit` · `specread` (one spec, side by side) ·
`feralread` · `killread` · `rotation` · `sessionread` · `describe`. Their output names other players
and is never committed.

**Leave a tool behind.** A one-off script that answered a question becomes a tool here, plus a
line in `match-review-tools.md`, so the next session runs one command instead of re-deriving it.

## Traps in this area

- **PowerShell variable names ignore case.** A loop variable `$c` replaced the colour table `$C`
  and the app died at startup with no message. Fatal errors now go to the Activity log.
- **The desktop script stays plain ASCII** (PowerShell 5.1 reads it as ANSI): build `▾` or `·`
  from char codes.
- **A Blade directive glued to a letter is not compiled** (`a game@if`): the template then fails
  with an unbalanced `@endif`.
- **The card cleanup deletes every `.html` that is not a game**, unless it is excluded by prefix.
  It deleted the Improve pages on every run until `improve-` and `comp-` were exempted.
- **The classification's `byName` is keyed by the exact name**, not lower case. Lower case missed
  Roar of Sacrifice.
- **A talent is not "ready" unless the player took it**: profiles are the default build
  (`CooldownLedgerService::takes()`).
- **The Garrote aura is the 18-second bleed, not the 3-second silence.**
- **`collect(...)->first()` is null on an empty side**: `null + [...]` throws.
- **A removal in the log is not a dispel.** SPELL_DISPEL also records Phantasm, a shapeshift
  breaking a root and Blessing of Freedom: 115 of a Feral's 133 "dispels" were Cat or Bear Form.
  A dispel is a spell with a `Dispel (38)` effect in the spell data
  (`ImprovementService::dispelSpells()`).
- **A session is read against the player's range of sessions, never their average.** Against the
  average, an ordinary 4-game session read as "better". A rate on a small denominator needs a
  floor too: two dispels on a session's few seconds of poison read as 15 a minute.

## Open items

- The warrant read is stored (version 9) without its replay: whether a defensive *did* its job
  (the damage it removed) still needs `warrant.php` and the raw log.
- Comp advice needs other players' games against the same comp: pooling uploads needs an opt-in.
- A before-the-gates card needs the addon to read enemy specs in the prep room; the combat log has
  only your own team until the gates open.
- Clips or screenshots at each enemy cooldown: measure the combat log's write delay first.
- The Improve, Comps and answer-sheet pages have been checked as text, not yet looked at on screen
  by anyone but the user.
- **Moving the app's pages off IE11 onto WebView2 (Edge), when the design needs it.** Every page
  is drawn in WinForms' `WebBrowser` control, which is IE11: no CSS variables, flex or grid gaps,
  `<details>` or `position: sticky`. **Inline SVG does work**, so charts need no move. Every page
  sets `X-UA-Compatible: IE=edge`, and a test render on 4 Oct 2026 read `documentMode` 11 and drew
  an SVG line chart. `WebBrowser.DrawToBitmap` also screenshots a page with no window on screen,
  which is how to look at one without the user. The WebView2 runtime
  ships with Windows 11 (154.x on the dev machine, 4 Oct 2026). Switching needs three things:
  - the `Microsoft.Web.WebView2` NuGet package's `Microsoft.Web.WebView2.WinForms.dll`,
    `Microsoft.Web.WebView2.Core.dll` and `WebView2Loader.dll`, loaded with `Add-Type -Path`;
  - a writable user data folder (`%APPDATA%\MindCollector\webview`);
  - `EnsureCoreWebView2Async()` before the first `Navigate`. The app already navigates to the card
    files by path (`MindCollectorLogs.ps1`), so only the control's creation changes.

  The pages would not change at first, since modern CSS is a superset. Do this when a page needs
  something IE11 cannot draw (a sticky header, CSS variables), not before. Until then the pages
  stay IE11-safe.
