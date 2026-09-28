# Match Review — Operations

How to conduct a review of your own arena games: how a played game gets from WoW into something
you can read, what the log can and cannot be made to say, what a go is, how each measure is taken,
and the tools that take them. **Findings and their interpretation are in
`match-review-analysis.md`**, not here. This file is the method.

Written 2026-09-27, split from the findings 2026-09-28. `addon-upgrades.md` holds what Chriso
wants next from the capture side.
CLAUDE.md's rule 12 holds the Solo Shuffle log structure and is not repeated here.

---

## Where it stands

Deployed and live at commit `575ea01`. `/wow/game-review` is **auth-gated and scoped to the
signed-in user** — it shipped public for about twenty minutes on 2026-09-25 and that must not
recur; the route middleware and the component's own scoping are both load-bearing.

Two ways in, one assembler:

```
WoWCombatLog-*.txt
   │
   ├─ wow:sync ──→ wow:ingest-combatlog ──→ archive (raw/ + metadata/, on D:)
   │                                            │
   │                                            └─→ LobbyReviewService::buildFromArchive()
   │                                                        │
   └─ browser upload ─→ ArenaReviewIngestService ──────────→ assemble()  ← one place
                              (arena_rounds)                      │
                                                                  ▼
                                                        arena_reviews (owned rows)
                                                                  │
                                                            /wow/game-review
```

`assemble()` is deliberately input-agnostic. Verified by replaying all six rounds of a real lobby
through the upload path and getting a result identical to the archive path.

**Reviews are database rows, not files.** They were briefly committed, which published other
people's names, talents and gear. `arena_reviews` and `arena_rounds` both carry `user_id`.

**The raw log is not retained on upload.** A round is derived on arrival into
`arena_rounds.payload` — metadata, throughput, every player's COMBATANT_INFO, and the moments — and
the text discarded. That payload is the only second chance: **anything not captured at derive time
cannot be recovered without the player re-uploading.** This is why moment detection had to go in
before real uploads started.

### The pieces

| Thing | Where |
|---|---|
| Split a log into rounds, derive metadata | `CombatLogIngestService` |
| Measure healing / absorbs / damage | `CombatantThroughputService` |
| Build, gear and the stat block | `ArenaLogService::extractCombatantInfo()` |
| Resolve a build to talent names | `ArenaLogService::resolveCombatantTalents()` |
| Find the moments in a round | `ArenaMomentService` |
| Assemble and store a review | `LobbyReviewService` |
| Browser upload | `ArenaReviewIngestService`, `ArenaUploadController` |
| The page | `App\Livewire\GameReview` |

Local loop: `php artisan wow:sync` then `tools/arena.bat`. `wow:forget-games --before=` removes
games and **records its cutoff** so a later bare sync cannot resurrect them — it did exactly that
once, rebuilding 196 deliberately deleted games minutes after they were deleted.

---

## What the combat log actually says

Everything here was measured against real 12.1.0.69933 logs, not assumed. Field counts are stable
per event type, so **every offset is read from the END of the line** — the advanced-logging block
in the middle has grown between patches and the suffix has not.

| Event | Fields | Offsets |
|---|---|---|
| `SPELL_HEAL`, `SPELL_PERIODIC_HEAL` | 36 | amount `-5`, overhealing `-3` |
| `SPELL_DAMAGE`, `SPELL_PERIODIC_DAMAGE` | 42 | amount `-11`, baseAmount `-10`, overkill `-9`, **critical `-4`** |
| `SWING_DAMAGE`, `SWING_DAMAGE_LANDED` | 38 | amount `-10` |
| `SPELL_ABSORBED` | **22 or 19** | shield caster `-10`, absorbed `-3` |

**The crit flag is at `-4`, not `-5`.** Spell damage lines carry a trailing `ST`/`AOE` tag that
shifts the documented suffix by one. Reading `-5` returns `absorbed`, which is almost always 0, so
a crit silently reads as "not a crit". This was got wrong once and only surfaced because a hit that
looked impossible turned out to be an ordinary critical strike.

**`amount` vs `baseAmount` on a crit.** For a critical strike `amount` is the crit and
`baseAmount` is the non-crit figure, so `amount > baseAmount` is normal and is **not** an
amplification effect. Their ratio is the crit multiplier.

**`SPELL_ABSORBED` has two lengths** — 22 when a spell was absorbed, 19 when a melee swing was,
because the swing form carries no spellId/name/school for the incoming hit. A fixed index for the
shield's caster reads the wrong field on 1,656 of 20,440 events in a single lobby.

**`SWING_DAMAGE` and `SWING_DAMAGE_LANDED` overlap.** 1,540 and 3,864 events in one lobby, 836 of
them the same hit reported twice. Sum both and melee is over-counted; take either alone and a
third or half is lost. De-duplicate on (timestamp, source, target, amount).

**Self-damage is not output.** A health-cost or redirected-damage ability otherwise counts toward
its own team and puts a damage peak on the wrong side of the scoreboard.

**Pets** are credited via `SPELL_SUMMON` (source is the owner). The advanced-param `ownerGUID` is
no help — on a pet's own damage event it reads all zeroes, because those params describe the
event's *target*. A pet already out before logging started cannot be attributed and is reported as
`unattributedPetDamage` rather than guessed onto somebody.

**`UNIT_DIED`'s trailing field is `unconsciousOnDeath`** — 1 for a feign, 0 for a real death.
Without that filter one Beast Mastery Hunter "dies" six times a lobby.

### COMBATANT_INFO

**The stat block is 22 fields on this client where the documented layout is 21.** One field in the
dodge/parry/block/crit/speed/lifesteal run is undocumented, so counting forward mislabels
everything after it — a naive read put `armor` on a versatility value. Labels are anchored from
both ends and the haste and versatility triples are *verified* (each must be three equal values);
if they do not match, the block is reported unaligned rather than shown wrong. Values are
**ratings**, not percentages.

**Gear** is `(itemId, ilvl, (enchants), (bonusIds), (gems))` per slot, two fields after the talent
bracket. Read the **median** ilvl — a shirt or tabard at ilvl 1 drags a mean down twenty points.

**The talent `entryId` is not `talent_node_entries.external_talent_id`** — two genuinely different
Blizzard id spaces, 0 of 17 matched. `nodeId` does match `external_node_id`.
`resolveCombatantTalents()` already reconciles this with a median-offset method verified 12/12
across 114 archived matches; **do not reinvent a node-only workaround**, it is cruder.

---

## Moments: what happened in a round

`ArenaMomentService`. **Moments are found from the cooldowns, not from a clock.** An earlier pass
looked for a health collapse inside a fixed window and needed a threshold nobody could defend — at
35% it fired every twelve seconds on ordinary churn, at 85% it was arbitrary the other way.

**Two cooldown bars, and the second one matters.** 45s+ is worth listing; **only 90s+ anchors a
moment**. Without the split a busy round is one moment: somebody presses something every few
seconds, the chain never breaks, and a real round came out as a single 44-second window holding 38
commitments. Avatar and Combustion anchor; Colossus Smash and a trinket ride along.

**The classification labels, it does not decide.** `data/arena-logs/spell-classification/*.json`
is a hand-reviewed list of 304 ids that says which way a cast points. It is **not** a list of what
matters — letting it decide put Penance, a nine-second rotational spell, in as a commitment. And
`mixed-cooldowns.json` (25 spells) means **both**, not defensive: reading the flag naively filed
Avatar under things a Warrior pressed to survive.

A moment carries: what each side committed, the pressured player and their health curve, control
that landed on a defending healer (**with who broke it, named**), and deaths.

---

## How to review a set of games

**Draft spec, 2026-09-28.** This is the method written down before it becomes a command. Once it
reads cleanly, the tools get built from it.

### What a review is for

A review should answer **one question about your own games whose answer changes how you play the
next game.** It is not a scoreboard. Damage and healing totals say who pressed buttons, not why a
game ended. A review finds the moment each game was decided and compares what was true at that
moment in wins against losses.

Your own games are the only outcome data this project has (rule 12). The game itself is knowable
from the spell data. What only your games can show is which of those facts decided a result.

Every review states its **question** first, then its **sample** (games, team, date, rating range),
then its findings. Anything the log cannot show gets named as a caveat, never filled in.

### The review table is the first product

A review's first output is **one table, one row per game** (`killread.php` prints it last, as
markdown): result, both MMRs, **every enemy player's experience** (Gladiator seasons, or their
best rank if they have none, and their highest 3v3 rating), our goes and how many were followed by
a kill, **defensives each side spent before the first death**, who died first and to what, and
whether our healer was locked out at that moment. The table shows *which* games to look into.
Everything else in this section (a go-by-go chain, the event list, the defensives each side still
had) is how you look into one.

### What to record for every game

Before any analysis, one row per game. Without these rows no finding can be grouped.

| Field | Source |
|---|---|
| result, duration | metadata |
| our team MMR, their team MMR | `ARENA_MATCH_END` fields 3 and 4. Ours is `3 + myTeam`, and theirs is the other one. These are MMR, not CR (Chriso, 2026-09-28). |
| MMR difference | theirs minus ours |
| enemy comp | specs from `COMBATANT_INFO`, healer first |
| every player's experience | Blizzard profile API; see below |
| who died, who got the killing blow and with what | `UNIT_DIED` (with `unconsciousOnDeath = 0`), and the last damage event on them |
| rematch id | the same enemy roster twice in one session |

### Experience: why MMR is not enough

**Early in a season MMR is deflated.** At the time of writing, Rank 1 is about 2600 and usually
ends the season near 3000. So a 2100 game now may be closer to a 2500 game at the end of the
season, but nothing in the log shows how much closer. What does not move with the season is the
players' **experience**, so every review records it for all six players.

The log records each player as `Name-Realm-Region`. That is enough to look them up in Blizzard's
profile API with the site's app token, whether or not they have ever used the site. The parsers
already exist in `BattlenetCharacterSyncService` (the character sync on `/characters`), and they
are public and take no database rows:

| Field | Source | Scope |
|---|---|---|
| **Highest 3v3 rating ever** ("exp") | `parseStatistics()`: achievement statistic 595 | **this character only** |
| **Gladiator seasons**, and Rank 1 seasons | `parseArenaTitles()` | **the whole Battle.net account**: Blizzard shares season titles across an account |
| Legend seasons (Solo Shuffle) | `parseArenaTitles()` | account |
| best season rank title | `parseRankTitle()` | account |
| current 3v3 rating | `/pvp-bracket/3v3` | this character, **as of today** |

What to keep in mind when reading these:

- **Exp and Gladiator count can disagree, legitimately.** A player on an alt shows low exp next
  to many Gladiator seasons (one opponent: exp 2164, 8 Gladiator seasons). **Gladiator seasons is
  the stronger signal**, and it is what Chriso asked for.
- **The lookup reads the character as it is now**, not as it was during the game. A few days
  apart that changes little. Months apart, it matters.
- **Realm slugs.** `Jubei'Thos` → `jubeithos`: split CamelCase at word boundaries, then drop the
  apostrophe *and* the hyphen it leaves behind.
- **Some characters have no public profile** (renamed, transferred, or inactive): 2 of 35 on
  26 Sep. Count them as unknown, never as zero.

### How to group games

- **By enemy comp.** Group by healer spec, then by the DPS pair. Most groups will hold one or two
  games at first, so read them as examples until they fill up.
- **By enemy experience.** Gladiator seasons summed across the team, and the team's highest exp.
  Early in a season this is a better measure of how strong a team is than its MMR.
- **By MMR band.** Use both the absolute MMR and the difference. A team that is climbing plays
  harder opponents as it goes, so wins and losses from different bands are not a fair comparison.
- **Rematches first.** When you play the same roster twice, the enemy is held constant, so a
  rematch is the cleanest comparison available. Where a win and a loss exist against one roster,
  start there.

### Categories come first

**Every measure below is the output of a filter.** A go, a defensive and a utility do not exist
in the log. They exist only once each spell is put into a category, so how complete the
categories are sets how good the review can be. Every cast on a 45s+ cooldown is exactly one of:

| Category | Source | Role |
|---|---|---|
| **offensive** / **mixed** | the hand-reviewed labels in `spell-classification/` | **anchors** a go: no offensive cooldown, no go |
| **control** | has a `dr_category` | **part of the go's chain** in the attacker's hands; an answer in the defender's |
| **defensive** | the same labels | what a go forces |
| **utility** | everything else | **not part of a go, but affects it.** In the attacker's hands it enables (Evangelism, Power Infusion, Nature's Swiftness into a CC); in the defender's it answers (Tremor Totem against a Fear, Nature's Swiftness into a big heal, Innervate) |

#### Why we identify goes

**A go is the unit of pressure in arena:** the moment a team commits to force the other team's
resources or get a kill. A log holds thousands of events. Goes reduce a game to the handful of
decisions that decided it, and every question a review asks is a question about them:

- **Did our goes force defensives,** and did that drain turn into a kill on a later go?
- **What did the enemy answer with:** which defensive, which utility, which CC?
- **Was the go executed well**, a good go or a bad one?
- **Did anyone bait,** spending the other side's defensives for nothing?
- **How did the same kind of go fare against more and less experienced teams?**

For a player pushing for Gladiator, that is the output: which of their goes work, against whom,
and why the ones that failed, failed. The same questions asked of the enemy's goes say what beat
them.

#### What a go is (Chriso, 2026-09-28)

**A go is a chain of events designed to force resources or get a kill.** In the log it is built
as a chain:

1. **The links are offensive cooldowns and CC that landed on the enemy.** CC means a stun,
   silence, disorient or incapacitate aura that actually applied, whatever the spell's cooldown,
   so Cyclone, Polymorph and Fear count as well as Leg Sweep.
2. **Links are joined from the chain's *end*.** A CC ends when its aura ends. An offensive cast
   counts as ending 5s after it is pressed. Measuring from the end is what lets a healer CC that
   lands long before the offensive still belong: each CC links to the last, and the last links to
   the cooldowns.
3. **Two link lengths: good goes and bad goes.**
   - A link within **5s** of the chain's end always joins. A go whose every link is this tight is
     a **good go**.
   - A link up to **10s** away still joins, but **only through an offensive cooldown**: the new
     link is one, or the chain's end was set by one. CC never links loosely to CC. A go that needed
     a loose link is a **bad go**: the right pieces, badly timed. Chriso's example: a Druid stuns
     the target, presses Incarnation 10s later, then Cyclones the healer 8s after that. The
     offensive cooldown is what connects it.
4. **A chain is a go only if it holds an offensive cooldown.** A CC chain with no offensive
   cooldown is not a go.
5. **CC links are labelled by who they landed on:** the enemy **healer**, the go's **target** (the
   enemy the attacking side damaged most inside the window), or **cross CC** (anyone else, inside
   the go's window). Cross CC is part of the go, not a peel.
6. **The go's window** runs from its first link to 15s after its last offensive cooldown (or to
   the end of its last CC, if later). Defensives the enemy uses inside it count as forced by the
   go, including the ones answering the opening CC.
7. **Utilities are not links.** They never start a go and never move its start. They are recorded
   against the go as enablers (attacker) or answers (defender).

**Bait** is the other half. A CC that draws a defensive but belongs to no go, followed by no
offensive cooldown until that defensive has expired, is **bait**: it spent their resource for
free. A CC that leads straight to a kill is a kill setup, not bait, and several CCs drawing the
same defensive are one bait.

**Why experience and MMR are needed alongside this.** How a player reacts cannot be read from
the go. Two goes can force the same defensives, one against a team that answers well and one
against a team that does not. Grouping by experience and MMR is what lets the review predict how
a team will react, which the go on its own never can.

`killread.php` implements exactly this. `--strict` builds goes from offensive casts only, for
comparison. For a game's detail (`killread.php 20:13`) each go prints as its chain, with CC marked
`*`, each CC labelled `> healer`, `> target` or `> cross`, and every gap written `-3.2s->`.

#### Definitions that were tried and are wrong

- **Anything on a 60s+ cooldown that is not defensive** (the first pass). This let a lone Tremor
  Totem or Leap of Faith count as a go, and produced a finding that did not survive.
- **Utilities inside the go.** Evangelism became part of the chain, moving the go's start. That
  came from a misread question: utilities *matter during* a go, but they are not a go.
- **Offensive cooldowns only, CC recorded beside it.** This missed the Leg Sweep that opens a go,
  and so missed the defensive the enemy spent answering it.
- **One 5s link length for everything.** A badly timed go split into two goes instead of reading
  as one bad go.
- **CC counted only up to 10s before the first offensive cooldown, measured from the CC's cast.**
  This cut off a chain that started earlier (19:47's go really started with a Sap 30s before the
  kill), and it could only see CC on a 45s+ cooldown, so it never saw Cyclone or Polymorph.

**Read the utility bucket as a list of missing labels, too.** Anything that falls into utility
because it was never labelled lands there by default. On 26 Sep that caught Summon Darkglare,
Invoke Xuen and Aspect of the Eagle (offensive), and Healing Tide Totem, Aura Mastery, Alter
Time, Vampiric Embrace and Nether Ward (defensive). **Racials are missing from the spell data
entirely:** 29 racial casts in the 15 games (Blood Fury, Shadowmeld, Gift of the Naaru, Quaking
Palm, Spatial Rift) and none reached the timeline.

### Go effectiveness: drain, then kill

**A go is effective when it forces defensives, and the kill usually comes on a later go.**
Measured per go as **how many of the defending team's defensives were already on cooldown when
it started** (cast earlier, and cast time + cooldown still ahead), against whether a kill landed
in the go's window or in the 30s after it. Counting defensives forced *within* one go does not
predict a kill. An opener that forces five defensives has done its job, and the kill comes on a
later go.

### The healer against our go

The defending healer's **effective healing plus the absorbs they cast**, inside our go's window.
Report it as per-second and as **a share of the damage we did to their team in the same window**,
because raw healing rises with the damage there is to heal. Also report the **HoT share**
(`SPELL_PERIODIC_HEAL`). Label the healer by spec *and class*: "Restoration" alone merges a Druid
with a Shaman.

### The kill read

Do this for **every real death**, in both directions: our kills in the games we won, and our
deaths in the games we lost. The loss read mirrors the win read: their go, our defensives, and
our healer's CC.

1. **The death.** `UNIT_DIED` with `unconsciousOnDeath = 0`.
2. **The go.** The killing side's go (see *What a go is*) whose window holds the death. Record
   how many seconds before the death it started and what it contained, including the CC. A death
   in no go's window is reported as such: 20:19's kill came off a CC chain with no offensive
   cooldown.
3. **Defensives spent (30s before).** Casts from the dying side's defensive list (including
   externals on the target), and how long before the death each came.
4. **Who did the damage (10s before).** Damage on the target, split by source, as each player's
   share. Record the killing blow's spell and amount, and the target's health just before it.
   **Report finishing blows separately.** Touch of Death on a target at 3–9% credits about 740k
   that is not pressure, and a dispel-triggered Dread Plague Erupt is a one-off burst of the same
   kind.
5. **The dying side's healer (10s before).** **Lockout** on them: `dr_category` Stun, Silence,
   Disorient or Incapacitate. Slow, Root, Knockback and Disarm leave a healer able to heal, and
   counting them made Chilled and Disable read as healer CC. Merge overlapping auras first, or a
   stun under a silence counts twice. Classify as *locked out at the death*, *lockout ended N
   seconds before*, or *none*.

Output: one row per death (game, who died, go start and contents, killing blow, healer state,
defensives spent). The 26 Sep table in `match-review-analysis.md` is the reference shape.

### Wins against losses: the current question

Chriso's read from playing: in the wins, our pressure overwhelmed them. In the losses, our
pressure did less, and they got CC onto our healer more easily and at better times. To test
that, measure these for every game:

- **How hard our go hit:** seconds from our go starting to their first major defensive, the
  number of their defensives spent per go, and whether each go ended in a kill or they recovered.
  Damage counts with finishing blows excluded.
- **When our healer was CC'd:** during *our* go (they stopped our pressure by locking the healer)
  or during *their* go (setting up a kill), and whether the healer's trinket and defensives were
  available at that moment.
- **CC on our DPS during our go:** how many seconds of our go the DPS spent unable to act.
- **The same three measures from their side,** so the comparison is symmetric.

A win should then read as: our go forced defensives quickly, and their healer was not the one
who ended our go. If the losses show our go being ended by CC on the healer, that confirms the
read. If they show our go running its full length without forcing defensives, the problem is
the pressure itself.

How these are measured in practice:

- **A go's window** runs from its first cast to 15s after its last. Each side's windows are merged
  before measuring, because consecutive goes overlap, and without merging one game read 395s
  of CC in 237s.
- **Lockout is a share of go time**, not seconds. Longer games have more of everything.
- **Separate volume from timing.** How much of the game a healer spent locked out and whether
  they were locked out *at the death* are different questions. On 26 Sep the first was the same
  in wins and losses, and the second was not.
- **Check each average without its most extreme game.** On 26 Sep one 44-second loss held 65–87%
  lockout figures and produced every "difference" in the CC averages on its own.

### Timing and overcommitment

- **Healer CC at our cooldowns:** was the attacking healer locked out from 1s before to 4s after
  the go's first offensive cast? This is timing, not volume: total lockout per go can be equal
  while the lockout lands at the one moment that matters.
- **Overcommitment:** defensives a side spent before the first death while the other side was
  **not** in a go. A defensive spent outside the enemy's go is spent against rotational damage or
  to break CC, and is not there when the real go comes.
- **Read the health beside every defensive.** A defensive pressed at 70% health, or to break a CC,
  is a different decision from one pressed at 30%. The event timeline shows both.

### Building one game's timeline

When a game needs looking into, list its events on **one clock**:

- **t = 0 is the `ARENA_MATCH_START` line.** Two dumps with different start points were once up to
  a second apart and nearly produced a wrong "was it before their cooldowns?" answer.
- **Every debuff the enemy put on us, not only lockout:** slows, roots, stings and marks are all
  `SPELL_AURA_APPLIED` with `DEBUFF`, paired with their `SPELL_AURA_REMOVED`. DoTs can be filtered
  by name. The lockout measures above deliberately ignore slows, so a peel by snare only shows
  here.
- **Pet-applied CC names the pet, not the owner** (Intimidation shows no caster), so filter by the
  owner's pets as well as the owner.
- **Positions exist only on casts.** A `SPELL_CAST_SUCCESS` line ends `…, posX, posY, uiMapID,
  facing, level`, so position is at `-5` and `-4` from the end, for the caster. A player who is not
  casting has no current position, so distance and kiting can only be estimated, and a
  Disengage or a charge is better evidence than a computed distance.
- **Hunter Feign Death writes `UNIT_DIED` with a trailing 1.** Filter on it (rule 12).

### What is useful, and what misleads

| Useful | Misleading |
|---|---|
| how long after the go started the kill came | total damage (inflated by finishing blows) |
| defensives spent before the death | damage taken against the round average (use the stretch just before the cast; see *Measuring a defensive honestly*) |
| healer CC state at the death | control counted by how short it ran (DR halves a repeat, so that reads as broken) |
| the killing blow, with finishing blows flagged | a single game's pattern stated as a rule |
| both team MMRs, enemy comp, enemy experience, rematches | |

### How the information is collected, today

**Not a command yet.** A review is produced in three layers, and only the first is finished:

1. **Committed, tested code produces the facts.** `php artisan wow:sync` (with
   `wow:ingest-combatlog`) splits your combat log into games in the archive.
   `ArenaMomentService::readTimeline()` reads every cooldown cast, every CC aura on a player,
   damage, health and deaths. `BattlenetCharacterSyncService`'s parsers read experience.
2. **Draft scripts turn the facts into the measures in this section.** They are in
   `tools/match-review/`, run by hand, in this order:

   | Script | Does | Run |
   |---|---|---|
   | `rosters.py` | every 3v3 game with Skylake + Hozzaarr: result, both MMRs, enemy specs → `games.json` | `python tools/match-review/rosters.py` |
   | `experience.php` | every player in `games.json` → exp, Gladiator/Legend seasons → `experience.json` | `php tools/match-review/experience.php` |
   | `killread.php` | goes (as chains, good and bad), drain, bait, utilities, healer sustain, CC timing, the kill read; per game and averaged over wins and losses. Pass `HH:MM` to print a game go by go; `--strict` for offensive-only goes | `php -d memory_limit=1G tools/match-review/killread.php 20:13 20:19` |

   `killread.php` reaches the private `readTimeline()` through reflection, so it measures from the
   same timeline the Moments section does, and it reads `experience.json` to group by opponent
   experience. The team (Skylake + Hozzaarr), the archive path, and a game-clock offset are
   hard-coded. **Their JSON output is gitignored**: it names other players, exactly like the review
   artifact that once got committed.
3. **Reading the output is AI analysis (or a person).** The scripts produce numbers. Deciding which
   numbers answer the question, which game is an outlier, what a rematch shows, and whether a
   finding survives a change of definition are judgements. They are written in `match-review-analysis.md` next to the numbers they rest on, so they can
   be checked.

**Raw output on the site.** `/wow/game-review/analysis` (auth-only, linked from Match Review)
shows the signed-in player's own tool output, read from
`storage/app/private/match-review/{user id}/*.txt` on the server. The output names every
opponent, so it is **uploaded, never committed or deployed**: save each tool's console output to a
`.txt` file and upload it with the base64-over-exec method in CLAUDE.md, then `chown` it to
`www-data`. Chriso is user 8 on production.

**To become a command**, the draft needs: the team and date range as arguments rather than
hard-coded; a written output (JSON, not console text) that the review page could read; the
experience lookup folded in; and tests against a fixture log, the way `ArenaMomentTest` does for
moments. The spec in this section is what it would implement.

---

## Measurement rules from earlier studies

Methods the first studies settled. Their results are in `match-review-analysis.md`.

- **Mixed cooldowns:** measure damage *done* during the buff against the caster's own rate for the
  rest of the round, and damage *taken* the same way. `mixed-cooldowns.json` is a label, not a
  behaviour; check it against this before treating a mixed spell as defensive.
- **A defensive's effect:** compare damage taken during it against the **same length of time
  immediately before the cast**, never the round average (you press them when focused, and the
  average includes quiet time). **Measure an external on the ally it was cast on**, but only on
  the same side: redirecting Touch of Karma, The Hunt or Ray of Frost measures the victim.
  Zone effects (Aura Mastery, Anti-Magic Zone, Darkness) have no target in the log and cannot be
  redirected.
- **CC broken by your own team:** between the control landing and ending, did the caster's own
  team put damage on the target? Never "ended before half its listed duration": DR halves a
  second Fear, so perfectly held control reads as a 50% break.
- **The answer pool:** per spec, what was spent when that player was under pressure, and how often
  it was their *first* answer (the reflex against the considered).

---

## Known defects and open items

### The review draft (`killread.php`)

- **Labels are incomplete**, and each gap moves a spell into the wrong category. The ones found
  are listed under *Categories come first*. Fixing them is the first thing that improves every
  number above.
- **A kill can come from no go.** 20:19's kill (Leg Sweep into Asphyxiate, then Death Coil) came
  from a CC chain with no offensive cooldown in it. Under the definition that is correct. It is
  worth watching whether CC-only kills are common.
- **The go's target is inferred** as the enemy the attacking side damaged most in the window.
  A go that switched target part-way labels the first target's CC as cross CC.
- **Pet CC has no caster:** Intimidation and Freezing Trap show as "by ?" when a pet applied them.
- **A utility is counted, not matched to what it answered.** That is the next step, above.

### Everything else

- **Zone-effect defensives measure only the caster.** Aura Mastery reads 0.07x.
- **Health reads `?` for a player who is not being hit** — HP is sampled from damage events only.
  Reading it from heal events too would fix it.
- **`Evangelism` (35 casts) and `Gladiator's Medallion` (66) have no `duration_seconds`**, so any
  window-based measure skips them. Correct for an instant dispel, a real gap for Evangelism.
- **nginx has no `client_max_body_size` for this site** (so 1MB) and PHP is at
  `upload_max_filesize=2M`. A round gzips to 123–923KB, so **rounds over about four minutes will
  413** on the browser upload. One line of nginx config fixes it; not done, not authorised.
- **3v3 does not group by roster** and must not — facing the same team twice in a session is
  ordinary and grouping would weld two games into one. Only round-based brackets group.
- **Most analysis scripts are throwaway.** The moment detector is committed and tested
  (`ArenaMomentService`, `ArenaMomentTest`). The review scripts (rosters, experience, kill read)
  are saved as drafts in `tools/match-review/`. The mixed-cooldown study, the CC-break study, the
  answer-pool study and the big-hit finder existed only in a session scratchpad and would need
  rebuilding. The major-cooldown report for 15 games is saved at `major-cooldowns-26sep.txt`.

## Where this could go

1. **Make the analyses real commands.** The major-cooldown table and the big-hit finder both earn
   their place next to the moments.
2. **Feed the answer pool into guide drafting.** It can fill a guide's Defensives section from
   measurement; the Sequence stays a person's job, per Part 5.
3. **Record observed-vs-curated corrections somewhere durable.** Avatar being classified mixed
   while being used purely offensively is a real correction to curated data and currently lives
   only in this file.
4. **Compare a played game to a guide's `Sequence`** — the original goal. The moments are the
   half that was missing.
