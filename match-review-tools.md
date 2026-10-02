# Match Review — The Tools, and What an AI Analysis Needs

Written 2026-10-01 from Chriso's notes on the AI match analysis. Two things: **a list of every tool
the analysis has had to build, what it is and why it was made**, so the next analysis starts from
them instead of rebuilding them; and **answers to what the analysis depends on**, from what
actually happened in the 26 Sep and 30 Sep reviews.

The method each tool implements is in `match-review-operations.md`; the findings are in
`match-review-analysis.md`. This file is the index between them.

---

## Why this list exists

Chriso's observation: a cheaper model (Haiku 4.5) asked a question the saved analysis cannot
answer spends about as many tokens as a stronger one, because it has to do the same work. A
stronger model (Opus) answers from what is saved, but also writes one-off scripts in its
scratchpad to get there. Those scripts are lost when the session ends.

**The expensive part of an answer is reading the log, not reasoning about it.** A 3v3 game is tens
of thousands of lines, and every new question means parsing them again. A saved tool turns that
into "run one command, read a table", which any model can do cheaply. So every question answered
by a one-off script should leave behind either a saved tool or a line here saying what the script
did, so the next one is written in minutes, not rediscovered.

---

## Saved tools (`tools/match-review/`)

Run from the repo root through PowerShell (`php -d memory_limit=1G tools/match-review/<tool>`).
Their JSON and text output is gitignored: it names other players.

| Tool | What it is | Why it was made |
|---|---|---|
| `rosters.py` | Every 3v3 game of the 26 Sep team: result, both MMRs, enemy specs → `games.json` | the review table needs one row per game before anything can be grouped |
| `experience.php [games file]` | Blizzard profile lookup for every player: highest 3v3, Gladiator/Legend seasons, best rank → `experience.json` (adds to it) | early-season MMR is deflated; experience says how strong an opponent really was |
| `killread.php` (`--me --mate --date`, `HH:MM` for detail) | The team read: goes as chains, drain, bait, utilities, healer sustain, CC timing, peak burst, the kill read, the review table | "why did we win or lose", in the go model |
| `warrant.php` (`--mate=Name\|* --date=YYYY-MM-DD --only=HH:MM,…`) | Every defensive and Medallion: what it broke, health and time to live, a verdict. Defaults to the 26 Sep team (`--mate=Hozzaarr`); `--mate=*` reads every game of `ME` | "was the trinket warranted, was the second defensive needed" |
| `rotation.php NAME` (`--with --date`) | One player: talents from the log, casts a minute, damage by ability, the casts around each major cooldown, resource and buffs at each press | the source for a class guide, and "how does this player play" |
| `specread.php` (`--spec --col --with --deaths --bracket`; a column's sixth field `+Talent`/`-Talent` filters by build; `--bracket="Rated Solo Shuffle"` reads each round, with the result known for the logging player only) | One spec, several players side by side, per minute alive: output, presses, idle time, CC landed, cooldown use, defensives with health at press, uptimes; and one player's deaths | "how does my Feral differ from a higher-rated one" |
| `feralread.php` (`--col --with --windows`) | Feral only: combo points per finisher, free Bites, every proc's fate (spent on what, or expired), bleeds inside Tiger's Fury, presses after Tiger's Fury, Incarnation windows cast by cast, damage per press and per energy, damage mix inside goes | Chriso's questions about Rastic's Bite build, 2026-10-01 |
| `sessionread.php` (`--me --group=Label:date[:+Name\|-Name] --bracket --detail`) | One player's games split into groups by date and by whether a named partner was in them, read from the stored per-game analysis (no raw log): win rate, enemy Gladiator seasons, goes and kills both ways, and the player's own lockout, healer CC, Medallion and Pain Suppression at each first death | "I won with one partner and lost with everyone else: was it me?", 2026-10-01 |
| `describe.php "Name" …` | What a spell or talent does, as the site resolves it | a "why" must come from the game's own text, not memory. Can pick the wrong copy: read it before citing it |

## One-off scripts that were not kept, and what each did

Short enough to rewrite from the line below. Keep one if the same question comes back.

| Script (session) | What it did | Why | Keep? |
|---|---|---|---|
| survey / pool (30 Sep) | Every Feral and Hunter in the archive: games, MMR range, dates, comps, whose side | to find comparison players before comparing | **yes**: the first step of every comparison. Candidate for a `--list` mode in `specread.php` |
| who (30 Sep) | Which character logged each game (`affiliation: 1`) | found that Rastic's 46 games were Skylake's log | fold into the above |
| comma (30 Sep) | Count COMBATANT_INFO lines the talent regex failed on, by spec | Hunter talents came back empty; found `[,(` | no: fixed in `ArenaLogService` |
| ccmap + ccscan (30 Sep) | Every curated CC spell's aura length across the whole archive, against its curated duration | Garrote's 18-second "silence" | **yes**: an audit worth re-running after every curation change or patch |
| builds (30 Sep) | Talents, PvP talents, gear ilvl and stats per player of a spec, and a talent diff | "does he even have that talent" | partly in `rotation.php`; the diff part is worth keeping |
| gear (30 Sep) | Gear slot by slot, with enchant and gem ids | missing enchants, the ring enchant lead | fold into builds |
| items (30 Sep) | Item names and effects from Blizzard's item API | to name two trinket ids | small, keep with gear |
| pets (30 Sep) | Every pet cast per Hunter | showed Master's Call is pressed from the pet | no: `specread.php` now counts pet presses |
| verify (30 Sep) | Raw-line check of named abilities (Roar of Sacrifice, Tranquilizing Shot…) and which curses/poisons landed on us | before saying "never pressed" | a pattern, not a tool: **always check raw lines before a "never"** |
| hunt (30 Sep) | What other Hunters' Tranquilizing Shot removed, who their Roar went on | Tranquilizing Shot turned out not to be a lead | no |
| forms (30 Sep) | Classified every Cat Form press (broke a snare, after Cyclone, other), what Remove Corruption removed, what each Medallion broke | "is this a wasted global" | fold the Medallion part into `warrant.php` |
| wg (30 Sep) | Found Rastic's Wild Growth came every 120 seconds with no cast line | tied it to Heart of the Wild | no |
| Earlier (26–28 Sep, per the operations file) | mixed-cooldown study, CC-broken-by-own-team study, the answer pool, the big-hit finder | the first studies | lost; would need rebuilding |

---

## What the analysis depends on

### Could it be done without the spell table?

**The facts, mostly yes. The categories, no.** The combat log holds, by itself: every cast with
its resource and combo points, every aura by name and id, all damage and healing, deaths, the
talent node ids, gear ids, both MMRs, and positions on casts. What it does not say is **what
anything is**. Four things came from the spell table and the curated files on 30 Sep:

| From the data | Used for |
|---|---|
| `dr_category` (which aura is control, and which kind) | every lockout, every go's CC links, "healer locked out at the death" |
| `cooldown_seconds` | which presses are commitments at all, so which casts can start a go |
| the hand-reviewed offensive/defensive labels | goes need an offensive cooldown; defensives forced, overlaps, warrant |
| talent nodes and spell text | turning node ids into talent names, and every "why" (`describe.php`) |

Without them a model would have to supply those categories from memory. That fails in two ways
already seen: the game has moved past a model's training (this expansion's Heart of the Wild,
the "Rune of …" buffs and Sudden Ambush's current text were all new to it), and a memory cannot
be checked. **The table can also be wrong** (Garrote was read as an 18-second silence), and it was
the log that caught it. The table gives the words; the log checks them.

### Could it be done without the structure files?

**The spec comparison, yes; the "why did we lose", no.** `specread.php` and `feralread.php`
compare presses, procs, damage and deaths, and need no model of arena. But the team findings
(drain then kill, burst landing on their healer's CC, our healer locked as our cooldowns went,
defensives spent outside their go) are all questions the framework asks. Without it an analysis
produces a scoreboard: damage and healing totals, which say who pressed buttons, not why a game
ended. The model is what turns thousands of events into a handful of decisions.

### Using the combat log and the addon alone?

The arena addon only switches logging on and off, so this is the same as "the log alone". The
log alone gives every fact above but no categories. In practice that means a model can answer
"what did I press, how often, and when did I die", but not "was my go good" or "did I overlap
defensives", because both need something to say what counts as a go or a defensive.

### Does breaking a game into goes help?

**Yes, for why games were won or lost; no, for how a player plays their spec.** Whole-game
averages hid the answers both times: on 26 Sep our healer's lockout *volume* was the same in wins
and losses, and only its *timing inside goes* differed. On 30 Sep the three clearest team findings
are all go measures:

| Measured per go | Wins | Losses |
|---|---|---|
| Our goes followed by a kill | 52% | 0% |
| Our healer locked out as our cooldowns went off | 20% | 61% |
| Their healer locked out during our hardest 6 seconds | 60% | 28% |

The individual findings (deaths, presses a minute, procs, talent trade-offs) needed no goes at
all.

### Has it had to make tools?

Yes, for nearly every new question: fourteen one-off scripts on 30 Sep and 1 Oct alone, and two
new saved tools (`specread.php`, `feralread.php`). **Several found real bugs before they found an answer:** the Hunter talent regex,
the Garrote bleed, the Incarnation aura that shares its name with a Prowl flag, Master's Call
logged from the pet, and a "lockout ended" reading that took the earliest lockout instead of the
latest. That is the other reason to keep the tools: each fix is in the tool, not in a lost script.

---

## How to run the next analysis cheaply

1. **Start from the list above.** Most questions are a column choice in `specread.php` or a date
   in `killread.php`.
2. **Find the players first** (survey/pool): who is in the archive, at what MMR, on whose side.
3. **Run the saved tools, read the tables.** Write a new script only for a question no table
   answers, and add it to this file when done.
4. **Check before saying "never".** A raw-line check, a talent check, and a pet check.
   On 30 Sep one "never pressed" (Master's Call) was wrong, and one "wasted global" (Cat Form)
   was right only two times in three, until they were checked.
5. **Findings go in `match-review-analysis.md`, methods in `match-review-operations.md`**, and
   the tool here.

**Still worth building:** a `--list` mode that does the survey; the CC-duration audit as a saved
tool; and the operations file's own ask, turning `killread.php` into a command with JSON output,
so the page could read a review without a model at all.
