# The site's pages

*Moved here word for word from CLAUDE.md on 2026-10-04, to keep that file to orientation and rules.*

- `/` — `Landing` (public front page: feed of game plans + comp shortcuts). Signed-in players
  redirect to `dashboard`.
- `/dashboard` — `Home` (feed, your guides, characters, friends).
- `/wow/matchup-lab` — `MatchupLab`. Two comps on one clock: whose kill window opens first,
  and why, read at three execution settings. The only page answering a question about a
  *matchup* rather than about one spec or one comp.
- `/wow/game-review/{id?}` — `GameReview`. The matchup read **backwards**, off a game that
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
- `/wow/coach` — `CoachController`, **"Your coach"**: the desktop app's pages (each game, Improve,
  Comps, Shuffle) for a signed-in player, built on the server from the games the app uploads with the
  player's key (made on this page, shown once). Auth, read only from the viewer's own folder. One copy
  of every page with the app; see `match-review.md`, "The same pages on the website".
- `/wow/match-analysis` — `MatchAnalysis`, **"Your analysis"**: a player's own uploaded games combined
  into the wins-against-losses read (review table, who you played, what differed, a takeaway for your
  role). Auth, scoped to the viewer. Each game is measured at upload by `RoundAnalysisService` (the raw
  log is discarded straight after) and every player's experience is looked up by a queued job. See
  `match-review-operations.md`, "Your analysis".
- `/wow/comps` (was `/wow-comps`) — `WowComps`, the heaviest page. Tabs: **How to play it** (first
  and default since 2026-10-05), Offensive/Defensive Cooldowns, Mobility, Crowd Control, PvP Talents,
  Burst Window.
  - **How to play it** (`CompPlaybookService`, `livewire/partials/comp-playbook.blade.php`) is a
    plain guide for a player new to arena, so someone can be sent to the page: eight basics (shown
    alone before anything is picked), then for the three specs: lock their healer (the first three
    steps of `CcFormulaService`'s chain, each saying whether it can be kicked and whether damage
    breaks it), the buttons to press together (what each spec presses in at least half its goes,
    from `data/comp-playbook/go-cooldowns.json`, never guessed from the spell data), what to save
    for the kill target, what never to put on it, defensives one at a time, kicks and peels.
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
- `/wow/quiz` — `Quizzes\WowQuizIndex` / `WowQuizPlay`, plus concept drills at
  `/wow/quiz/{class}/{spec}/drill/{concept}` (`Quizzes\ConceptDrillPlay`). A level and a concept
  are two ways of choosing the same generated question types; `App\Learning\ConceptCoverage` maps
  concept → `brain.md` sections → types, and names why four of the seven concepts have none.
  Drill results are **never** written to `UserConceptMastery` — see the Rules.
- `/characters` — `Battlenet\Characters` / `CharacterShow`.
- `/top-damage-rotations`, `/cc-chains`, `/cc-review`, `/friends`, `/guilds`, `/profile`.
- Admin: `/admin/content`, `/admin/talent-builds`, `/admin/page-usage`, `/admin/weak-areas`,
  `/admin/diagnostic-stats`, `/admin/api-usage`.
