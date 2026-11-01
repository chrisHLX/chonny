# Plan overlay: a plan held against a played game

Status: **proposal, 2026-11-01.** Nothing on the site changes yet. The only code is two read-only
research tools, `tools/match-review/planread.php` and `cdaudit.php`. Correct this doc before anything
is built from it.

Read this for: comparing a player's plan with what happened in a game, showing each teammate their
own part of a shared plan, keeping cooldowns right as the game changes, and where AI earns its place.

## The short version

It can be done, mostly from data already stored. A plan on the site already records which spec
presses each step, and every stored round already holds each player's presses, where control landed,
health a second at a time and who died. A prototype read Chriso's own Jungle plan against 71 stored
Jungle rounds. It confirmed the 30-second cycle he wrote, and it found that a pattern he saw on video
in one game runs through all of them: damage cooldowns pressed ahead of the control lead it by a
median 5.1s.

Four things have to change for it to be trustworthy:

1. **Plans need a few structured fields.** A plan today is a list of buttons. The target, the trigger
   and whether a step is required or optional are all in prose or implied. AI's first job is to turn
   that into checks the player approves.
2. **Attempts must start from an event, not a time gap.** Counted by time gaps, the share of goes
   that "followed the plan" ran from 3% to 58% depending only on the gap chosen. Anchored on an event
   (their healer locked) it barely moved when the joining rule changed.
3. **Plans target roles, not named enemies.** Chriso's plan is a general blueprint already, and that
   is the right shape: 71 Jungle rounds met 59 different enemy teams.
4. **The data the overlay reads needs its own feedback loop.** 106 button and cooldown pairs were
   pressed but missing from stored kits, and a silence in Chriso's plan was invisible to every lockout
   measure. Both are fixed and the loop is designed. See "Keeping cooldowns right".

There is no "plan followed: 72%" score in this design. Each step says what happened and, when the log
can tell, why. That is the honest output, and it is also what makes AI useful here: the plan is what
the player meant to do, so the feedback answers their own question.

## What this adds to the site

| Page | Compares the player with | Answers |
|---|---|---|
| Improve | Other players at their level | Where do I stand on damage, defensives and control? |
| Moments | Nothing: a situation worth recognising | What happened that I should look at? |
| **Plan** | **What the team meant to do** | **Did we do what we said, and if not, why not?** |

This is the missing "reference" in the feedback loop in `VISION.md`: goal, sensor, comparison, action,
feedback. The site has a strong sensor and comparisons with other players. It has no record of the
player's own goal for a game. A plan is that record.

## What exists already

| Need | Already there | Gap |
|---|---|---|
| The plan | `UserGuide`, sections, blocks | No trigger, target or required flag |
| Who presses a step | `payload.source_spec_id` on every spell block | By spec, so a team with two of one spec is ambiguous |
| The team and enemy | `members()`, `enemies()`, `comp_key` | `scopeForComp()` covers listed guides only |
| Sharing | `viewers`, `friends_can_edit`, guild sharing | Fine as it is |
| What happened | `analysis.timeline`: casts, hp, control, defUp, offUp, kicks | Fine as it is |
| Whose cooldown is back | `CooldownLedgerService` (right for this build), `players[].kit` | The kit misses buttons outside the default build (Incarnation, Scatter Shot) |
| Who took which talent | `payload.combatants[guid]`, `CooldownLedgerService::takes()` | Fine as it is |
| Goes and pressure | `FallsService`, read when a page is drawn | Fine as it is |
| Which player is the viewer | `players[].logger` | Fine for a player's own upload |
| Pages reach the app | `wow:game-cards`, `/api/coach/page/{file}` | Fine: the app shows the website's pages |

`UserGuideBlock`'s docblock expected this growth: its payload is JSON so that "a target marker, a
timing offset, an opponent-spec condition" could be added without a column per field.

## Chriso's Jungle plan against 71 rounds

The plan is public (`/g/chriso/jungle-discipline-beast-mastery-feral`): five sections, no named enemy.
It was typed into a rules file by hand for this test. **The file's targets are assumptions:** the
opener and "Every 30s" were read as control on their healer, and Maim's kill target as whoever the
nearest Feral Frenzy went on.

| The plan says | The games show |
|---|---|
| **Opening CC chain:** Intimidation, Trap, Scream, Chimaeral Sting, Cyclone | First 3s+ lock on their healer in 69 of 71 rounds, median start 21.3s. It began with Intimidation in 48, Binding Shot in 10 and Sting in 5. In that stretch: Trap 52 rounds, Intimidation 48, Scream 22, Sting 6, Cyclone 1 |
| **Every 30s:** stun, Trap, Scream, Cyclone | 384 stretches, **one per 30s of play**. Median start to start 25s, middle half 13s to 34s. 40% were 22s to 40s apart |
| **30s Offensive CDs** | See the table below |
| **Big Go CDs:** Incarnation, PI, Ultimate Penitence, Bestial Wrath, Feral Frenzy, Tiger's Fury | 141 Incarnations. Within 6s before or 10s after: Tiger's Fury 72%, Feral Frenzy 60%, Bestial Wrath 60%. Their healer held at 26%, control within 3s at 12% |
| **CC on kill target:** Maim | 247 Maims, **191 (77%) on the nearest Feral Frenzy's target**, 17 on their healer |

The team plays the plan's cycle and its Maim. That is worth saying on the page: a plan review should
show what went to plan, not only what didn't.

**The 30s cooldowns against the stretches:**

| Button | Whose | Presses | Inside | Up to 10s before | Up to 10s after | Apart |
|---|---|---:|---:|---:|---:|---:|
| Bestial Wrath | Hunter | 296 | 39% | 25% | 17% | 20% |
| Feral Frenzy | Feral | 279 | 33% | 29% | 14% | 24% |
| Tiger's Fury | Feral | 335 | 23% | 28% | 19% | 30% |
| Mind Blast | Disc | 206 | 31% | 25% | 22% | 22% |

The 248 presses of the Hunter's and Feral's buttons that came ahead of the control led it by a
**median 5.1s**. That is the Feral Frenzy Chriso saw go out about 5s early on one video, now across 71
rounds. Both clocks run on about 30s, so an offset once there has no reason to correct itself (reasoned,
not measured). Chriso's intent settles how to read it: the damage should land while their healer is in control,
because otherwise it gets healed (see "What Chriso said"). So this is a finding about damage cooldowns.
Tiger's Fury can still be setup.

**The long buttons show why "not back" matters.** At those 141 Incarnations:

| Button | With it | Back, not with it | Not back or unknown | Pressed in |
|---|---:|---:|---:|---:|
| Power Infusion (120s) | 49 | 19 | 73 | 66 of 71 rounds |
| Ultimate Penitence (240s) | 6 | 89 | 46 | 40 of 71 rounds |

Power Infusion was with Incarnation at 49 of the 68 times it could have been. A plain count would have
said 35% and called that low. Ultimate Penitence was back and elsewhere at 89 Incarnations, and that is not a gap: Chriso calls it
situational, a long cooldown that can win a game by saving the team or by killing.

**The plan as an arena blueprint.** The model is setup, go, answer, reset
(`docs/arena/structure-summary.md`). The opener is setup into the first go. "Every 30s" with the 30s
cooldowns is the steady go, which mostly strips answers. "Big Go" is the kill attempt. "CC on kill
target" lands inside either. **The plan has no answer or reset section:** what the team does under
their go, and the pre-agreed "enemy presses X, you press Y" triggers the model asks for. Those are the
next things a player could add, and the `their_go` trigger is there for them.

These figures are from the re-measure at version 18. Counting Chimaeral Sting (below) took stretches
from one every 34s to one every 30s, and the median start to start from 30s to 25s. Across the 384
stretches, Feral Frenzy or Bestial Wrath was pressed in 43%, neither with one ready in 13%, and neither
was back in 45%. 370 of 575 presses (64%) came outside any stretch: 65% in wins, 62% in losses.

### What Chriso said, and what the games show (2026-11-01)

**"Every game starts with a team calling who to go after."** The kill target is a call, and the log
holds no calls. Goes carry a `target`, but nothing models the target of a whole game. It can be read
well from casts: in 64 of 67 rounds (95%) both DPS mostly hit one player, a DPS's casts went on their
own main target at a median 76%, and their first death was a DPS's main target in 36 of 37. The main
target changed after the first third in 14% of rounds, which reads as a swap. This shows who the team
went after, which isn't always who was called, and it can't say whether the call was right.

**"Ideally you want the damage to land while the healer is in CC. The damage will usually just get
healed if the healer is not in CC."** The games agree. Feral Frenzy on someone other than their
healer, by their healer at the press:

| Their healer | Presses | Lowest within 8s | Still down at 14s | Fell 30+ points |
|---|---:|---:|---:|---:|
| Held | 69 | -35 | -12 | 57% |
| Control landed within 5s | 83 | -29 | -8 | 49% |
| Free | 112 | -22 | -3 | 28% |

And every second of their DPS's health across these rounds:

| Their healer | Seconds | Lost a second | Gained back a second | Gained per point lost |
|---|---:|---:|---:|---:|
| Held | 4,819 | 3.12 | 0.97 | 0.31 |
| Free | 17,494 | 1.65 | 1.89 | 1.15 |

With their healer free, more health came back than went: 1.15 points for each point lost, against 0.31
held. A press with control landing within 5s sits between the two, so being early costs something and
still beats no control. 112 of 264 presses (42%) had their healer free with nothing landing soon.

This is why the plan read matters, and it is not a contradiction of "Wins and losses look the same":
that compared how often the team did it, and this measures what it does when they do. It is still
association. A held healer often comes with stacked cooldowns, and their defensives aren't separated
out here. It is an analytical result about the plan's principle, not proof that changing it wins.

**Ultimate Penitence: "situational."** It can win a game by saving the team or by killing, and its long
cooldown makes the decision hard. So it is a situational step, never counted as missing. By the moment
it was pressed: 6 with one of ours at 40% or under (2 rounds won), 12 with one of theirs at 60% or under
(11 won), 23 with neither (12 won). 31 rounds never pressed it (13 won). These counts mostly show who was
already winning, so they aren't a verdict on when to press it. The useful page shows what each press
did, and what the team had for the rest of the round.

**Who the chains land on.** The games answer this without asking: Freezing Trap and Intimidation go on
their healer (187 of 194 and 127 of 129). Psychic Scream splits evenly and Cyclone goes on someone else
two times in three, so those two are set per step, not by the section.

### Chimaeral Sting could not be read (fixed)

It showed in 0 first stretches. That was a data gap, not play: 86 presses in 54 rounds, 80 on their
healer, and no control recorded for it, ever. From 120 raw logs, the silence is its own aura: Spider
Venom (356727), landing a median 3.2s after the press (1.6s to 3.5s) and lasting 3s. The curated line
tags only the cast's id (356719). 51 of 80 casts in those logs reached the silence. After the fix: 98
presses, 85 spans.

The fix is `control-auras.json`, read by the log reader only: the aura counts as Silence and is
credited to Chimaeral Sting. A spell row was the first idea and was dropped, because Spider Venom has
its own name and would appear in Hunter kits and Comps as a second, pressable spell. A plan's chain
timer should also know this silence lands about 3s after the press, which changes where Sting belongs
in a chain. That is not built.

## What the test showed

The first test, which shaped the design, from before the fixes. Plan: the machine-drafted "Jungle vs Ret
/ Arms / Disc" (Feral, Beast Mastery, Discipline). Rounds: the 64 stored then, 34 won and 30 lost.

### It found what the video showed

The check was the 25 Oct 19:11 game, read against its recording on 26 Oct
(`match-review-analysis.md`). The tool was not told what to look for.

| Round time | What the overlay printed | What the video showed |
|---|---|---|
| 39.2s | Bestial Wrath pressed. Feral Frenzy 0.9s from back, Feral held by Cheap Shot, their healer free | Not reviewed |
| 53.7s to 55.3s | Tiger's Fury, Feral Frenzy. Their healer free, control lands 6.7s after the first press | Feral Frenzy went out about 5s before the control |
| 60.4s to 67.8s | Binding Shot, Psychic Scream and Freezing Trap on their healer, overlapping by 2.1s and 5.6s: 7.4s of lockout | Three controls stacked together |
| 73.9s | Bestial Wrath. Their healer free, 6.1s after the lockout ended | Damage landed once their healer was free |

One game proves the reading works there. It is a lead until it holds on more games and other comps.

### A plan's steps are a menu more often than a sequence

"Between goes" had every step in 0 of 251 attempts, because it is a list of options. "Hold the
Warrior" had every step in 9 of 223. A check needs to know whether a section is an order, a set or a
choice of one. The plan's notes say so in prose: "Only one Cyclone can be out at once — so this and
the one in the healer chain are alternatives, not a sequence."

About half of this plan's 16 step notes hold a rule the log could check (an order, a target, a
choice). The rest explain why. Targets are mostly in section titles.

### Attempts cut by time are not a usable measure

The share of attempts at "The go" with every step:

| Gap between presses | Attempts | Every step | Median length | Longest |
|---|---|---|---|---|
| 5s | 580 | 17 (3%) | 0s | 12s |
| 10s | 396 | 40 (10%) | 4s | 29s |
| 15s | 271 | 64 (24%) | 9s | 109s |
| 25s | 134 | 78 (58%) | 36s | 208s |

The same games read as anything from 3% to 58%. This is the fault that ended the old go rule
(`match-review-operations.md`, "The go, revised").

### Attempts anchored on an event hold up

Each stretch our control held their healer for 3s or more became a window, and the question was what
Feral Frenzy and Bestial Wrath did in it. (That pairing is the prototype's choice, drawn from what
Chriso saw on video. The plan does not state it.)

| | Windows | A button pressed | None pressed, one was ready | None was ready |
|---|---:|---:|---:|---:|
| Won (34 rounds) | 185 | 81 (44%) | 18 (10%) | 86 (46%) |
| Lost (30 rounds) | 117 | 56 (48%) | 14 (12%) | 47 (40%) |
| All | 302 | 137 (45%) | 32 (11%) | 133 (44%) |

Share of windows with a button pressed, as each setting moves:

| Setting | Values | Result |
|---|---|---|
| Join control up to | 0.5s, 1s, 2s | 45%, 45%, 48% |
| Shortest window | 2s, 3s, 4s | 43%, 45%, 52% |
| Press counted this early | 0s, 1.5s, 3s | 40%, 45%, 53% |

The lead time matters, so it is a definition to agree with Chriso and then keep. It is a far smaller
effect than 3% to 58%.

Two readings for the player, as questions: in 44% of these windows neither button was back, so the
control and the damage were on different clocks. And 340 of 513 presses (66%) came outside any window.

### Wins and losses look the same on this check

68% of presses fell outside a window in wins and 64% in losses. **This does not show that lining up
on healer control wins these games**, and the overlay must not imply it. It is one team, one crude
rule and a plan the team did not write. It is why the design reports what happened, and why
validation is a separate step.

### Missing presses are rarely the issue

Across 396 attempts at the go, the buttons with a known cooldown were almost always pressed or not
back: Tiger's Fury was free and unpressed 5 times, Feral Frenzy 21 and Bestial Wrath 25. Press counts
would say this team plays the plan. The real question is timing against control, which only a
plan-shaped read can ask.

### Plans ask for buttons the team did not take

This is the simplest result and needs no judgement. From each player's stored talents across the 64
rounds: Typhoon was taken in none, Mighty Bash in 14 (Incapacitating Roar in the other 50, which the
plan calls useless in its matchup), Scatter Shot in 35. No button was ever pressed without its talent.
A plan check against the player's real talents can ship first.

It must read the talents (`combatants[guid]`, `CooldownLedgerService::takes()`). A kit can't answer
this: Scatter Shot is not in Beast Mastery's profile, so it is missing from every kit, including the
27 rounds it was pressed in. The prototype's "no kit" column has that limit, and an early draft of
this doc wrongly read it as "not taken".

### Where control really lands

The plan's "Control the healer" section against what happened:

| Spell | On their healer | On someone else |
|---|---:|---:|
| Freezing Trap | 187 | 7 |
| Intimidation | 127 | 2 |
| Psychic Scream | 130 | 128 |
| Binding Shot | 87 | 45 |
| Cyclone | 58 | 121 |
| Incapacitating Roar (not in the plan) | 16 | 60 |
| Mind Control (not in the plan) | 23 | 17 |

So a press is not the fact that matters. Freezing Trap has no target when pressed, and only
`timeline.control` shows who it caught. Off-plan control belongs on the page too, since it is often
what actually happened instead. 9% of the lockout on their healer overlapped other lockout (2,266s
summed, 2,056s distinct).

### Bad cooldown data produces false statements

Incarnation has no kit entry, because the kit is built from the default build's profile. With no
cooldown the first run said it was ready and unpressed 167 times. The prototype's fallback to the
spell data's base of 180s then said "on cooldown" 266 times. Both were wrong and both looked clean.

**The data was right; the prototype read the wrong layer.** `spells.cooldown_seconds` is the base
(`game-data.md`, "A spell's effective cooldown isn't one column"). `CooldownLedgerService::cooldown()`
applies the player's talents and returns 90s against that base of 180s in all 61 builds checked, which
matches the page's 90s and 137 pairs of real presses (shortest gap 89.9s, median 94.7s). Overlay
cooldowns must come from the ledger, with the player's own build. The 266 above is therefore not a
finding, and the tool's fallback remains in place only as a marked limit of the prototype.

### Plans have to work across enemy teams

53 different enemy teams in 64 rounds, the most common met 3 times, and 2 rounds against the machine
plan's named enemy. A plan tied to one enemy team would apply to 3% of these games. Chriso's plan names
none, which is the right default: a general blueprint for how the team plays any match, with
matchup-specific sections added on top.

## The design

### Plans that can be checked

A plan stays a page people write. A few optional fields make parts of it checkable, and anything
left as prose is shown as "not checked" and still counts as part of the plan.

**On a section,** a new nullable JSON column, `user_guide_sections.rule`:

```json
{
  "shape": "order",
  "trigger": { "type": "healer_locked", "min": 3 },
  "target": "their_healer",
  "repeat": "every_30s",
  "state": "Feral Frenzy and Bestial Wrath land while their healer is held"
}
```

| Field | Values for the first version |
|---|---|
| `shape` | `order` (in this order), `together` (any order, close together), `one_of` (a choice), `menu` (use as needed; never counted as missing) |
| `intent` | A check the player means, in plain terms: `damage_in_control` is the first, from Chriso's own rule |
| `trigger` | `opener`, `healer_locked`, `our_press` (a named step), `their_go` (a fall on our side, `FallsService`), `none` |
| `target` | `their_healer`, `their_kill_target`, `their_other_dps`, `our_player` (a slot), `self`, `any` |
| `repeat` | `once`, `every_30s`, `every_60s`, `big_go` (when a long cooldown is back) |
| `phase` | `setup`, `go`, `answer`, `reset`: the model's loop, so a page can show which parts a plan covers |

**On a block,** in the existing payload: `target` to override the section, `slot` (the roster position,
for teams with two of one spec), `optional` and `situational` (a decision, such as Ultimate Penitence:
shown with what it did, never as missing or "Was available").

**Roles instead of names** (agreed by Chriso, 2026-11-01). `their_healer` comes from `players[].healer`.
`their_kill_target` is **the team's call**, set at the start of a game and sometimes swapped. The log
holds no call, so it is read from where both DPS put their casts, for the round, with a change marked as a
swap. The player can correct it on the page, and that answer outranks the read. Maim landed on the
nearest Feral Frenzy's target 77% of the time, so the read is close to how the team plays. A plan is written for a team
and works against any enemy. An enemy-specific section is added with a condition such as "against a
Paladin", which keeps today's matchup guides useful.

**Old plans keep working.** With no rule, a plan still gets the talent check, presses and where
control landed.

### Which plan applies to a game

1. The player's chosen plan for that team (`comp_key`), set from the plan page.
2. Otherwise none. A plan is never silently applied to a game.

A later page lets the player switch the plan for a game. A plan edited after a game should not be
held against that game, because that tells them they ignored advice they did not have. So each saved
change makes a row in `user_guide_versions` (guide, hash, a JSON copy), and a game uses the version
live when it was played. The first version can skip this and label the page "against the plan as it
stands today".

### Reading a game against a plan

`PlanOverlayService::read(array $analysis, array $plan): array`, a read at page-draw time like
`FallsService`. Nothing new is measured, so no `RoundAnalysisService::VERSION` bump and no app has to
resend games.

1. Fill each step's owner: slot or spec to the round's player on our side.
2. Fill roles: their healer, kill target and other DPS.
3. Find attempts from each section's trigger. A section with no trigger uses its own first step as
   the anchor, and is shown as a list of presses with no attempt count.
4. For each step in each attempt, give it one state from the table below.
5. Add what happened outside the plan: control on their healer from other spells, and what the other
   side did to the attempt (kicks, control on the step's owner, defensives).

### What a step can say

| State | Page copy | Needs |
|---|---|---|
| Done | Pressed at 1:02 | The cast |
| Landed on the wrong role | Went on their Mage | `timeline.control` or the cast's target |
| Early or late | 5.1s before the control | The trigger |
| Not back | Back in 9.8s | The ledger's cooldown, when the audit trusts it |
| Held | Held by Cheap Shot | `timeline.control` |
| Dead | Already dead | `deaths` |
| Not taken | Not in this build | The player's talents, never the kit |
| Available | Was available | All the above clear |
| Situational | Pressed at 3:10, saved their Disc / Not used this round | A step the plan marks as a decision |
| Unknown | Cooldown not known | A missing cooldown, never a guess |

"Available" is never "missed". The log has no line of sight, no calls and no walls, so a free button
is a question for the player (rule from `.claude/skills/review-games/SKILL.md`: counts are questions).
There is no overall score, and no win probability anywhere (rule 33).

### Each player sees their part

The same rows drive every view, so two teammates can never be shown different facts. Only the order
and the framing change.

For the viewer:

1. **Your part:** the steps they own, and anything that was waiting on them.
2. **What you were set up by:** steps of a teammate's that theirs depended on.
3. **The team:** everything else, closed by default.

The viewer's slot comes from `players[].logger` in their own upload.

Here is the 25 Oct game at 55s to 74s in three views. The wording is illustrative, the times are the
log's.

| Viewer | Lead line |
|---|---|
| Feral | Your Feral Frenzy at 0:55 went out 5.1s before the first control on their healer. |
| Hunter | Your Trap at 1:02 landed on top of the Fear, overlapping it by 5.6s. Bestial Wrath came at 1:14, 6.1s after their healer was free. |
| Disc | Your Fear at 1:01 landed 1s after Binding Shot and 0.4s before the Trap. |

Each is the same seven seconds, and each player gets the thing they can change.

### Sharing with a teammate

This needs less than it first appears, because **one log holds all six players' casts.** A player's
own upload is enough to read every step for every teammate.

- **The plan** is shared with what exists: `viewers`, friends or a guild.
- **The first version shares nothing else.** Each player sees the overlay on their own upload of the
  game, in their own role. Two players on the same plan need no link between their accounts.
- **A teammate with no app** still appears in your view. They do not get a view of their own.
- **A later version joins the two copies** to show both players' notes on one game. `match_id` will
  not do it, because it hashes each client's own start time. `roster_key` is the zone and all six
  player GUIDs, so it should match within a short time window. That is untested: it needs two
  accounts' rounds, which are on production.
- **A second log is also a free check:** the two readings of one game should agree.

Other players' names already appear in a player's own game pages, so the first version adds no new
exposure. Wording stays "describe, do not blame": a teammate's row says what happened, and the page
does not rank players.

### Where it shows

**Built first, 2026-11-01: the Kill targets box on the Summary** (`KillTargetService`). Each team's targets by
the damage they took, who died, and its worst goes: the healer's control with its length, the target's
drop, the cooldowns. It holds no plan yet. It is where the plan arrives: Feral Frenzy outside control on
their healer, and wasted control such as a Fear spent outside a go, which then can't be used in one
without diminishing. Those two reads go on these rows, so a player is held to a plan or to the arena model
in one place.

- **Game card:** a **Plan** tab beside Moments, on the website and in the app.
- **Watch page:** plan steps marked on the timeline, so a row opens the video at that moment.
- **Improve:** one chosen check tracked across recent games (see "How we know it works").
- **Plan page:** "This plan in your games", with the talent check at the top.

### How pages get built

Nothing is calculated when a page opens, so this goes in `wow:game-cards`. Each game's plan view is
its own file, `plan-{lobby}.html`, with its own signature (the plan version plus the round). A plan
edit then redraws only games with that team, through the existing `BuildCoachPages` budget, without
touching the cards. It reaches the app through `/api/coach/page/{file}`, so it should need no app
release. A new website route follows rule 28.

## Keeping cooldowns right

WoW changes spells and talents constantly, so one fix won't hold. The overlay needs the same kind of
loop the spell data already has elsewhere.

### The loops that exist

Each follows one pattern: a tool finds problems and proposes, a person decides in a reviewed file,
and the fix is applied on every run.

| Question | Tool | Evidence | The decision lives in |
|---|---|---|---|
| Is a spell tagged right? | `tagaudit.php` | How it is pressed | Classification JSON, `reviewed.json` |
| Cast under an id we can't see? | `hiddencds.php` | Raw cast ids | `cast-aliases.json` |
| What does a character really have? | `wow:diff-spellbook` (also at import) | The addon's export | `spell_corrections`, overrides |
| Which spec has this spell? | `wow:diff-arena-spells` | Real casts | `baseline-spec-overrides.txt` |
| How long does control last? | `wow:find-cc-duration` | Aura windows | `cc-synergies-overrides.txt` |
| What empowers a spell? | `empowered.php` | Hit by hit | `empowered-casts/reviewed.json` |
| Has every spell got an icon? | `wow:apply-icon-manifest` | The manifest | `icon-name-overrides.txt` |
| **Is this cooldown right, and can we see it?** | **Nothing yet** | | |

That is the gap, and every press in the archive is evidence for it: a button can't be pressed sooner
than it comes back, and each round keeps that player's talents, so the check is per build. Arena-log
casts count as direct observation under this project's rules.

### The audit

`tools/match-review/cdaudit.php`, read-only, proposes only. On 753 stored rounds, 577 button and
cooldown pairs of 20s or longer:

| Check | Result | Reads as |
|---|---|---|
| 1. Pressed, not in the kit | **106 pairs, 6,675 presses, 44 pairs offensive** | Shadow Dance, Divine Toll, Essence Break, Incarnation, Scatter Shot, Avenging Wrath on Holy |
| 2a. Pressed early, fixed floor | 29 pairs, about 8 of them channel ticks under 4s | Chaos Nova 60s against 45.0s; Dark Pact 60s against 45.0s on three specs; Anti-Magic Shell 40s against 30s; Divine Protection 90s against 72.0s; Ret's Avenging Wrath 120s against 30.0s |
| 2b. Pressed early, spread | 79 pairs | Haste, resets, spending: Mind Blast, Tiger's Fury, Colossus Smash |
| 3. Never as fast as the cooldown | 6 pairs | Blessing of Sacrifice 60s, lowest gap 90s; Holy Bulwark and Sacred Weapon 45s, lowest over 100s |
| 4. Control that never shows as control | 17 pairs | Chimaeral Sting, Solar Beam; also roots and knockbacks |
| Agree, with 8+ gaps | **265 of 379 pairs (70%)** | Nothing to do |

After the fixes, on 836 rounds: check 1 fell to **69 pairs, 1,372 presses, 19 offensive**, buttons mostly
below the population's 25% use line. Check 2 is 28 fixed floors and 80 spread. Check 3 is 9. Check 4 is
13, with Chimaeral Sting gone. 268 of 385 pairs agree.

Limits: these rounds span patches and mostly one account's games. A row is a question, and agreement
only means no press contradicted the data. A cooldown can be pressed slowly and still be wrong.

### The kit gap was one code fix (done, 2026-11-01)

Check 1 was structural. `RoundAnalysisService::abilities()`, which writes `players[].kit`, walked only
the spec's profile, and a profile holds what the **admin-default build** took. Its sibling `kits()`
adds the player's own talents, but for defensives and control only. So every offensive cooldown
outside the default build was missing, for every spec, and new talents would have kept falling in.

`abilities()` now adds **what the population is seen pressing on that spec** (`norms.json`) when the
player has it, by the ledger or by pressing it in that round, with the ledger's cooldown. A build alone
isn't enough: it also holds what a talent replaced and passives. A simulation before the fix showed Berserk in 166 Feral kits with
0 presses, beside Sentinel, Flurry and Grimoire of Sacrifice, and Moments calls any button in a kit
ready. The other way round, a talent that isn't in the build's list can still be pressed: 92 of 93 Rets
pressed a Divine Toll theirs did not list, so a press counts as proof. After the fix Berserk is in no
kit, Incarnation was pressed in 186 of 191, and Shadow Dance in 101 of 102. Floors of 20s (offensive), 30s (answers) and 15s (control) keep rotation out: Barbed Shot, Haunt,
Soul Reaper. Because the check reads real games, a talent a patch changes sorts itself out at the next
`wow:population`. `wow:refresh-kits` rewrites stored rounds with no re-send and no re-measure.

### Where each finding goes

| Finding | Likely cause | Goes to |
|---|---|---|
| Button missing from kits | The kit's coverage | Code, once |
| Fixed floor, one player or build | A talent the ledger doesn't apply | `spell_relationships` import, then `scalar-corrections.txt` |
| Fixed floor, shared with another button | Cast on another spell's clock (Radiant Glory) | `cast-aliases.json` |
| Floor under 4s | Channel ticks or a press logged twice | Reviewed, then ignored |
| Spread, tied to spending or a proc | Dynamic reduction | `cooldown-scaling-notes.txt`; the page says the cooldown varies |
| Spread, haste | Hasted cooldown | Reviewed; the page gives a range |
| Not really a cooldown (Pyroblast) | A proc-gated cast | Reviewed; never shown as a cooldown |
| Control with no spans, same name, aura id untagged | Cast id tagged, aura id not | `cc-synergies-overrides.txt` |
| Control with no spans, aura under its own name | Spider Venom for Chimaeral Sting | `control-auras.json` |
| Control with no aura in the log (Solar Beam: none in 120 logs) | The log doesn't show it | Reviewed; marked as not readable |

### The loop

1. **After a patch or a big batch of games,** run `cdaudit.php` on rounds from the current build only.
   Presses from an older patch prove nothing about this one, so it needs a `--since` option.
2. **It writes proposals** to `storage/app/private/match-review/cd-proposals.json`, like `tagaudit.php`.
3. **A person decides** in `data/arena-logs/spell-classification/cooldowns-reviewed.json`: button,
   spec, build, verdict, why. A decided row stops being proposed until the patch changes, so the list
   only ever holds what is new.
4. **The fix goes** to the file in the table above. Where in-game truth is needed, `/mcexport` and
   `wow:diff-spellbook` already give it.
5. **Re-apply** as the tag audit does: bump the cache, then kits, then profiles (rules 17, 19, 31),
   then `wow:refresh-kits`.

On a patch this sits in the patch-day routine after `wow:patch-update`, with a line in the "You changed
/ Run" table in `match-review.md`. The server holds far more players' games, so the audit should also
run there and hand back the same proposals.

**The overlay's safety net.** A pair the audit has flagged and nobody has reviewed shows "Cooldown not
known" and never "Was available". A bad cooldown becomes a visible hole, which is the rule here:
"a confidently wrong number is worse than a visible hole."

## Where AI fits

This is a real use for AI because the hard part is meaning. The numbers stay with the tools.

| Job | What the model does | What keeps it honest |
|---|---|---|
| **Compile** a plan | Turns titles and notes into proposed rules | The player approves each one; spells checked against the kit; anything it can't express stays prose |
| **Draft** a plan | Proposes one from the team's own games | The player edits it. It is a start, never a verdict |
| **Tell the game** | Writes the lead lines from overlay rows, for the viewer's role | Every number comes from a row |
| **Find the pattern** | Picks the one check that repeats across games | The sample is stated; under 10 games is a lead |
| **Ask** | Asks what the log can't see: "Was the Trap meant to follow the Fear?" The answer goes in `arena_notes` | The player's answer outranks the reading |
| **Question the plan** | "This step was skipped in 18 of 20 windows and those went fine. Is the plan right?" | It proposes a change and the player decides |

The last two are what make this different from a report. The player's answer is information no log
holds, and a plan that reality keeps contradicting gets revised, which is the "Revise" step in
`VISION.md`.

The design in `docs/ai/ask-about-this-game.md` still holds: server only, queued job, credits, and its
rules (every number from a tool result, no win probabilities). This adds one tool, `get_plan_overlay`.
Its model and cost sections need refreshing when it is built. Two additions:

- **Send roles, not names.** "Your Feral" and "their healer" read better and remove other players'
  names from what goes to the model, which shrinks that doc's privacy question.
- **Proposes, then decided.** Compile follows rules 11 and 39: the machine proposes, a person decides.

Curated game facts still come from Chriso or real observation. The model reads the plan and the
measurements and does not add arena facts of its own.

## How we know it works

The three tests in `VISION.md`, each with something to measure.

**Is it right?** Chriso watches 5 games from at least 2 comps against the overlay and marks each row
right, wrong or can't tell. A teammate does the same for their own role. Suggested bar before anyone
else sees it: 9 in 10 rows right, with no wrong "available". A wrong "available" tells a player they
failed when they didn't.

**Does it change what the player does?** This is where plans are stronger than anything on the site,
because the check has a natural denominator. Pick one check, record a baseline, then show it. For
example "of the windows where Feral Frenzy was back, how many used it". The other checks are the
comparison: if only the focused one moves, the page probably moved it.

`plan_focus` (player, plan version, check, started, ended) records when a focus began. Without it no
before and after can be told apart.

**Does that improve results?** One team can't show this. Telling 50% from 60% wins takes about 400
games each way (16 × 0.25 ÷ 0.1²), and this test has 64. Use what happened after the window instead
(health lost, defensives drawn, `FallsService`), and pool across players once there are enough. Until
then, say the check moved and leave rating out of it.

## What the log cannot see

- Calls, intent and what a teammate was told.
- Line of sight and walls. Positions exist, walls do not.
- Whether leaving the plan was right. A deviation can be the best play in the game.
- Why a plan failed. "They stopped it" can be read from their kicks and control, "it was the wrong
  plan" cannot.

Asking the player covers some of this. The page says so where it matters.

## Risks

- **Few people write plans.** `monetisation-read.md` counts 3 people who have ever built a guide, and
  the feature is worthless without one. "Draft a plan from your games" may matter more than the rule
  editor.
- **Plan authoring gets heavier.** Rule fields must stay optional, and compile should fill them.
- **Cooldown data.** Every false "available" costs trust, and this test hit one straight away. Hence
  the audit and its reviewed file.
- **It reads as a score.** Any percentage on the page becomes the number people chase. Keep to
  counts, with a denominator, and one chosen check.
- **Blame between teammates.** A shared view can start arguments in a team. Keep the viewer's own part
  first and the team view closed.

## Build order

Each step is usable alone, and each is checked on video before the next.

1. **Fix what the plan can't see.** Done on 2026-11-01, not yet deployed: `abilities()`,
   `control-auras.json`, measure version 18. The prototype still has to read the ledger's cooldowns.
2. **The cooldown loop.** `cdaudit.php` gains `--since` and a proposals file, then the reviewed file
   and the first review pass.
3. **The talent check.** Plan page: "not in this build", per slot. No schema change.
4. **The read.** `PlanOverlayService`, tested against fixture rounds, with rules hand-written for
   Chriso's plan in a data file. It leads with the team's target and with `damage_in_control`: which
   damage cooldowns landed with their healer held, early, or free. Rows only, reviewed on video.
5. **The Plan tab,** visible to Chriso and one teammate, with each player's own part first.
6. **Rules in the builder.** The `rule` column, payload fields, and choosing a plan for a team.
7. **Compile,** then the player's approval step.
8. **Plan versions and one tracked check,** on Improve, with `plan_focus`.
9. **The AI layer:** lead lines, questions, drafting a plan from games.
10. **Joined games** between two accounts, once `roster_key` is confirmed.

Steps 1 to 5 add no AI and no new tables, and they answer the riskiest question: does a player agree
with what the page says about their game?

## Decisions for Chriso

Settled on 2026-11-01: plans name roles, and a general guide is the default. The kill target is the
team's call. Damage should land while their healer is in control. Ultimate Penitence is situational.

- **Teammate visibility.** Suggested: a teammate's steps are shown to anyone in the game, as the cards
  do today, and the wording stays descriptive.
- **Who sees it first.** Suggested: you and one teammate, until the "is it right" bar is met.
- **Shared games.** Checking `roster_key` on a game two accounts both uploaded needs a read of
  production.

## How to repeat the tests

```bash
# One game, step by step, from one role
php -d memory_limit=2G tools/match-review/planread.php 137 "2026-10-25 19:10" --as=Feral

# Every round with the team: attempts, why steps were missing, where control landed
php -d memory_limit=2G tools/match-review/planread.php 137 --link="The go=Control" --from="Feral Frenzy,Bestial Wrath"

# Attempts by time, at another gap
php -d memory_limit=2G tools/match-review/planread.php 137 --gap=25

# Attempts anchored on their healer being locked
php -d memory_limit=2G tools/match-review/planread.php 137 --windows=1 --from="Feral Frenzy,Bestial Wrath" --join=1 --min=3 --lead=1.5

# A plan from a rules file, read as a blueprint
php -d memory_limit=2G tools/match-review/planread.php --plan=jungle-plan.json --blueprint=1

# Cooldowns against real presses, about 8 minutes on a first run
php -d memory_limit=3G tools/match-review/cdaudit.php
```

Guide ids are from the local database. Run PHP through the PowerShell tool. A rules file is `title`,
`team` (Blizzard spec ids) and `sections`, each with `title`, `steps` as `[spell, spec id]`, and
optional `trigger`, `target` and `anchor`. It is a stand-in for the `rule` column.
