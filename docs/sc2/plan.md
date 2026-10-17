# StarCraft II on MindCollector: where its data comes from, and a first planner

Status: **research, 2026-10-17. Step 1's data is in (2026-10-18):** `data/sc2/techtree.json` is
sc2-techtree's extraction at 5.0.14, checked against all ten cost, supply and time changes in
Blizzard's 5.0.14 notes (all matched), and `data/sc2/roster.json` brings the fields the quizzes use
up to 5.0.17 from Blizzard's 5.0.15 to 5.0.17 notes (Spire, Viking, Ghost; 5.0.16 changed much
more and 5.0.17 put it back). `Sc2TechTree` reads both. The first thing built on it is the quizzes
(`/sc2/quiz`, `App\Quiz\Sc2`); the unit reference pages are next on the same reader. What else
it starts with is Chriso's call (`VISION.md`, "The platform").

What the extraction gets wrong or leaves out, found while building the quizzes: costs are
cumulative (a Baneling's includes its Zergling, a Zerg building's its Drone; `Sc2TechTree` takes
them off); several units have no weapon in it (Battlecruiser, Void Ray, Sentry, Oracle, Carrier,
Baneling), so "what can it hit" is not asked of them; the Baneling's morph from a Zergling is
missing (added in `roster.json`); the Ravager's requirement reads as a Hatchery. High Templar and
Dark Templar build times are not asked until a new extraction settles them.

## Is there a planner like WoW's?

No. Nothing SC2 exists yet. Everything WoW has stands on the spell data: the spec kits, the comp
builder, the Matchup Lab, the guides and the coach all read a button through it. SC2 needs the same
foundation first: every unit, building, ability and upgrade, with its cost, build time, supply and
requirements, from the game itself.

## Where SC2's game knowledge comes from

| Source | What it has | Use |
|---|---|---|
| Blizzard's SC2 API | Ladders, leagues, seasons, player profiles. **No unit or ability data.** | Ratings and league later, the way WoW reads arena history from Battle.net. Old community reports say some ladder endpoints served stale data; check before relying on it. |
| The game's own data files | Units, abilities, upgrades, costs, build times, requirements, weapons, in the game's XML (the Liberty, Swarm and Void mods merged in load order) | **The foundation.** `sc2-techtree` (MIT) extracts it from an installed game into JSON, the way WoW's data comes from SimulationCraft's dumps of the client. Re-extract after each balance patch, and read which game version a file came from before importing it (the lesson of rule 6a). |
| Liquipedia | Human-written unit pages and build orders | A hint to check against, never the authority (like `dr-categories-reference.md` for WoW). CC-BY-SA. |
| Replays (`.SC2Replay`) | The whole game, both players: every unit started, finished and killed, upgrades, positions of units in combat, and each player's stats at intervals (supply used and made among them) | **The match data**, the SC2 version of the combat log. Blizzard's `s2protocol` (MIT) decodes them; `s2protocol-rs` is a Rust port. The game saves every ladder replay to the player's Documents folder by itself, so the desktop app can watch that folder the way it watches WoW's Logs. |

Two facts to verify on a real replay before any measure rests on them: which fields `PlayerStats`
carries (resources collected, workers, army value) and how often it is written.

## WoW to SC2, piece by piece

| WoW | SC2 |
|---|---|
| Spell data (`import:spelldata`) | The tech tree: units, abilities, upgrades, costs, times, requirements |
| A spec's kit | A race's roster and tech |
| Combat log, arena rounds | Replays, one game each |
| `RoundAnalysisService` measures | Workers at set times, supply-blocked seconds, idle production, unspent money, army value at timings, scouting, losses traded |
| Population norms by spec | The same by race, matchup and league |
| Comp builder, Matchup Lab | Build planner, army comparison |
| Improve, game cards | The same, from replays |

## The first planner: a build planner

Pick a race and matchup and write a build ("14 pool, 16 hatch, 18 gas..."). The planner runs it
against the clock: income from workers, supply, build times and requirements from the tech tree. It
shows when each thing finishes, every supply block, and money left unspent. Then it compares: the
plan against the player's own replays (where they fell behind it, and by how much), and against
the same build from strong players' replays.

The honest limit: mining income in the planner is a model. Calibrate it against the resources
replays record, and say on the page that it is an approximation, the way the Matchup Lab says every
cadence is an upper bound (rule 34).

## Steps

1. **The tech tree into the database** under a `sc2` game row (the schema already isolates reference
   data by game), at a pinned game version. A unit and build reference on the site is the first
   thing a player can use, and it is quick.
2. **The replay reader.** Where it runs is the main decision:
   - on the server, as a job: `s2protocol` needs Python, a second runtime to keep working;
     `s2protocol-rs` builds to one binary PHP can call, with no runtime. Replays are small (tens to
     hundreds of KB), so the app can upload the files whole, as it uploads WoW rounds.
   - in the desktop app: no maintained .NET decoder was found, so it would mean porting one.
   The server, with the Rust binary, keeps the app thin and the decoder updatable per patch.
3. **Measures, cards and Improve** for SC2, from the replays.
4. **The build planner**, on the tech tree and calibrated against replays.

## Open questions

- Which game version the current `sc2-techtree` extraction matches, against today's ladder patch.
- `PlayerStats`'s fields and interval, from a real replay.
- Whether the ladder API serves current data in every region.

Sources: [Blizzard/s2protocol](https://github.com/Blizzard/s2protocol),
[BurnySc2/sc2-techtree](https://github.com/BurnySc2/sc2-techtree),
[sebosp/s2protocol-rs](https://github.com/sebosp/s2protocol-rs),
[StarCraft II Community API update (Blizzard forums)](https://us.forums.blizzard.com/en/sc2/t/starcraft-ii-community-api-update/6803).
