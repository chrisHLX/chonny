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

**Nothing is calculated when a page opens.** A change to a measure needs a re-measure; a change to
how a page looks needs only `wow:game-cards`.

| You changed | Run |
|---|---|
| `RoundAnalysisService`, `ArenaMomentService`, `CooldownLedgerService`, a classification file | `php -d memory_limit=2G artisan wow:sync --skip-ingest --fresh` (about 8 minutes for 260 rounds), then `wow:game-cards` |
| A classification file (it also feeds the site) | first bump the spell cache version, then `wow:precompute-spell-kits`, then `wow:build-matchup-profiles` (rules 17, 19, 31), then re-measure |
| A desktop view, `GameCardService`, `ImprovementService`, `CompLibraryService` | `wow:game-cards` (it notices the code change); `--fresh` redraws everything |
| `MindCollectorLogs.ps1` | restart the app: tray icon, **Exit**, reopen |

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
| `.../contextual-cooldowns.json` | spells read per press (Vanish, Mass Invisibility, Master's Call...) |
| `.../short-defensives.json` | defensives under the 45s floor that still count (Feint, Crimson Vial, Fade...) |
| `spells.dr_category` (curated, `cc-synergies-overrides.txt`) | what counts as crowd control, and which kind |
| `data/matchup-profiles/{class}/{spec}.json` | each spec's answers, control and interrupts (default build); the answer sheet starts here |

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

## Open items

- Skull Bash is not flagged as an interrupt (the flag needs `import:spelldata`).
- The warrant read (question 2) is not stored, so stored data cannot say whether a defensive was
  needed. It needs health at each press, stored at sync.
- Comp advice needs other players' games against the same comp: pooling uploads needs an opt-in.
- A before-the-gates card needs the addon to read enemy specs in the prep room; the combat log has
  only your own team until the gates open.
- Clips or screenshots at each enemy cooldown: measure the combat log's write delay first.
- The Improve, Comps and answer-sheet pages have been checked as text, not yet looked at on screen
  by anyone but the user.
