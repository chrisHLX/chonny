# The site's pages

*Moved here word for word from CLAUDE.md on 2026-10-04, to keep that file to orientation and rules.*

- `/` — `Landing` (public front page: feed of game plans + comp shortcuts). Signed-in players
  redirect to `dashboard`.
- `/dashboard` — `Home` (feed, your guides, characters, friends).
- `/wow/matchup-lab` — `MatchupLab`. Two comps on one clock: whose kill window opens first,
  and why, read at three execution settings. The only page answering a question about a
  *matchup* rather than about one spec or one comp.
  - **How a side trades its answers** (2026-10-07, `TradePlanService`, follows the trigger table's
    toggle): each enemy go with the answers it took, their stacked reduction **multiplied** from each
    spell's own "Modify Damage Taken%" effect (Barkskin 20% + Survival Instincts 50% = 60%, not 70%),
    what the thinnest player held when the next go came, and advice from their go cadence against
    the side's cooldowns (alternate the short answers when they go often; two on one go is affordable
    when they go rarely). Grounded in `docs/learning/population-findings-2026-10-07-trading.md`:
    spending more per go did not win more, an answer back for the next go did. Cooldowns shortened
    in play (Savage Momentum) are named as a limit, not modelled.
- `/wow/game-review/{id?}` — `GameReview`, the **same-spec review** page that "Your games" links to
  (the upload moved to Your games; the raw tool output at `/wow/game-review/analysis` is admin-only
  since 2026-10-05). The matchup read **backwards**, off a game that
  actually happened: rounds won and lost, every player's effective healing, absorbs, overheal and
  damage, and a **same-spec mirror** diff of talents, PvP talents, gear and stats. The mirror is
  the unit because an identical kit leaves only build, gear and play.
  - **`auth` middleware, and private to the viewer.** A review names five other players with
    their talents and their gear. It is a signed-in player's record of their own games, never a
    public browser — it shipped public for about twenty minutes on 2026-09-25. The route's
    middleware and the component's own scoping are both meant to be there.
  - **Solo Shuffle only** (`LobbyReviewService::reviewable()` filters on `isRoundBased()`). A
    shuffle reliably produces the mirror; more importantly the 16 oldest archive matches came
    from the wowarenalogs feed and are **other people's games**, which the bracket filter
    excludes by construction rather than by a maintained list of ids. Bringing 3v3 back needs an
    owner recorded at ingest, not a bracket check.
  - **The review artifact is gitignored, not committed.** It was briefly committed, which
    published it. A review is user data; it reaches production by being uploaded by its owner,
    never by a deploy.
  - Reads **only** `data/arena-logs/lobby-reviews/*.json`, never the gitignored archive, per
    rule 14 — there is a test that deletes the archive and still expects the page to render.
    Throughput comes from `CombatantThroughputService`, the first thing here to measure output at
    all; its field offsets are read from the end of each log line and every one was measured, not
    assumed.
- `/wow/coach` — `CoachController`, **"Your games"** (was "Your coach"; since 2026-10-05 the one page
  for a player's own games, merged from Game Review, Your analysis and Your coach). Tabs: Games,
  Improve, Comps, Shuffle, **Classes** (the app's pages; Classes from 2026-10-07: the
  highest-rated and the most experienced player of each spec you met, by class, each with a page
  of what they pressed, `ClassLibraryService`), **Same spec** (the lobby reviews below, each opening on
  its own page) and **Upload** (the browser upload, `GamesUpload`, plus the desktop app key). A
  browser upload now builds the same pages as the app's (`ArenaUploadController::assemble`
  dispatches `BuildCoachPages`), so a player needs nothing installed. Before the merge it was the desktop app's pages (each game, Improve,
  Comps, Shuffle) for a signed-in player, built on the server from the games the app uploads with the
  player's key (made on this page, shown once). Auth, read only from the viewer's own folder. One copy
  of every page with the app; see `match-review.md`, "The same pages on the website".
- `/wow/match-analysis` — **redirects to `/wow/coach` since 2026-10-05** (Improve replaces it; the
  component and its service are kept, unrouted). Before that `MatchAnalysis`, **"Your analysis"**: a player's own uploaded games combined
  into the wins-against-losses read (review table, who you played, what differed, a takeaway for your
  role). Auth, scoped to the viewer. Each game is measured at upload by `RoundAnalysisService` (the raw
  log is discarded straight after) and every player's experience is looked up by a queued job. See
  `match-review-operations.md`, "Your analysis".
- `/wow/comps` (was `/wow-comps`) — `WowComps`, the heaviest page. Tabs: **How to play it** (first
  and default since 2026-10-05), **Basics**, Offensive/Defensive Cooldowns, Mobility, Crowd Control, PvP Talents,
  Burst Window. Since 2026-10-05 the eight basics are their own **Basics** tab, so "How to play
  it" starts with the team's plan (Chriso: people had to scroll past the basics to reach it).
  - **How to play it** (`CompPlaybookService`, `livewire/partials/comp-playbook.blade.php`) is a
    plain guide for a player new to arena, so someone can be sent to the page: eight basics (shown
    alone before anything is picked, with a link to the basics check), then for the three specs:
    lock their healer, the buttons to press together, control for the player being killed,
    defensives one at a time, kicks and peels. Everything from play is in
    `data/comp-playbook/go-cooldowns.json` (`wow:go-cooldowns`):
    - **The healer lock is each spec's signature combo put together** (Chriso's idea, 2026-10-05):
      a Hunter's most common run on the healer is Intimidation > Freezing Trap, a Disc Priest's is
      Psychic Scream, so Jungle reads "Maim or Intimidation > Freezing Trap > Psychic Scream". One
      step per kind of control, stuns first; two players with the same kind are alternatives. A
      spec seen controlling a healer in under 10 goes falls back to the CC formula's pick.
    - **Kill-target control is where players put each stun or silence, counted per player:** each
      player with 5+ placements votes their share on the target, so one prolific player cannot
      decide it (per cast, Rastic's Maim on the healer 108 to 36 outvoted Crawlordx's on the target
      64 to 22). Mean vote 0.6+: kill target (Kidney Shot 0.62, Storm Bolt, Leg Sweep). 0.4–0.6:
      split, settled by **range** (Chriso: the melee is already on the kill target, ranged control
      reaches the healer): melee (10 yd or less) goes on the target (Maim 0.50), ranged on the healer
      (Binding Shot 0.54). Split with no range in the data: shown **both ways** (Chaos Nova). Only
      stuns and silences can be kill-target control; a healer's own stuns stay in its lock. Under 2 voters or 20 placements, the kit's stuns and silences round it out. A run
      holding a kill-target stun is skipped for the lock; a healer's split stun stays in the lock.
    - **Not measured yet:** roots and Solar Beam's silence are not control in the goes, so a Balance
      Druid's "root, beam" cannot come from play.
    - "Never crowd control the player you are hitting" was removed as a basic (Chriso, 2026-10-05).
  - **Why counted from play:** the longest cooldown made Shattering Throw Arms's big button, and the
    classifier's Buff/Spell label made Tricks of the Trade Subtlety's. A spec with too few measured
    goes says so. It does not say which defensives can go on a teammate: the data has no
    personal/external split (see `MatchupProfileService`).
- `/guides/{slug}/edit` — `Guides\Builder` + `Guides\Palette`; `/g/{username}/{slug}` —
  `Guides\Show`; plus `/browse-guides` and `/claudes-comp-guides` (machine-drafted).
- `/pvp-guides/{class}/{spec}` — `PvpGuides` shell over four panels (kit, burst, spells,
  counters), each also standalone at `/class-guide`, `/burst-guides`, `/spells`,
  `/spell-counters`.
- `/spell/{id}` — `SpellDetail`; shared `SpellDetailModal` everywhere else.
- `/wow/quiz/basics/{class?}/{spec?}` — `Quizzes\BasicsCheckPlay`, the **arena basics check**: one
  question per basic (the comp page's list), built by `App\Quiz\Wow\BasicsCheck` from the spec the
  player plays where the data allows (their go button and a partner's from the go-cooldowns file,
  their DR, their defensives; kick questions from the game's cast-time control), authored where it
  cannot (what a go is, when to swap, line of sight), every answer from an [OBS]/[DER] claim with its
  brain.md anchor. The result says basic by basic what to work on. No mastery written (rule 35).
  Linked from the quiz index and the comp page's basics.
- `/wow/quiz` — `Quizzes\WowQuizIndex` / `WowQuizPlay`, plus concept drills at
  `/wow/quiz/{class}/{spec}/drill/{concept}` (`Quizzes\ConceptDrillPlay`). A level and a concept
  are two ways of choosing the same generated question types; `App\Learning\ConceptCoverage` maps
  concept → `brain.md` sections → types, and names why four of the seven concepts have none.
  Drill results are **never** written to `UserConceptMastery` — see the Rules.
- `/characters` — `Battlenet\Characters` / `CharacterShow`.
- `/top-damage-rotations`, `/cc-chains`, `/cc-review`, `/friends`, `/guilds`, `/profile`.
- Admin: `/admin/content`, `/admin/talent-builds`, `/admin/page-usage`, `/admin/weak-areas`,
  `/admin/diagnostic-stats`, `/admin/api-usage`.
