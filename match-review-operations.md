# Match Review — Operations

How to conduct a review of your own arena games: how a played game gets from WoW into something
you can read, what the log can and cannot be made to say, what a go is, how each measure is taken,
and the tools that take them. **Findings and their interpretation are in
`match-review-analysis.md`**, not here. This file is the method.

Written 2026-09-27, split from the findings 2026-09-28. `addon-upgrades.md` holds what Chriso
wants next from the capture side.
`docs/combat-log-ingest.md` (rule 12 in full) holds the Solo Shuffle log structure and is not
repeated here. **The whole pipeline as it stands, from the log to the desktop app's pages, with
the analysis versions and what to re-run after a change, is `match-review.md`.** Read that first.

---

## Where it stands: the review path (written 2026-09-27)

The game-review path below is unchanged. Everything built on it since (the per-round analysis
in `RoundAnalysisService`, versions 5 to 9, and the desktop app's pages) is mapped in
`match-review.md`. `/wow/game-review` is **auth-gated and scoped to the
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
| Measure each round (goes, deaths, the ledger, answer sheets, warrant) | `RoundAnalysisService`, `CooldownLedgerService` (see `match-review.md`) |
| The desktop app's pages | `GameCardService`, `ImprovementService`, `CompLibraryService`, via `wow:game-cards` |

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

**The Garrote aura is the bleed, not the silence** (found 2026-09-30). Control is read as an aura:
it starts when the aura lands and ends when it comes off. Spell 703 is curated as a 3-second
Silence, which is right for a palette, but the 703 aura in a log is the bleed: **541 auras in the
archive, median 18.0s, 91% more than a second past the curated 3.0**. The silence is its own aura,
1330 "Garrote - Silence", at 3.0s. Reading 703 as lockout showed a Priest locked out for 82 seconds
of a 111-second game (34 without it) and called a healer "locked out at the death" who was not.
`ArenaMomentService::AURA_IS_NOT_THE_CONTROL` skips it; the curated line stays. It was the only
spell of its kind in a scan of all 162 curated CC spells. The two next furthest from their curated
lengths are small and are recorded in `knowledge-gaps.md` (the Rake stun, Void Nova).

**Some presses are logged from the pet, not the player.** One Hunter's Master's Call appears only
as the pet's `SPELL_CAST_SUCCESS` (pressed off the pet bar), where other Hunters' appears as their
own. Counting the player's casts alone read 0 uses for a button pressed seven times. Count a pet's
cooldown presses under its owner, and one press can log once per unit it touches.

**A shapeshift that breaks a root or snare writes `SPELL_DISPEL`** with the form as the spell
(`Cat Form` removing `Crippling Poison`). That is how a deliberate shift is told from a wasted one.

### COMBATANT_INFO

**The stat block is 22 fields on this client where the documented layout is 21.** One field in the
dodge/parry/block/crit/speed/lifesteal run is undocumented, so counting forward mislabels
everything after it — a naive read put `armor` on a versatility value. Labels are anchored from
both ends and the haste and versatility triples are *verified* (each must be three equal values);
if they do not match, the block is reported unaligned rather than shown wrong. Values are
**ratings**, not percentages.

**Gear** is `(itemId, ilvl, (enchants), (bonusIds), (gems))` per slot, two fields after the talent
bracket. Read the **median** ilvl — a shirt or tabard at ilvl 1 drags a mean down twenty points.

**A Hunter's talent bracket can open `[,(` instead of `[(`**: a leading empty entry, on 108 of
3,598 archived lines, all Hunters (81 of 163 Beast Mastery, 27 of 69 Marksmanship). The extractor
required `[(` and returned nothing for those players, so they showed no talents, gear or stats.
Fixed 2026-09-30 in `extractCombatantInfoFromLog()`.

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
- **Before writing any finding about defensives, read them in the order set out in *Reading
  defensives: three questions, in order* (below).** Overcommitment and overlap counts are where
  that reading starts, never where it ends.

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

### Damage, go composition, overlap and interrupts

- **Damage by ability:** ours onto them, pets credited to owners, summed across the games.
  Touch of Death finishes are included; read them as finishes.
- **What killing goes had in common:** each of our goes is broken into its links (anchors, and
  CC labelled healer or DPS) plus three flags (healer CC in the chain, a good go, 2+ of their
  defensives already down). Compare the share of killing goes that had each against the share of
  the rest. A link in every go (Dark Transformation) is the go itself, not what decides it.
- **Overlapping defensives:** from `BUFF` auras on each player (`SPELL_AURA_APPLIED` /
  `SPELL_AURA_REMOVED`), matched by name against the game's defensive list: two different
  defensives on one player at once for 1s or more, before the first death.
- **Interrupts:** `SPELL_INTERRUPT` (the timeline does not read it). The interrupted spell is
  the second-to-last field. Count per side, on the healer, and inside the kicker's own go.
- **One level of play at a time:** `killread.php --only=19:26,19:47,...` restricts every measure
  to those games. A guide tagged with a level cites numbers from that level only.

### Peak burst

- **The peak:** inside each go, the 6s window (sliding, anchored on each damage event) with the
  most damage from the attacking side's DPS, pets credited to owners. Record its damage, its share
  of the go's DPS damage, when it starts relative to the go, and every ability that landed in it.
- **Joint peak:** both DPS did at least 25% of it. Tells whether the damage was stacked or one
  player's.
- **Aligned peak:** the defending healer was locked out for 2s+ of those 6 seconds, or kicked
  during them. This is the measure that separates goes that kill; a joint burst on its own does not.
- **Report the peak without Touch of Death** beside it: a 740k finish on a target at 3–9% is a
  finish, not burst.

### How one player plays their spec (`rotation.php`)

`php tools/match-review/rotation.php <character> [--only=...]` reads, for one player:

- **The talents and PvP talents the log recorded** in each game (COMBATANT_INFO through
  `ArenaLogService::resolveCombatantTalents()`), split into every game and some games.
- **Casts per minute** of arena, with each major cooldown's length.
- **Damage by ability**, the player's and their pets', onto enemy players.
- **Which major cooldowns go out together**, and the median gap between them.
- **Every cast from 3s before to 10s after each major cooldown**, in order, with offsets. This is
  the rotation evidence: count how often a pattern repeats ("Blinding Sleet 1.3s before Army, 5 of
  9") rather than describing one burst.

It also reads **the why**:

- **The resource each ability was pressed with.** Every `SPELL_CAST_SUCCESS` line carries the
  power the cast was paid from, read from the end: `powerType -9, current -8, max -7, cost -6`
  (then `posX, posY, uiMapID, facing, level`). Types: 3 energy, 5 runes, 6 runic power (logged
  x10), 12 chi. **A free cast reports the caster's main power (energy) whatever the spell costs**,
  so for a proc-free button only the "cost 0" share means anything.
- **The buffs up when each ability was pressed**, against the share of all the player's casts with
  that buff up, so a proc that drives a button stands out from one that is simply always on.
  **A buff counts only if it went up at least 0.1s before the cast**: the log writes the aura a
  cast creates on the line just before the cast itself, and without the gap every self-buff reads
  as 100%.
- **The build**, most common across the games, as a guide draft's `"builds"` entry (talents with
  `:rank` and `#node`).

`php tools/match-review/describe.php "Name" ...` prints what a talent or spell does, as the site
resolves it: the source for a why that rests on a talent. **It can pick the wrong copy:** for
Tranquilizing Shot it printed another talent's text (rule 3). Read the result before citing it.

`rotation.php` takes `--with=Name,Name` (teammates who must be in the game; `--with=` for any game
the player is in) and `--date=YYYY-MM-DD`. The default is still the 26 Sep team.

### One spec, side by side (`specread.php`)

`rotation.php` describes one player. To ask how one player differs from others of the same spec,
the same things have to be measured the same way for each and put in columns:

```
php -d memory_limit=1G tools/match-review/specread.php --spec=103 --since=2026-09-01 --with=Doubletapz
    --deaths=Crawlordx "--col=Crawlordx:Crawlordx" "--col=Rastic <2150:Rastic:0:2149"
    "--col=Rastic 2150+:Rastic:2150:9999" "--col=Others 2100+:*:2100:9999"
```

- **A column is `Label:Name[:minMMR:maxMMR[:W|L]]`.** `*` is every other player of the spec not
  named in another column. The MMR is the player's own team's, from `ARENA_MATCH_END`. `--with`
  restricts the first column's player to games with that teammate.
- **Everything is per minute alive**, from the match start to the player's death or the end. A
  player who dies early would otherwise read as doing less.
- **What it measures:** damage out (pets credited), its share on their healer and on the most-hit
  target; damage in; healing on self and on teammates; presses a minute; time in gaps over 2.5s
  between presses, less time locked out; time locked out; lockout this player landed on their
  healer and their DPS, by spell; interrupts; deaths; each offensive cooldown against a teammate's
  and against lockout on their healer in the next 8s; every 20s+ cooldown as uses a game, first
  press and median gap; presses of each ability; damage and healing by ability; own buffs' uptime;
  own debuffs on enemies (share of time at least one enemy has it, and the average count); the
  resource each press was made at.
- **Defensives carry the health they were pressed at** (median, and the share at 50% or lower).
  Health comes from damage and heal events on the player, so it is stale when nothing hits them.
- **`--deaths=Name`** prints each real death: health at 20s down to 1s before, damage in by
  source, every defensive and self-heal the player pressed with its health, what teammates put on
  them, control on them, lockout on their healer, and the enemy's offensive cooldowns.

What to watch for when reading it:

- **A comparison player in another comp is not the spec.** Where one well-sampled player and the
  pool of others disagree, the difference is that player's build or comp. Say which it is.
- **The pool of "others" is mostly opponents of the logging player**, so it loses more than it
  wins. It shows what higher-MMR players press, not what wins.
- **A button never pressed may not be talented.** Check the talents before calling it a gap. It
  may also be pressed from the pet (above).
- **Before calling a press wasted, look for what it did.** A third of one Feral's Cat Form presses
  broke a snare.
- **Base cooldowns only** (rule 34): "was it available" is not known, only "was it pressed".

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
   | `specread.php` | one spec, several players side by side, and one player's deaths (see *One spec, side by side*) | above |
   | `feralread.php` | Feral only: combo points, every proc's fate, Tiger's Fury, Incarnation windows cast by cast, damage per energy, damage inside goes. Two auras share the Incarnation name: 252071 is only the Prowl-in-combat flag | `--col` as in `specread.php`, `--windows=Name` |

   **Another team or day:** `killread.php --me=Crawlordx --mate=Doubletapz --date=2026-09-30`.
   `--me` is the player whose side is "us", `--mate` a teammate who must be in the game, `--date`
   one day on the printed clock (HH:MM repeats across days). `experience.php <games file>` adds
   the players in any file of the same shape to `experience.json` without asking again for ones
   it already holds; with no argument it still rebuilds from `games.json`.

   `killread.php` reaches the private `readTimeline()` through reflection, so it measures from the
   same timeline the Moments section does, and it reads `experience.json` to group by opponent
   experience. The archive path and a game-clock offset are hard-coded; the team defaults to
   Skylake + Hozzaarr. **Their JSON output is gitignored**: it names other players, exactly like the review
   artifact that once got committed.
3. **Reading the output is AI analysis (or a person).** The scripts produce numbers. Deciding which
   numbers answer the question, which game is an outlier, what a rematch shows, and whether a
   finding survives a change of definition are judgements. They are written in `match-review-analysis.md` next to the numbers they rest on, so they can
   be checked.

**Raw output on the site.** `/wow/game-review/analysis` (auth-only, linked from Match Review)
shows the signed-in player's own tool output, read from
`storage/app/private/match-review/{user id}/*.txt` on the server. The output names every
opponent, so it is **uploaded, never committed or deployed**: save each tool's console output to a
`.txt` file and upload it with the base64-over-exec method in `DEPLOY.md` ("Working from Claude Code"), then `chown` it to
`www-data`. Reviews belong to Chriso's account, `christian@mindcollector.com`, which is user 2 on
production (user 8 is a different account of his).

**To become a command**, the draft needs: the team and date range as arguments rather than
hard-coded; a written output (JSON, not console text) that the review page could read; the
experience lookup folded in; and tests against a fixture log, the way `ArenaMomentTest` does for
moments. The spec in this section is what it would implement.

---

## "Your analysis": the same read for any player (2026-09-29)

The read `match-review-analysis.md` holds for one team was produced by questions, scripts and
judgement. `/wow/match-analysis` produces it for any player from their own uploads:

| Step | Where | When |
|---|---|---|
| Measure each game | `RoundAnalysisService::analyse()`, stored in `arena_rounds.payload['analysis']` | at upload, inside `LobbyReviewService::deriveRound()`, **while the raw log is still in hand** (it is discarded straight after) |
| Look up every player's experience | `FetchPlayerExperience` job → `PlayerExperienceService`, cached 7 days per character | queued at upload; the page never waits on Blizzard |
| Combine the games | `MatchAnalysisService::build()`: review table, who you played, what differed (each comparison with its sample, "lead" under 10), a takeaway for the uploader's role | on page load, from stored payloads only |

- **`RoundAnalysisService` is the product definition of the measures.** `killread.php` stays the
  research tool; a definition that changes there reaches players only when it is carried into the
  service, with a test. The service was checked against `killread.php` on the 26 Sep games and
  matched on every figure (goes, kills after, drain, burst, lockout at death, defensives outside
  goes, overlaps, kicks, both MMRs).
- **"Us" is the logging player's side** (affiliation 1, then `reaction`), so it works for whoever
  uploads, with no names in the code.
- **Games read from your own combat log get it from `wow:sync`** (2026-09-30), which the log
  manager runs after every game. Until then `wow:sync` wrote only the review (`arena_reviews`), so
  a locally played game never reached `/wow/match-analysis`. It now feeds each round's
  `raw/{id}.log.gz` from the archive through `ingestRound()`, gives the round the archive's lobby
  id (so the upload page's assembler never builds a second copy of a shuffle), and looks up
  experience directly, because no queue worker runs locally. `--skip-analysis` turns it off.
- **A game uploaded before 2026-09-29 has no analysis** and cannot get one without being uploaded
  again: the raw log was not kept. `php artisan wow:upload-rounds {user} {files...}` feeds round logs
  through the browser-upload path for testing or backfill from an archive.
- **Not yet in it:** Solo Shuffle as its own read (rounds are analysed, but the page groups by day,
  bracket and team, and a shuffle's team changes every round); a model-written summary over the
  tables; a player's own rotation (`rotation.php`).

## Where the losses came from: the rules (2026-09-29)

`MatchAnalysisService::faults()`, shown on `/wow/match-analysis`. In each loss, every mistake the
log can pin on a button counts toward whoever pressed it, and what the other team brought counts
toward them. Weights are in `MatchAnalysisService::FAULT_WEIGHTS`; changing one changes every split.

| Item | Owner | Weight |
|---|---|---|
| Our healer locked out when a teammate died, Medallion still on its 120s cooldown | our healer | 2 |
| The same, Medallion available (unused, or used more than 120s before) | our healer | 1 |
| ~~A defensive stacked on one already up~~ | **removed 2026-10-03** | |
| A defensive spent while they were not in a go, before the first death, **unless its owner was locked out within 4s after** (`beforeLockout`, version 7) | whoever spent it | 1 |
| A go whose 6-second peak landed with their healer free (not locked 2s+, not kicked) | the team (burst timing) | 1 |
| Their team had 3+ more Gladiator seasons | them | 2 |
| Their MMR 50+ above ours | them | 1 |
| Our goes forced 2+ defensives each on average and none led to a kill | them | 1 |

What it needs from each game (RoundAnalysisService v3): who applied each overlapping defensive
(the `BUFF` aura's source), who spent each defensive and whether it was outside the enemy's goes,
and whose lockout was on their healer during each go and each burst.

**An estimate from rules, not a verdict.** It cannot see positioning, calls, or a mistake nobody
pressed a button for; a player who never pressed anything collects no share. The page says so,
shows every item in a ledger, and prints the weights.

**The overlap rule was removed on 2026-10-03, after it changed how Chriso played and lost a game.**
He held Pain Suppression because Barkskin was up, as the rule taught, then was locked out for nine
seconds while the Druid died (`match-review-analysis.md`, "The Pain Suppression that was held").
A second defensive is right or wrong by what is coming: the enemy go live and their crowd control
ready for the healer. Neither a count nor the target's health sees that. Overlaps are still stored
and counted as a description, never charged to anyone.

**What a loss now shows instead: the answer sheet** (`RoundAnalysisService` version 7,
`withAnswers()`). For our first death, from the start of the go that killed to the death:
- **Their go:** offensive cooldowns and crowd control on us, as two separate lists.
- **Each of our players' lockout in it:** the longest stretch, and the free moment just before it.
  That moment is the last chance to press something, and it is where the 3 Oct decision was.
- **Every button our team had:** defensives and the trinket, crowd control with a cooldown that
  takes a player out (a peel), and interrupts. Each is sorted into *ready and never pressed*,
  *pressed in their go*, or *on cooldown when it began*. Presses refused by crowd control, range
  or line of sight ("Can't do that while fleeing") are shown as tried.

Each player's buttons are built by `kits()`:
- the spec's matchup profile, plus their own talents and PvP talents (Roar of Sacrifice is a
  talent, not in the profile), plus anything they pressed that the data calls defensive or
  crowd control;
- a talent they did not take is dropped (`CooldownLedgerService::takes()`), so a Druid with
  Mighty Bash is never shown with Incapacitating Roar ready;
- cooldowns are the player's own, talent-resolved.

The sheet lists what was there and does not say what would have won. "Ready" is ordered most
direct first: the dying player's own defensives, the rest of the team's, peels, interrupts,
Medallions. Charges are modelled as in the ledger.

**Difficulty beside the result.** The card and the Improve page call a game *harder* when the
enemy's MMR was 50+ above yours or they had 3+ more Gladiator seasons, *easier* the other way, and
*even* otherwise (`GameCardService::difficultyOf()`, the loss rules' own thresholds). The Improve
page shows your record in each.

**Known flaw (2026-09-30): the Medallion rule ignores whether the press was needed.**
On the 26 Sep losses, 9 of the healer's 14 points came from presses made with a teammate 2–4
seconds from death. The overlap rule also counts Lichborne (no damage reduction) and Anti-Magic
Shell (magic only) as defensives to stack on. Read a flagged press with `warrant.php` (below)
before accepting it. The findings are in `match-review-analysis.md`, *Was the trinket warranted*.

### Was it warranted (`warrant.php`)

`php -d memory_limit=1G tools/match-review/warrant.php [--only=HH:MM,...]` measures the kill read
plus these, per game:

- **Each Medallion of ours:** every debuff that came off within 0.4s of it. The lockout it broke
  is not always the first match: at 20:13 a slow and a Freezing Trap came off together. It also
  records the CC's nominal time left (after DR), each teammate's health, and the incoming rate in
  the 3s before and in the saved window. Then what the trinketer pressed and healed in the window,
  and the next lockout on them.
- **Each of our overlaps:** the target's health when the second defensive went on, the incoming
  rate before and during, the physical share of the damage, and the lowest health during and 3s
  after.
- **Incoming = damage + `SPELL_ABSORBED`.** Without absorbs a shielded player looks safe.
- **Time to live** = current health ÷ incoming per second. Health (current and max) is read from
  damage and heal events whose advanced-info unit is the target.
- **At each of our deaths:** our healer's debuffs and when their Medallion was last used.
- **Every defensive (both sides), with its reasons and a verdict:** danger > cc > insure > focus >
  alone > none. The definitions are in `match-review-analysis.md`, *Why each defensive was
  pressed*, and at the top of the block in the script. How each is measured:
  - The **target** is the matching `SPELL_CAST_SUCCESS`'s destination, so Pain Suppression counts
    on the ally.
  - The **buff window** is its own `BUFF` aura; failing that, the spell's duration.
  - **"Ate a CC"** is `SPELL_MISSED` with miss type `IMMUNE` (field 12) on a spell whose
    `dr_category` is a lockout. Slows are excluded: Judgment of Justice is often missed IMMUNE
    and meant nothing.
  - **"Grants immunity"** comes from `ModuleSpellReferenceService::ccImmunityGrantedBy()`.
    Unmapped mechanic codes are dropped (Lichborne carries codes 1, 10 and 23 besides Fear).
  - **"Their cooldowns running"** means an offensive cast whose own `duration_seconds` (12s when
    null) covers the moment. Their go window alone is too wide, because it runs 15s past the last
    cast.
  - **"Alone"** is never given to the healer's own press.
  - **Rows print for our side only.** The per-player summary includes theirs.
- **Whether the reason was valid (ours only).** The verdict definitions are in
  `match-review-analysis.md`, *Was the reason valid*. How it is measured:
  - **Replay without it:** each covered enemy hit grows by `r/(1-r)`, using `DEFENSIVE_EFFECT`
    in the script, whose values come from the spells' `Modify Damage Taken%` effects. Absorbs come
    from `SPELL_ABSORBED` lines naming the defensive. For a Leech defensive (Lichborne), it is the
    `SPELL_HEAL` "Leech" lines above the player's Leech rate over the previous 10s.
  - **Health without it** = logged health minus the running total added back, from the press to
    3s after the buff ends. The healer's response to a lower bar is not modelled.
  - **The enemy's output and the target's share** are taken over the 3s before against the buff
    window. A swap is a share at least halved while total output held. A fall is total output
    under half.
  - **Their cooldowns' time left** is their offensive casts' own `duration_seconds` (12s when
    null) minus elapsed. At `TAIL` (3s) or less, a danger press is LATE.
  - **Cost** is whether the player fell to `DANGER_HP` or died before the button was back. It uses
    base cooldowns, so it overstates; it does not yet discriminate. `cdledger.php` (below) answers
    the cost question with talent-resolved cooldowns.

## Reading defensives: three questions, in order (2026-10-02)

**Why this section exists.** The same mistake has been made three times. A review counts
defensives (spent outside a go, stacked, Medallion gone at a death) and calls the count a fault.
Chriso then asks whether the press was needed, and it usually was: the teammate was seconds
from death. Then he asks what happened *next*, and that is where the game went. On 26 Sep the
faults split blamed the healer for Medallions pressed with the DK 2–4 seconds from death. On 1 Oct
the first draft said "hold the second Pain Suppression charge", and the data said the opposite. On
2 Oct the pooled read listed "dying with the Medallion available" as a habit, without asking
whether anything was on the player for a Medallion to break.

`warrant.php` already answered the second question. The reviews skipped it because nothing
made it a required step, and the stored per-game analysis (what `patternread.php`, `/wow/match-analysis`
and the planned "Ask about this game" read) does not carry its verdicts. A review that reads only
stored data cannot see them.

**Ask these three questions, in this order, and never stop after the first:**

1. **What was pressed, and when?** The counts: defensives per go, outside their goes, overlaps,
   Medallion at a death. This says where to look, nothing more.
2. **Was each press needed?** (`warrant.php`.) Time to live, what the Medallion broke, whether the
   damage came. A press with the target under about 5 seconds from death is not a mistake to
   fix. **A Medallion still up at a death means nothing unless a lockout was on that player at
   the time:** it breaks CC; it does not reduce damage.
3. **What did it leave for the next go?** (`cdledger.php`.) Even a needed press costs the
   team the button until it is back. The question is how many big answers the team holds when
   the next exchange starts, and whether the team chose to start that exchange.

The conclusion lives at step 3, and so does the advice: **don't start an exchange with two or more
big answers down; play for time until they are back.** That is Chriso's conclusion from the 1 Oct
review, and the pooled ledger supports it (`match-review-analysis.md`, *The cooldown ledger*).

### The cooldown ledger (`cdledger.php`)

`php -d memory_limit=2G tools/match-review/cdledger.php [--bracket=3v3|shuffle|all] [--cds] [--games]`

It reads stored per-game analysis only (no raw log), so it runs over every uploaded game.

**Every cooldown is resolved from the player's own talents**, not the spell table's base value
(rule 34). `payload.combatants` holds each player's `COMBATANT_INFO` talents and PvP talents.
`ArenaLogService::resolveCombatantTalents()` turns them into talent entries, and their
`spell_id`s, internal ones per rule 2, become the selection that
`ModuleSpellReferenceService::effectiveCooldown()` / `effectiveCharges()` read. That is the same
path the spell pages use for a saved build. `--cds` prints every resolved value against its base,
so a wrong one can be caught. The first run read Fortifying Brew as 90–120s, not the base 360s,
and Combustion as 60s, not 120s. **Check `--cds` after a patch.** A modifier the data cannot size
silently leaves the base value.

The spell is looked up by name, preferring the pressable copy (rule 3). The Medallion is fixed at
120s.

**What a side could press** is the spec's matchup-profile `answers` list (the default build), plus
every defensive the player actually pressed in that game. **Big answers** are those on a 90s or
longer cooldown. The Medallion is excluded from coverage, because it breaks CC rather than
reducing damage.

**The three reads:**

| Read | Question | How |
|---|---|---|
| Coverage at their go | How many of our answers were back when their go started, and did it kill? | each answer is ready unless pressed within its resolved cooldown, counting charges; split by **big answers down: none, one, two or more**, and again **inside bands of game time** (before 60s, 60–120s, after 120s), because dampening makes late goes likelier to kill and late goes also find more down |
| Going while down | Did their next go kill more often when we had last gone with big answers down? | at each of our goes, our big answers down; then whether their next go killed |
| The trade | Was each press fair on cadence? | for each defensive of ours pressed inside their go: when it is back, minus when the **first** of the offensives that drew it is back. A charge still left counts as back at once. **Fair** if back within 15s (`FAIR_SLACK`), otherwise **expensive**. Then: did their next go come before it was back, and did it kill? |

**Reading the trade.** Chriso's rule of thumb: a 60s Barkskin into a 60s Kingsbane and a 60s
Combustion is a fair trade, because all three come back together. A 180s Pain Suppression into
the same go is not: the next time those 60s cooldowns come round, Pain Suppression is not there.
The pooled ledger bears out the cadence half. An expensive press was still down at their next go
95% of the time, against 24% for a fair one. **The verdict on any single press does not predict
the next go's outcome** (it killed 39% after expensive presses, 32% after fair ones, in 3v3). What
predicts it is how many big answers are down in total. So judge one press with `warrant.php`, and
judge the team's state with the coverage read.

**Before the game.** The pre-game version of this is a cadence table: their offensives with
their cooldowns, and our answers grouped by cooldown. Answer the 60s cooldowns with 60–90s
answers. Keep the big ones for the go that also has CC on our healer or no peel. The Matchup Lab
(`CooldownGraphService`) already pairs each threat with the answers that cover it, from default
builds. It does not yet show cadence (which answer comes back in time for the threat's next use)
or count how many big answers a team can lose before a go kills. That count is the ledger's
finding: one.

**What it cannot see:**
- **A defensive's target is not stored**, so coverage is the team's, not the attacked player's.
  Pain Suppression on the DK and Pain Suppression on the Monk read the same.
- **Charges are modelled as independent**, each back one cooldown after its own press. In game a
  second charge starts recharging only after the first is back, so two charges spent close
  together come back later than modelled. Coverage is overstated, never understated.
- **Default-build answers a player did not take** count as available whenever they were not
  pressed. Any such answer understates "down".
- **Whether going later would have moved their go.** The ledger shows that exchanges started with
  two or more big answers down kill far more often. It cannot show that waiting would have
  delayed their go, since their timing is theirs. Holding a go keeps our own offensive
  cooldowns and positioning back for theirs, which is the reasoning, not a measurement.

## Making the tags better from play (`tagaudit.php`, 2026-10-04)

Every feature sees a button only through the spell data's tags. Three kinds of gap hide a button:
- **No tag at all.** Vanish, Alter Time, Mass Invisibility, Evangelism and Skull Bash (not even
  as an interrupt) were all untagged on 4 Oct. A death read listed "nothing pressed" while
  Vanish went out.
- **Tagged, but under the timeline's 45s floor** (`ArenaMomentService::MIN_COOLDOWN_SECONDS`).
  Feint is tagged defensive and was still missing from 49 deaths, because the goes, the kill
  read, the overlaps and the defensive counts only read cooldowns of 45s or more.
- **Tagged as the wrong thing.** Heals on a rotation (Power Word: Radiance, Wild Growth) are
  tagged defensive. Divine Hymn and Tranquility have no cooldown at all in the data.

**The audit reads each spell by how it is pressed.** For every press it records:
- whether the target was in danger: 35% or lower, or lost 25% in the 3s before. Read on the
  teammate it went on when it was cast on one, otherwise on the caster;
- whether the other team was in a go, or our own;
- whether the caster was locked out;
- the caster's damage done and damage taken in the 6s after, against the 6s before.

A press is *defensive in context* when its target was in danger, or the other team was in a go
and ours was not. It is *offensive in context* when our go was on and no one was in danger.

**It proposes and never applies.** A heuristic misreads some spells. Alter Time "looks
offensive" because Mages press it ahead of the enemy's burst rather than in danger. The
classification files stay hand-promoted (rule 11):
1. run the audit;
2. read the proposals;
3. edit `data/arena-logs/spell-classification/*.json` by hand;
4. re-measure with `wow:sync --skip-ingest --fresh`.

**Spells used both ways are read per press** (`RoundAnalysisService::classifyContextual()`,
version 8). Vanish, Mass Invisibility, Master's Call, Nether Ward, Evangelism and Tremor Totem are
listed in `data/arena-logs/spell-classification/contextual-cooldowns.json`. A press of one counts
as **defensive** when its presser was in danger, or the other team was in a go and theirs was not.
Otherwise it counts as **utility**, which is neither a defensive nor the start of a go. In the
answer sheet such a spell is one of the player's answers once they have pressed it in the round.

**Short defensives come in by a curated list, not a lower floor.**
`data/arena-logs/spell-classification/short-defensives.json` holds Feint, Crimson Vial, Spell
Reflection, Frenzied Regeneration and Fade. Each enters the timeline from a 15s cooldown
(`ArenaMomentService::DEFENSIVE_FLOOR`). A blanket 15s floor for every defensive-tagged spell
was tried first and reverted the same day. It brought in Blink and the Mage barriers, pressed
every time they are back, and one game's "defensives before the first death" went from 11 to 48.
With the list, that game reads 29: the extra presses are real Feints and Fades.

**What was promoted on 2026-10-04**, by judgement from the audit and the spells' own jobs:
- **Defensive:** Alter Time, Intervene, Leap of Faith.
- **Offensive:** Summon Darkglare, Summon Infernal, Summon Demonic Tyrant, Tip the Scales, Aspect of
  the Eagle.
- **Removed from defensive:** Power Word: Radiance, Prayer of Mending, Swiftmend, Wild Growth,
  Renewing Mist, Reversion, Unleash Life, Healing Stream Totem, Stormstream Totem, Mend Pet and
  Chi Torpedo. They are heals on a rotation, or mobility.
- **Left alone:** mobility spells, short rotational cooldowns, and spells whose job was unclear
  (Abyssal Gaze, Cauterizing Flame).
- **Still open:** Skull Bash is not flagged as an interrupt. That flag lives in the curated import
  files and needs `import:spelldata`.

The classification also feeds the spec kits and matchup profiles. After a change, bump the spell
cache version, then run `wow:precompute-spell-kits` and `wow:build-matchup-profiles` (rules 17,
19 and 31), then re-measure. Frost Mage's answers gained Alter Time this way, and Discipline's
gained Leap of Faith and lost Power Word: Radiance.

### Tagged, but cast under an id the timeline cannot see (`hiddencds.php`, 2026-10-08)

A fourth kind of gap: the spell is tagged, but the log casts it under a copy of the spell that has
no cooldown in the data, or that the data does not hold. The timeline takes a commitment only from
a cast whose own id has a 45s+ cooldown, so the press never enters a go, a defensive count or an
answer sheet. Chriso found the first: Shadow Priest and Ret goes showed Halo and Divine Toll, never
Avenging Wrath.

**Avenging Wrath was missing from 833 of 897 Ret presses.** With Radiant Glory, every Wake of Ashes
casts an 8-second Avenging Wrath under 454351, which has no cooldown in the data (every one of the
833 sat beside a Wake of Ashes cast). Wake of Ashes itself is on 30s, under the floor, so a
Radiant Glory Ret's go showed neither. Only the 64 classic presses (31884, 120s) were seen.

`tools/match-review/hiddencds.php` reads every archived round and sorts each use of a tagged
cooldown into: seen, cast but not seen (with the cast ids and their cooldown in the data), or
aura only (no cast of the name within 2s, so a talent or proc applied it). Casts of a name within
10s are one press, so channel ticks (Divine Hymn, Tranquility, Doom Winds) do not read as hidden
presses. Short rotational spells under the floor are left out; they are out on purpose.

**The fix is a curated list, not a name match.**
`data/arena-logs/spell-classification/cast-aliases.json` gives each hidden cast id the cooldown of
the copy that has one (`cooldownOf`), and that copy's tags when the cast id has none (`as`). A cast
of an alias within `ArenaMomentService::ALIAS_REPEAT` (12s) of the same player's commitment of the
same name is the same press (The Hunt's impact, Doom Winds' ticks). Matching every copy by name was
rejected: a name's copies include ticks and landings that are not presses. Added on 2026-10-08,
presses found over 823 rounds:
- Avenging Wrath (Radiant Glory, 454351), on Wake of Ashes' 30s: 833;
- Anti-Magic Shell (410358, not in the data): 348;
- The Hunt's impact (1246169): 310;
- Smoke Bomb (212182, 359053): 174;
- Havoc's Metamorphosis (200166; the cooldown sits on 191427): all 121;
- Ultimate Sacrifice (199448, not in the data): 109;
- Wailing Arrow (392060): 71;
- Doom Winds' storm (469270): 40.

**Left out on purpose:** Heroic Leap. The log writes only its landing (52174, 345 leaps), which is
tagged offensive; as a commitment every leap would start a Warrior go.

**Aura only, not added:** procs and talents that apply a cooldown's aura with no press (Havoc's
Metamorphosis from Eye Beam under Demonic, Resto Shaman's Ascendance from Deeply Rooted Elements,
Shadow's Halo, Lightsmith's armaments). They are windows, not decisions; the press that caused
them is the commitment where it is one.

**Promoted from the tag audit the same day:** Aura Mastery (defensive), Breath of Eons and
Predator's Wake (Devourer's version of The Hunt) as offensive. Thunder Focus Tea, Prescience,
Roll the Bones, Flare and Time Spiral were decided as untagged (`reviewed.json`).

After either kind of change: `wow:sync --skip-ingest --fresh` (it is a measure change:
`RoundAnalysisService::VERSION` 11), then `wow:population --fresh` and `wow:go-cooldowns`, so the
norms and the comp page's "How to play it" see the presses too.

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
- **"Lockout ended Ns before" used to name the first lockout in the last 10 seconds, not the
  latest** (fixed 2026-09-30). It read 8s at a death where another lockout had ended 1.4s before.
- **Some labels still name the 26 Sep team:** "games lost without 19:47" and "DK and Monk" print
  for any team. The numbers under them are right.
- **A utility is counted, not matched to what it answered.** That is the next step, above.

### Everything else

- **Analyses stored before RoundAnalysisService v4 overstate lockout in any game against a Rogue
  who pressed Garrote** (2026-09-30). `/wow/match-analysis` reads stored payloads, so those games
  keep the old figures, and the loss split's "healer locked out at a death" item with them, until
  they are derived again. For a game read from your own log that is
  `php artisan wow:sync --fresh --skip-ingest`; an uploaded game has to be uploaded again.
- **Since version 9 (2026-10-04) the stored analysis also says whether each defensive was
  needed** (`RoundAnalysisService::warrant()`): whom it went on, health, time to live, the reasons
  and `needed`. The loss rules no longer charge a needed press, and the Improve page reports the
  share. The replay (what the defensive removed) is still `warrant.php`'s alone. The paragraph
  below is what was true before.
- **The stored per-game analysis carries the ledger's coverage, but not the warrant verdicts**
  (2026-10-02; coverage added 2026-10-03). Since `RoundAnalysisService` version 6, every go
  stores `cover`: the defending side's damage defensives back as it started, with cooldowns
  resolved from each player's own talents by `CooldownLedgerService`, the same class
  `cdledger.php` now reads through. Version 6 also stores `dispels` and each player's `debuffs`
  from the other side, which the desktop app's Improve page measures against
  (`ImprovementService`). The warrant read (question 2) still needs health and time to live,
  which only the raw log has. It is not stored, so anything reading only stored data can say what a
  defensive left for the next go but not whether it was needed.
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
