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
- `/wow/players` and `/wow/player/{region}/{realm}/{name}` (`Players\Search`, `Players\Show`,
  2026-10-19, Chriso: "build our own version of check pvp"): any character's exp, titles, ratings,
  the rating history this site has seen (`player_rating_snapshots`), gear and talents. A looked-up
  character is a `battlenet_characters` row with no account (`looked_up_at` set), the same row its
  owner's link would find. The page serves what is stored; only a browser (`wire:init`) asks
  Blizzard, when nothing is stored or it is over 6 hours old, 15 lookups a minute per address. The
  talent tree is drawn the same way, after load: ~330 KB of HTML a character (it was also 5s and
  750 queries until `TalentSelector` kept one description service per tree, 2026-10-19; now
  ~0.2s, its resolved descriptions cached per spell cache version). Public data only, nothing says whether a character is linked here. Looked-up players
  are in the sitemap; linked-only ones never are.
  Also on a player page, none of it asking Blizzard: their places on Blizzard's leaderboards
  (`pvp_leaderboard_entries`, pulled daily by cron, `wow:pvp-leaderboards`), their rating history
  (`player_rating_history`: one row per character, bracket and season, a point per change, written
  by both the pull and a lookup), and every uploaded game they were in, whoever uploaded it
  (`arena_round_players`, indexed whenever a round's payload is saved; Chriso 2026-10-19: "just
  show everything for now"). So a leaderboard player or one seen in a game has an indexable page
  before anyone looks them up. The search box suggests names as you type from all three
  (`Search::suggestions()`), the way Check-PvP does.
- `/top-damage-rotations`, `/cc-chains`, `/cc-review`, `/friends`, `/guilds`, `/profile`.
- `/`, `/wow`, `/sc2`, `/lol` (2026-10-17, Chriso: "look more like a platform"). `/` is MindCollector's
  home (`PlatformController::home`): what it is, and a card per game from `config/platform.php`
  (WoW live, SC2 and LoL coming soon). The WoW front page (`App\Livewire\Landing`) moved to `/wow`
  unchanged, still logged as `landing`; signed-in players are still sent from it to `/dashboard`.
  `/sc2` and `/lol` say what is coming and take "tell me when it's ready" (`game_interests`: by the
  account when signed in, by email otherwise, with a hidden field against scripts; counts on
  `/admin/page-usage`). The sidebar has a game switcher; WoW's menu, its reference pages and its
  training show on WoW's pages only, and a coming game's page shows its own short menu.
- **The 2026-10-18 pass on reading as one product** (Chriso: "Does the website look coherent enough
  as a product?"; the review and its nine points are in the session, the fixes here):
  - `/wow/sample` (`SampleGameController`): four real pages the app drew (a 3v3 game, a Solo
    Shuffle, Improve, a comp) with every name swapped by `tools/app-pictures/anon.py`, committed in
    `resources/sample/wow/`, in a frame with tabs. Anyone can open it. **"Your games" (`/wow/coach`)
    sends a signed-out visitor there** rather than to a bare sign-in box, with "Sign in to see yours".
    Tracked as `wow_sample`. Regenerate it the way the app's pictures are (`tools/app-pictures/README.md`).
  - `/wow` (`Landing`) is the WoW hub: the sample and the app's page up top, then the planning
    tools, "Learn the game" (basics check, class quizzes, spells) and the latest guides. The app's
    pitch, slides and setup steps are `/wow/app`'s alone; the two pages were nearly the same.
  - The WoW menu is grouped Plan (3v3 Comps, Matchup Lab), Guides (PvP Guides, Player guides),
    Learn (Arena Kit, Class quizzes, Arena basics check); the lower panel's public links sit under
    **Lab** (Claude's comp guides, Top Burst Windows, Top 10 CC Chains). "Your library" shows only
    to a signed-in player. The Brain stays out of the menu (2026-10-05).
  - 3v3 Comps and Matchup Lab still open with empty slots (WowComps::PRESET_COMPS says why), and
    their empty state now offers the common comps one click away (Matchup Lab: "RMD against Jungle",
    `applyMatchup()`, counted as two presets).
  - Arena Kit (`components/spells/table.blade.php`): no raw spell ids under names; a modifier that
    names the spell itself, or repeats one already listed (one per internal copy, rule 3), is shown
    once. A signed-in player with Battle.net characters opens on their own spec. The "(varies)" text
    is a resolver gap, counted in `knowledge-gaps.md`.
  - League of Legends is unlisted (`config/platform.php` `listed => false`): off the home, the switcher
    and the sitemap; `/lol` still answers. `/diagnostic`, the old cross-game picker with its own header,
    redirects to `/`.
- `/sc2/quiz`, `/sc2/quiz/{race}`, `/sc2/quiz/{race}/level/{level}`, `/sc2/diagnostic` (2026-10-18,
  Chriso: "do the diagnostic and game quizzes for sc2 so there is some content"). SC2's first
  content, listed as "Open now" on `/sc2` and in its sidebar (`config/platform.php` `now`,
  `PlatformController::openNow`). The quizzes run on the shared engine (`QuizService::GAMES['sc2']`,
  `App\Quiz\Sc2`): four levels per race (costs, supply and time, the tech tree, Light/Armored and
  what an attack hits), every question built from `data/sc2/techtree.json` (the game's own data
  at 5.0.14, sc2-techtree, MIT) brought up to 5.0.17 by `data/sc2/roster.json`'s corrections, each
  from Blizzard's notes. `/sc2/diagnostic` is the learning platform's Commander Profile Assessment
  (`app.screener_sc2_module_id`) at SC2's own address, so the sidebar stays on SC2; its result now
  points at the SC2 quizzes. Tracked as `sc2_quiz`, `sc2_quiz_play` (slot `race:level`),
  `sc2_diagnostic`.
- `/wow/pvp` and `/wow/pvp/{class}/{spec}` (`SpecPvpController`, 2026-10-16) — the spec guides
  written for search. Each answers what players type into Google ("ret paladin pvp talents",
  "disc priest pvp guide midnight": the autocomplete research is in the commit) with numbers counted
  from every player in every measured game: PvP talent pick rates, the cooldowns pressed in a go and
  the CC on the healer (`go-cooldowns.json`), deaths and first deaths against every spec, the
  defensives pressed when a go lands on them, interrupts, and the 3v3 comps they played with their
  record. The numbers are `data/population/spec-pages.json` (`App\Support\SpecPages`), written by
  `wow:population`'s aggregate beside the norms, so the server's `wow:population-refresh` keeps a
  live copy fresh from every uploaded round. A spec with under 8 rounds has no page (no thin pages).
  Every page states its sample, dates, rating band and how much of it is the uploaders' own games,
  and ends with the app. The old `/wow/pvp-guides` pages are titled "Arena Kit" since, so the two
  do not compete for the same searches, and link to the guide.
  **What to kick (2026-10-16):** each page lists the spec's casts worth an interrupt, in the tiers
  of Skill Capped's list for the patch (linked; names and tiers only), each with how often it was
  stopped when that spec cast it in the archive, a never-stopped cast saying so, and the casts
  kicked often that the list leaves out (`App\Support\KickPriorities`, `data/kick-priorities/`).
  "{Spec}s as kickers" gives the share of high-priority casts the spec's players stopped with their
  kick ready (measure 15), against every spec with a kick; under 20 chances it is left out.
  **Fixed the same day:** rows under 5% are left out of the goes and healer-control lists like
  every other list on the page (a "0%" chain was shown), a spec with under 20 measured goes shows
  no go section ("over 1 goes ... Touch of Death 100%" on Mistweaver), and the spec is named as a
  noun throughout ("a Fire Mage", not "a Fire"). **In the menu as "PvP Guides"** (under Plan, and
  the landing page's planning cards); the kit pages are "Arena Kit" there, as their titles are.
- `/admin` (`Admin\Overview`, 2026-10-19, Chriso: "combine the analytics into one spot"): the
  numbers in one place, people only. A visitor is a session where a browser ran a page
  (`confirmed_at`) or someone was signed in; where they came from is each visitor's first outside
  referrer or "Direct or an app link", so the rows sum to the total; the path from a visit to an
  uploaded game; accounts by method; 14 days by day; crawlers and scripts counted apart. Every admin
  page carries a tab strip (`admin/partials/tabs.blade.php`, shown by `layouts.app` on admin routes),
  and the sidebar has one "Admin" link under Home instead of the collapsed group at the bottom.
  Page usage's "engaged" now uses the same rule: it was "two or more rows", which one quiz load by
  a crawler met (276 of 303 "engaged" sessions of 12-19 Oct never ran a page). Diagnostic stats'
  sign-up form count shows people beside the raw page loads, and accounts created by any method.
- Admin: `/admin/content`, `/admin/talent-builds`, `/admin/page-usage`, `/admin/weak-areas`,
  `/admin/diagnostic-stats`, `/admin/api-usage`, `/admin/matches`, `/admin/search-pages`.
- `/admin/search-pages` (`Admin\SearchPages`) — how the spec guides do: readers (browser-confirmed
  sessions), how many came from a search engine, crawler visits, and what readers did next (the
  app's page, the download, connecting the app). Which searches bring them needs Google Search
  Console, not yet connected.
- `/admin/matches` (`Admin\Matches`, 2026-10-15) — every game uploaded to the site, by everyone:
  totals, games per day, each uploader (including anyone who connected the app and has sent
  nothing yet), and each game with both teams, the result, MMR and length, opening into its rounds
  and the uploader's own game card (`/admin/matches/{user}/{lobby}.html`). Built from
  `arena_reviews`; only the page's 30 rows are decoded, and MMR is read from the rounds in SQL.
  Played times are the uploader's clock (Australian games read 11 hours ahead of UTC). A game two
  people uploaded is two rows.
