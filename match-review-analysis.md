# Match Review — Analysis

What has been found from reading our own arena games: measurements, and what they are taken to
mean. **How** each measure is taken, what a go is, and the tools that produce the numbers are in
`match-review-operations.md`. This file holds only results and their interpretation.

Every number here is from one player's own games. A finding is a description of those games and
a starting point, not a rule about the game. Where a finding depends on a definition that later
changed, that is said beside it.

---

### Mixed cooldowns are mostly not mixed

Damage done during the buff against the caster's own rate for the rest of the round:

| | casts | dmg done | dmg taken |
|---|---|---|---|
| Ultimate Penitence | 15 | 6.07x | 0.00x |
| Bestial Wrath | 5 | 1.80x | 1.12x |
| Eye Beam | 24 | 1.42x | 1.09x |
| **Avatar** | **27** | **1.31x** | **1.12x** |
| Metamorphosis | 6 | 1.17x | 2.08x |

Of the 25 spells `mixed-cooldowns.json` calls both, **only Ultimate Penitence behaves that way**.
Avatar and Metamorphosis are pressed *into* more damage, not to avoid it. Treating them as a
defensive trade is wrong.

### Measuring a defensive honestly

Comparing damage taken during a buff against the **round average** biases every defensive towards
looking useless — you press them when you are being focused and the average includes quiet time.
The control is damage taken in the **same length of time immediately before the cast**.

**An external must be measured on the ally it was cast on, not the caster.** Pain Suppression on
the Priest's own damage taken was n=2 noise; redirected to the recipient it is **n=29, +63%**.
But **same side only** — Touch of Karma, The Hunt and Ray of Frost target an enemy, and
redirecting there measured the victim instead, turning Touch of Karma from 1.48x into 0.28x.

Zone effects (Aura Mastery, Anti-Magic Zone, Darkness) are still wrong: they protect a team and
the log gives no target to redirect to.

### Crowd control broken by your own damage

DR-immune measure: between the control landing and ending, did the caster's **own team** put
damage on the target? (Counting "ended before half its listed duration" is wrong — DR halves a
second Fear, so perfectly held control reads as a 50% break.)

| | control cast | broken by own team |
|---|---|---|
| A coordinated 3v3 team | 50 | **30%** |
| An LFG 3v3 team | 53 | **53%** |
| Solo Shuffle, both sides | 113 | **70% / 70%** |

That reads as a coordination scale. The named culprits are the actionable part — a Fire Mage's
Ignite and a Disc Priest's Shadow Word: Pain breaking their own team's fears and polymorphs.

### The answer pool, observed

Per spec, what was actually spent when that player was under pressure, and **how often it was
their first answer**. That `1st` column separates the reflex from the considered:

```
Arms Warrior        Berserker Shout   4 uses, 1st 4/4, 2.4s in    ← automatic, gone immediately
Unholy DK           Anti-Magic Zone   5 uses, 1st 4/5, 1.3s in    ← reflex
Holy Paladin        Divine Protection 6 uses, 1st 0/6, 7.3s in    ← still up at seven seconds
```

`arena-structure.md` Part 2 says a guide should be organised around exactly this. The Matchup Lab
models what a spec *could* press; this is what they *do*.

**What observation cannot supply is the ideal go.** Part 5: "openers are engineered between games,
not looked up." Averaging an uncoordinated team's games would codify its mistakes as doctrine.

### 26 Sep: why the wins were won and the losses lost, 15 games of 3v3

Disc Priest (Skylake), Unholy DK (Hozzaarr), Windwalker Monk (Captnmurphy). 9 won, 6 lost.
Times are the in-game clock. Produced with the three scripts in `tools/match-review/`;
experience read 2026-09-28.

**Our team's experience:** Disc Priest 1x Glad 2243 · Unholy DK 4x Glad 2526 · Windwalker 6x Glad
2673 (11 Gladiator seasons).

**Corrected 2026-09-30: every lockout figure for 19:47 was too high.** That game was against an
Assassination Rogue, and the timeline read the Garrote *bleed* as a silence (the method is in
`match-review-operations.md`, "What the combat log actually says"). With the bleed left out:

| 19:47 | As first written | Corrected |
|---|---|---|
| Our healer at the Monk's death | locked out at the death | last lockout (a Cyclone) ended **8s before**; he was never Garrote-silenced in this game |
| Our healer locked out, share of our go | 75% | 43% |
| Our DPS locked out, share of our go | 82% | 25% |
| Our healer locked out, whole game | | 17s of 44s |

What that changes below, each marked where it stands:
- **Our healer locked out at our death: 3 of 6 losses, not 4** (19:54, 20:13, 20:30), with 20:22
  ending the same second as before.
- **19:47 is no longer "the rule's one clean case"** of a Medallion held through a silence while a
  teammate died. The Priest was bleeding, not silenced, when the Monk died.
- **The loss split's Priest share is a point too high** (the 19:47 "Medallion available" item).
  The stored analyses behind that split were measured before the fix.
- 19:47 was called the outlier that "produced every difference in the CC averages on its own".
  Most of that outlier was the bleed. Losses now average 21.5% healer lockout in our goes against
  16.8% in wins, with 19:47 included.

No other 26 Sep game had a Rogue in it, so nothing else moves.

#### The review table

| Game | Result | MMR (us / them) | Enemy team (healer first; Gladiator seasons, or best rank if none; highest 3v3) | Our goes (followed by a kill) | Defensives spent before the first death (us / them) | First death | Our healer at that death |
|---|---|---|---|---|---|---|---|
| 19:26 | W | 1930 / 1924 | Mistweaver 5x Glad 2129 · Elemental 1x Glad 2092 · Arms 2x Glad 1956 | 2 (2) | 2 / 6 | theirs: Dianpaoer (Elemental) to Touch of Death 748,186 | - |
| 19:29 | W | 1961 / 1986 | Preservation 3x Glad 2461 · Fire Mage 1x Glad 1962 · Windwalker Duelist 2369 | 3 (2) | 9 / 12 | theirs: Ffz (Fire) to Strike of the Windlord 48,583 | - |
| 19:35 | W | 1999 / 2047 | Holy Paladin Elite 2216 · Affliction Legend (Shuffle) 2067 · Frost Mage Duelist 1997 | 4 (2) | 10 / 11 | theirs: Squivv (Affliction) to Melee 646 | - |
| 19:43 | W | 2041 / 1968 | Holy Paladin Elite 2216 · Affliction Legend (Shuffle) 2067 · Frost Mage Duelist 1997 | 3 (1) | 9 / 13 | theirs: Squivv (Affliction) to Penance 14,473 | - |
| 19:47 | L | 2061 / 2098 | Resto Druid 4x Glad 2535 · Affliction 6x Glad 2594 · Assassination 4x Glad 2140 | 1 (0) | 2 / 4 | ours: Captnmurphy (Windwalker) to Sudden Demise 76,336 | ~~LOCKED OUT at the death~~ lockout ended 8s before (corrected 2026-09-30) |
| 19:51 | W | 2036 / 2049 | Disc Priest 3x Glad 2248 · Ret 1x Glad 2570 · Havoc 1x Glad 2447 | 1 (1) | 3 / 3 | theirs: Biiggwhammy (Retribution) to Dread Plague (Erupt) 538,666 | - |
| 19:54 | L | 2071 / 2183 | Mistweaver (no profile) · Shadow Priest 5x Glad 2418 · Survival 5x Glad 2555 | 3 (0) | 7 / 3 | ours: Hozzaarr (Unholy) to Shadowy Apparition 6,299 | LOCKED OUT at the death |
| 19:57 | W | 2057 / 2014 | Disc Priest 3x Glad 2248 · Ret 1x Glad 2570 · Havoc 1x Glad 2447 | 5 (2) | 12 / 21 | theirs: Biiggwhammy (Retribution) to Touch of Death 726,364 | - |
| 20:02 | W | 2081 / 2109 | Resto Shaman Elite 1888 · Unholy DK 3x Glad 2580 · BM Hunter 2x Glad 2454 | 2 (2) | 6 / 8 | theirs: Teriyke (Restoration) to Touch of Death 749,187 | - |
| 20:08 | W | 2119 / 2179 | Disc Priest (no profile) · BM Hunter 3x Glad 2555 · Feral 8x Glad 2853 | 4 (3) | 8 / 6 | theirs: Meowzetzan (Feral) to Touch of Death 735,701 | - |
| 20:13 | L | 2164 / 2151 | Resto Druid 1x Glad 2381 · Unholy DK 4x Glad 2880 · BM Hunter 2x Glad 2428 | 2 (0) | 9 / 11 | ours: Hozzaarr (Unholy) to Stomp 17,045 | LOCKED OUT at the death |
| 20:19 | W | 2129 / 2159 | Resto Druid 1x Glad 2381 · Unholy DK 4x Glad 2880 · BM Hunter 2x Glad 2428 | 3 (1) | 7 / 8 | theirs: Whâle (Unholy) to Death Coil 61,816 | - |
| 20:22 | L | 2168 / 2211 | Resto Shaman 8x Glad 2164 · Shadow Priest 8x Glad 3281 · Affliction 10x Glad 3271 | 2 (0) | 6 / 4 | ours: Captnmurphy (Windwalker) to Unstable Affliction 135,195 | lockout ended 0s before |
| 20:25 | L | 2144 / 2103 | Resto Druid 4x Glad 2535 · Fury Elite 1942 · Frost Mage 4x Glad 2718 | 2 (0) | 9 / 9 | ours: Captnmurphy (Windwalker) to Shatter 102,792 | no lockout in last 10s |
| 20:30 | L | 2103 / 2146 | Resto Druid 4x Glad 2535 · Fury Elite 1942 · Frost Mage 4x Glad 2718 | 3 (0) | 11 / 10 | ours: Hozzaarr (Unholy) to Execute 75,914 | LOCKED OUT at the death |

How to read it:

- **Defensives spent before the first death** is the quickest answer to "did we force anything
  off them". In the wins, they spent more than we did in 7 of 9 (88 against 66 in total). In the
  losses, they spent more in only 2 of 6 (41 against 44 in total).
- **"No profile"** means Blizzard has no public profile for that character, so their experience
  is unknown, not zero.
- **Legend (Shuffle)** is shown for a player whose best title is Solo Shuffle's top rank but who
  has never been Gladiator.

#### Looking into one game: 19:54, Mistweaver / Shadow Priest / Survival

Lost in 63s. Shadow Priest 5x Glad 2418, Survival 5x Glad 2555, Mistweaver unknown (no public
profile). Their MMR was 112 above ours, the biggest gap of the day. Every time below is on one
clock, from the `ARENA_MATCH_START` line (re-checked 2026-09-28; an earlier read mixed two start
points and was up to a second off).

**Every defensive we pressed came before any offensive cooldown of theirs.**

| Time | Who | What |
|---|---|---|
| 7.9s | their Priest | Psychic Scream on our Monk and DK |
| 8.9s | our DK | **Lichborne**: breaks the fear |
| 9.2s | their Hunter's pet | Intimidation on our healer |
| 9.8s | our DK | **Anti-Magic Shell** on our healer |
| 10.9s | our healer | **Pain Suppression** on the DK, at 74% health after 260k in two seconds |
| 11.8–14.8s | their Hunter | **Scatter Shot on our healer** |
| 13.5–13.8s | us | **Army of the Dead, Zenith, Dark Transformation**: our cooldowns go off *inside* that Scatter Shot |
| 14.8–15.8s | our Monk | Strike of the Windlord, Fists of Fury onto the Hunter |
| 16.1s | their Mistweaver | Leg Sweep on our DK |
| 16.8s | our DK | **Icebound Fortitude**: breaks the Leg Sweep after 0.7s |
| 18.1–18.2s | their Mistweaver, Priest | Sphere of Despair and Psyfiend on our Monk |
| 19.0–23.0s | their Priest | Silence on our healer |
| 19.7s | their Hunter | Disengage |
| 20.7s | our Monk | **Fortifying Brew**, at about 68% health |
| 24.8–30.9s | their Hunter | Binding Shot four times on our DK and Monk |
| 25.9s / 28.0s / 32.6s | their Hunter | **Gladiator's Badge, Aspect of the Eagle, Takedown: their first offensive cooldowns** |
| 32–34s | | our DK takes **505k in two seconds** |

- **Their CC was timed against our go.** Scatter Shot covered the exact moment our cooldowns went
  off, the Leg Sweep landed mid-go, and the Silence followed.
- **We overcommitted.** Before their go, our DK never dropped below 63% and our Monk never below
  68%. Two of our five defensives were used to break CC (Lichborne on the fear, Icebound on the
  Leg Sweep), and Pain Suppression and Fortifying Brew went out at 68–74%. When their real go came
  at 32.6s, nothing was left.
- **What we forced off them:** Invoke Chi-Ji, then Desperate Prayer and Roar of Sacrifice. When
  our DK died at 54.6s they still had Aspect of the Turtle, Dispersion, Life Cocoon, Revival and
  **all three trinkets**.
- **Not confirmed from the log:** a double Leg Sweep on us (only the Mistweaver's landed on us;
  ours hit them), and slows on our DPS during our go (none of the usual ones: the Hunter's snares
  are the Binding Shots from 24.8s). Kiting cannot be measured well: a player's position is only
  logged when they cast, so the Hunter's is seconds stale. The Disengage at 19.7s is the one
  direct sign.

That is a more experienced team absorbing our go with CC timed to our cooldowns while holding
every major defensive, making us spend ours early, then going once they were spent.

#### Did they peel our goes rather than eat them? (checked 2026-09-28)

19:54 suggested it: that team answered our go with CC on our healer and DPS and spent almost no
defensives. The question was whether that is how the teams that beat us generally played it:
**peel our go with CC instead of eating it with defensives.** Checked across every go of ours:

| Game | | Our goes | Their defensives per go | Their CC on our DPS per go | Their CC on our healer per go | Our DPS locked out | Our healer locked out | Followed by a kill |
|---|---|---|---|---|---|---|---|---|
| 19:26 | W | 2 | 3.0 | 2.5 | 0.0 | 9% | 0% | 2 of 2 |
| 19:29 | W | 3 | 3.3 | 3.7 | 1.3 | 14% | 20% | 2 of 3 |
| 19:35 | W | 4 | 2.2 | 3.2 | 2.0 | 12% | 31% | 2 of 4 |
| 19:43 | W | 3 | 3.3 | 3.0 | 2.3 | 11% | 28% | 1 of 3 |
| 19:47 | L | 1 | 4.0 | 2.0 | ~~3.0~~ 2.0 | ~~82%~~ 25% | ~~75%~~ 43% | 0 of 1 |
| 19:51 | W | 1 | 3.0 | 2.0 | 1.0 | 9% | 6% | 1 of 1 |
| **19:54** | **L** | 3 | **1.3** | 1.3 | 2.0 | 7% | 28% | 0 of 3 |
| 19:57 | W | 5 | 3.8 | 2.6 | 1.4 | 3% | 12% | 2 of 5 |
| 20:02 | W | 2 | 4.5 | 2.5 | 2.5 | 4% | 14% | 2 of 2 |
| 20:08 | W | 4 | 1.8 | 2.0 | 2.2 | 15% | 23% | 3 of 4 |
| **20:13** | **L** | 2 | **7.0** | 4.5 | 3.0 | 10% | 20% | 0 of 2 |
| 20:19 | W | 3 | 2.3 | 2.3 | 1.7 | 10% | 17% | 1 of 3 |
| 20:22 | L | 2 | 2.5 | 3.0 | 2.0 | 5% | 21% | 0 of 2 |
| 20:25 | L | 2 | 4.0 | 2.5 | 1.0 | 9% | 11% | 0 of 2 |
| 20:30 | L | 3 | 3.3 | 4.3 | 1.7 | 9% | 13% | 0 of 3 |
| **Wins** | | 27 | 3.0 | 2.7 | 1.7 | 10% | 19% | 59% |
| **Losses without 19:47** | | 12 | **3.4** | 3.1 | 1.9 | 8% | 19% | 0% |

**Not supported, except in 19:54.** The teams that beat us spent *more* defensives per go than
the teams we beat (3.4 against 3.0), and CC'd about as much (our DPS locked out 8% of our go time
against 10%, our healer 19% against 19%). At 20:13 they spent seven defensives per go and still
survived. 19:54 is the only loss where they peeled rather than ate (1.3 defensives per go), and
the one win where they spent few (20:08, 1.8) we still won.

So the losses were not a different *kind* of answer. The teams that beat us answered our goes
the same way the teams we beat did, mostly with defensives, **and it worked for them.** That
points back to what those defensives were attached to: more experienced players, a Resto Druid's
HoTs and Nature's Swiftness, and CC on our healer at their kill. Why the same answer held for
them and not for the others is the open question, not which answer they chose.

"CC per go" counts stun, silence, disorient and incapacitate auras that landed; "locked out" is
the share of the go's window spent under them.

#### Timing across all games: did the 19:54 pattern repeat? (checked 2026-09-28)

Two things decided 19:54: **CC on our healer as our cooldowns went off**, and **our defensives spent
while they were not in a go**. Neither is the general pattern of the losses.

**CC on our healer as our cooldowns went off** (locked out from 1s before to 4s after our first
offensive cast):

| | Our goes | Healer locked out as cooldowns went | Goes with the healer free | Followed by a kill, healer free |
|---|---|---|---|---|
| Wins | 27 | 9 (33%) | 18 | 12 |
| Losses without 19:47 | 12 | 2 (17%) | 10 | **0** |

It happened *more* often in the wins. In the losses our healer was free as our cooldowns went in 10
of 12 goes, and none of them led to a kill.

**Defensives spent before the first death while the other side was not in a go:**

| | Ours | Theirs |
|---|---|---|
| Wins | 7 of 66 (11%) | 13 of 88 (15%) |
| Losses | 5 of 44 (11%) | **1 of 41 (2%)** |

- **We overcommitted no more in losses than in wins.** 19:54 (4 of 7) is the outlier.
- **The teams that beat us almost never spent a defensive outside one of our goes: 1 in 41.** The
  teams we beat spent 13 of 88 that way. The teams that beat us held their defensives until we
  actually went. That is timing, on their side, and it fits the rest: they answered our goes with
  defensives at the moment they were needed, so the answer held. The counts are small (13 against
  1); treat it as a lead.

#### Who we played: experience separates wins from losses better than MMR

| | Their MMR, average | MMR diff | Their Glad seasons, average | Their best exp, average |
|---|---|---|---|---|
| Wins (9) | 2048 | +9 | **5.0** | 2497 |
| Losses (6) | 2149 | +30 | **12.2** | 2791 |

The two players with no public profile count as 0 Gladiator seasons here, which understates one win and one loss slightly.

- **MMR says the games were roughly even** on both sides of the ledger. Experience says the
  losses were against teams with more Gladiator seasons than ours (12.2 against our 11), and the
  wins against teams with under half as many.
- **20:22 is the extreme:** 26 Gladiator seasons and two players with 3270+ exp, at 2211 MMR.
  That is the early-season deflation Chriso described, in one game.
- **Not a clean rule:** we beat an 11-season team (20:08) and lost to a 7-season team once while
  beating them once (20:13/20:19).
- **All six losses came after our MMR passed 2060,** so "why did we lose" is partly "we climbed
  into more experienced teams". The rematches are the only games that hold the opponent constant.

#### How the kills happened in the wins

- **Every kill came 3–22s into a go** (first-pass go definition; under the strict one, a Touch of
  Death finish reads as its own go, see *Categories come first*). The DK's part was Army of the Dead with Dark
  Transformation, and the Monk's was Zenith, Leg Sweep and Touch of Death. The DK and Monk did
  88–99% of the damage on the target in the last 10 seconds.
- **The kills did not come from locking out the enemy healer.** The healer was locked out at
  the death in 2 of 8 (one of the 9 kills was the healer), had no lockout in the last 10s in 3,
  and had lockout ending 1–8s before in the rest.
- **In most kills the target's team had already spent major defensives in the 30s before.** That
  is a go forcing the defensives, then a kill once they are gone. Whether it was planned, the log
  cannot say.
- **Touch of Death got 4 of the 9 killing blows,** each on a target at 3–9%, each credited at
  about 740k. That is about 3M of the Monk's damage total, and it is finishing damage, not
  pressure.

#### Testing Chriso's read: pressure, and CC on the healer

Averaged per game, with the go as defined above (the chain, good and bad):

| | Wins | Losses | Losses without 19:47 |
|---|---|---|---|
| Our goes that forced a defensive | 85% | 92% | |
| Their defensives per our go | 3.0 | **3.7** | |
| Our goes followed by a kill (in the window or 30s after) | 59% | **0%** | |
| Their goes that forced one of our defensives | 91% | 100% | |
| Our defensives per their go | 2.8 | 3.5 | |
| Our healer locked out, share of *our* go time | 17% | 27% | 19% (first pass) |
| Our healer locked out, share of *their* go time | 20% | 28% | 18% (first pass) |
| **Our healer locked out at the moment of our death** (enemy healer, in wins) | **2 of 8** | ~~4 of 6~~ **3 of 6** (a fourth ended 0s before; corrected 2026-09-30, see the top of this section) | |

What that says about each half of the read:

- **"Our pressure was less effective":** our goes forced *more* defensives in the losses (3.7 per
  go against 3.0), so they were not weaker at forcing. They never **converted**: no go in a lost
  game was followed by a kill. The more experienced teams had the answers and spent them.
- **"Their pressure on us was stronger":** **unsettled.** The first pass said yes (89% against
  68%) because it let a lone utility or CC count as a go. The next three definitions showed no
  difference. This one shows a small one (3.5 defensives per go against 2.8). A difference that
  appears and disappears with the definition is not a finding.
- **"They CC'd the healer more easily":** **not by volume.** Without the one 44-second outlier
  (19:47), our healer was locked out for about the same share of go time in wins and losses.
- **"...and at better times":** **yes, this is the difference.** At the moment our player died,
  our healer was locked out in 3 of 6 losses (first written as 4; 19:47 was the Garrote bleed),
  and in a fourth the lockout ended that same second. When we got a kill, their healer was locked
  out in only 2 of 8.

So the losses read as: our goes forced as much as in the wins, or more, but never converted, and
the enemy's CC landed on the healer at the kill rather than spread across the game.

**Five conclusions held under all six go definitions tried:** our goes forced as much in losses,
they never converted in losses, drain predicts the kill, Resto Druid games converted worst, and
our healer was locked out at the death. Individual figures moved; those answers did not.

#### Drain, then kill

Each of our 40 goes, by how many of the enemy's defensives were **already on cooldown** when it
started:

| Their defensives on cooldown at go start | Goes | Followed by a kill | Defensives forced in the go |
|---|---|---|---|
| 0 | 10 | 3 (30%) | 5.0 |
| 1 | 5 | 0 (0%) | 3.8 |
| 2 | 1 | 1 (100%) | 2.0 |
| 3 or more | 24 | 12 (50%) | 2.2 |

**With 0–1 defensives down, 3 of 15 goes led to a kill. With 2 or more down, 13 of 25 did.** The
first go of a game is mostly the 0 row: it forces the most defensives (5.0) and rarely kills. That
is Chriso's model: the go forces defensives, and the kill comes on a later go. Counting defensives
forced in the same go shows no such pattern (0 forced: 40% followed by a kill, 3+ forced: 36%),
which is why drain is the measure.

Caveat: one death can count for two overlapping goes, and "defensive" only counts what is labelled.

#### Execution: good goes and bad goes

| | Goes in games won | Followed by a kill | Goes in games lost | Followed by a kill |
|---|---|---|---|---|
| **Our good goes** | 15 | **67%** | 8 | 0% |
| **Our bad goes** | 12 | 50% | 5 | 0% |
| Their good goes | 19 | 0% | 4 | 75% |
| Their bad goes | 4 | 0% | 7 | 71% |

- **In the games we won, our good goes converted more often than our bad ones** (67% against 50%).
- **In the games we lost, neither kind converted.** And their goes killed whether good or bad.
  Execution matters, but it does not decide the game on its own: how the other team reacts does,
  which is what experience and MMR are recorded for.
- **Our goes in losses carried more healer CC** (2.4 per good go, against 1.5 in wins) and still
  did not convert.

What a chain shows that a count cannot:

- **19:47 L, their go:** Sap on our healer, then Garrote, Cheap Shot and Cyclone, and only then
  Kingsbane, 30 seconds before the kill. A Rogue opener, read as one go.
- **20:25 L, their bad go that killed:** Intimidating Shout on the target, Cyclone on our healer,
  Recklessness, Avatar, Frozen Orb, then 8.5s to Ray of Frost, 9.1s to Storm Bolt on our healer,
  then Ray of Frost, Odyn's Fury and Cyclone on our healer again. Loosely timed, and it killed the
  Monk.

#### Bait

**Corrected 2026-09-28: there is no confirmed bait in these games.** The first version listed
eleven. Most of them were CC landing *inside the other side's go*, which is an answer to that go,
not bait. 19:54, first read as "bait, go, kill", was the enemy answering *our* go with CC (see
*Looking into one game*). With the rule fixed (a CC inside either side's go is never bait), one
candidate remains, and it is wrong too:

- **19:54, their Intimidation on our healer at 9.2s "drew" Pain Suppression.** But Pain
  Suppression went on our DK, who had just taken 352k, not on the healer who was stunned. The rule
  sees only that a defensive followed a CC within 5s. It cannot see that the defensive went to
  someone else, because the timeline does not carry a defensive's target.

To detect bait properly, the defensive has to go to the player who was CC'd, or be a response to
that CC. That needs the defensive's target from the log (`SPELL_AURA_APPLIED` on the recipient),
which the timeline does not keep yet.

#### Utilities inside our goes

| Our goes against | Goes | Their defs/go | Their utilities/go | Their CC/go | Our utilities/go | Our CC/go | Followed by a kill |
|---|---|---|---|---|---|---|---|
| teams with < 10 Gladiator seasons | 30 | 3.5 | 1.8 | 1.9 | 1.6 | 3.9 | 43% |
| teams with 10+ | 10 | 2.0 | 1.3 | 1.4 | 1.1 | 2.8 | 30% |

**Utility *counts* do not separate anything yet.** Experienced teams spent *fewer* defensives and
utilities per go and still converted less often. That fits spending the *right* answer once
rather than several, but counts cannot show it.

**What they used is more telling than how many:**

- **Games lost:** Nature's Swiftness (Resto Druid) 5, Innervate (Resto Druid) 2, Alter Time and
  Mass Invisibility (Frost) 2 each, Master's Call 2, Vampiric Embrace (Shadow), Healing Tide
  Totem and Tremor Totem (Resto Shaman) 1 each.
- **Games won:** mostly mobility and our own side's kind of utility: Evangelism (Discipline) 9,
  Divine Steed 12, Leap of Faith 4. Nature's Swiftness (Resto Druid) appears once.
- **Nature's Swiftness from a Resto Druid inside our goes:** 5 times in 13 goes in losses, once in
  27 in wins. That is the instant big heal Chriso named.
- **Our own utilities barely change:** Death's Advance and Evangelism in both.

The next step is to tie each utility to the go it answered: did the go that met Tremor Totem have
a Fear in it, and did the go that met Nature's Swiftness stop forcing defensives after it?

#### The Resto Druid theory

Enemy healer inside our goes:

| Healer | Goes | Games | Healing per second | Healed as a share of our damage | HoT share | Their defs/go | Our goes followed by a kill |
|---|---|---|---|---|---|---|---|
| **Resto Druid** | 11 | 5 (1 won, 4 lost) | 116,003 | 85% | **42%** | **3.9** | **9%** |
| Discipline | 10 | 3 | 89,742 | 87% | 0% | 2.9 | 60% |
| Holy Paladin | 7 | 2 | 76,774 | 120% | 4% | 2.7 | 43% |
| Mistweaver | 5 | 2 | 98,403 | 88% | 15% | 2.0 | 40% |
| Resto Shaman | 4 | 2 | 90,616 | 74% | 22% | 3.5 | 50% |
| Preservation | 3 | 1 | 141,967 | 100% | 35% | 3.3 | 67% |

- **Our goes converted worst against a Resto Druid: 9% (1 of 11), against 40–67% for every other
  healer.**
- **The Druid had the highest healing per second** of the healers seen in more than one game,
  and by far the most of it from HoTs (42%). That fits "flat healing from HoTs".
- **But as a share of our damage the Druid healed no more than the others** (85%, against 74–120%).
  So the numbers do not show the Druid out-healing our damage. They show the Druid's team
  spending the most defensives per go (3.9) alongside steady HoT healing and Nature's Swiftness,
  which leaves our goes with nothing to convert.
- **The racial part cannot be seen here.** None of the Resto Druids cast a racial on a cooldown,
  racials are missing from the spell data (see *Categories come first*), and passive racials
  never appear in a log. A player's race is on the Blizzard profile, so it could be recorded
  with experience.
- **Confound:** 4 of the 5 Druid games came after our MMR passed 2060, against teams averaging 8
  or more Gladiator seasons. Druid-ness and experience are not separated in this sample.

"Healed as a share of our damage" can exceed 100%, because healing lands on damage from before
the window, and on damage our side did not do (self-damage, falling).

#### The rematch: Resto Druid / Unholy / BM, lost 20:13, won 20:19

The same six players twice, seven minutes apart. The openings were nearly identical. Both teams
went at 4–7s, our opener forced 8 defensives in the loss and 5 in the win, and neither side
got a kill. The games split at the **second** exchange:

- **20:13 (lost).** Their second go (37–65s: Bestial Wrath and Dark Transformation, with the
  Druid's Nature's Swiftness in it) forced **six** of our defensives: Pain Suppression twice,
  Lichborne, Fortifying Brew, Anti-Magic Zone and a Medallion. Over the same stretch our Zenith go
  forced none. At 63s Intimidation and Bestial Wrath went onto the DK, the Priest was
  **Strangulated from 64.2s to 68.2s**, and the DK died at 67.3s with every defensive already
  spent.
- **20:19 (won).** Their second go (42–69s) forced only **two** of ours (Pain Suppression,
  Anti-Magic Zone). The Priest was Freezing Trapped for six seconds in the middle of it (57.8–63.8s),
  but with defensives still up nobody died. Our go at 65.8s chained Leg Sweep into Asphyxiate on
  the Druid (65.8–70.5s), and their DK died at 70.1s **with the healer locked out**.

The healer got CC'd in the middle of the enemy's go in both games. What differed was whether
our defensives had already gone into their previous go: drain, then kill, from their side.
**Two games; a lead, not a finding.**

#### Peak burst: do the DK and Monk land their damage together, on their healer's CC? (2026-09-28)

For each of our goes, the **6 seconds with the most damage from the DK and Monk** (pets credited),
what landed in it, and whether their healer was locked out 2s or more, or kicked, during it.

- **The peak holds 40–49% of a go's DPS damage in 6 seconds.** Killing goes peaked higher (1.41M
  against 1.07M), partly from Touch of Death finishes.
- **The peak usually comes late**, often 8–25 seconds after the go's first link: the cooldowns
  go out, and the damage arrives after them.
- **The DK and Monk usually burst together.** In 28 of 40 goes both did at least a quarter of
  the peak (a *joint* peak), and joint peaks were bigger (1.26M against 0.90M, Touch of Death
  excluded). Bursting together did not convert on its own (36% against 50% for one player's peak).
- **What converted was the joint burst landing on their healer's CC or a kick:**

| Our peak 6 seconds | All 15 games | Gladiator level |
|---|---|---|
| Joint burst **and** their healer locked 2s+ or kicked | **5 of 8 followed by a kill (63%)** | **3 of 5 (60%)** |
| Joint burst, their healer free | 5 of 20 (25%) | 1 of 7 (14%) |
| Any peak, healer locked or kicked | 6 of 10 (60%) | 4 of 6 (67%) |
| Any peak, healer free | 10 of 30 (33%) | 2 of 10 (20%) |

**Only 8 of our 28 joint bursts landed on CC or a kick on their healer.** The rest landed while
their healer was free to answer. That is the most concrete, fixable thing these games show: the
damage and the lockout are both there, and mostly not at the same time. The two kicks that landed
inside a killing peak were both the DK's Mind Freeze on their healer (19:26, 19:57).

Each peak is listed with its abilities in the raw output (`killread-26sep`, "every go").

#### The healer's CC on their healer, and where the losses came from (2026-09-29)

Chriso's read, as the healer: in the losses, if he had not overlapped defensives and had got more
CC on their Druid during the goes, the team would have had a better chance. Tested on the stored
analyses of all 15 games (`/wow/match-analysis`, RoundAnalysisService v3).

**CC on their healer during our goes: supported.**

| Our goes | Led to a kill |
|---|---|
| With the Priest's CC on their healer in the go | **11 of 23 (48%)** |
| Without it | 5 of 17 (29%) |
| Anyone's CC on their healer in the go | 14 of 36 (39%) |

**Against the Resto Druid, the lever is timing, not how often.** The Priest was already fearing the
Druid in 6 of the 11 goes against him (1 led to a kill; 0 of the 5 without). But the team's hardest
6 seconds landed while the Druid was locked out or kicked in only 4 of those 11. At 20:25 and twice
at 20:30 the fear landed on the Druid and the burst came while he was free. Across all 40 goes,
the Priest's CC was on their healer in 23, **but on them during the burst itself in only 7**.

So the actionable version is: **land the fear with the DK and Monk's burst**, not more fears. That
is the same finding as *Peak burst* above (a joint burst on their healer's CC killed 5 of 8 times,
5 of 20 with their healer free), now with the healer's own part in it.

**Overlapping defensives: supported, and the DK stacks too.** Every overlap now has an owner: the
player who put the second defensive on. In the losses: the Priest's Pain Suppression on the DK or
Monk while their own defensive was up (19:47, 19:54, 20:25 twice), and the DK stacking his own
Icebound Fortitude, Lichborne and Anti-Magic Shell on each other or on top of Pain Suppression
(19:54 twice, 20:13, 20:25 twice). At 19:54 all of it happened inside 17 seconds, before their
first offensive cooldown.

**Where the losses came from: a rough split.** Every mistake the log can pin on a button, owned by
whoever pressed it, plus what the other team brought, weighted and turned into shares (the rules
and weights are in `match-review-operations.md`, "Where the losses came from"):

| Owner | Share | From |
|---|---|---|
| **Discipline Priest** | **32%** | locked out when a teammate died with the Medallion on cooldown (19:54, 20:13, 20:22, 20:30) or available (19:47: ~~counted~~ not a lockout, corrected 2026-09-30); four overlaps; one defensive outside their goes |
| Your team (burst timing) | 23% | ten bursts that landed with their healer free |
| **Unholy DK** | **18%** | five overlaps of his own defensives; three defensives outside their goes |
| Them: answered every go | 11% | five losses where our goes forced defensives and none killed |
| Them: more experienced | 9% | 19:47 (14 Gladiator seasons to our 11) and 20:22 (26 to 11) |
| Windwalker Monk | 5% | one overlap, one defensive outside their goes |
| Them: higher MMR | 2% | 19:54 (+112) |

The split agrees with Chriso's sense of it: the largest single share is the healer's, and most of
it is timing: the Medallion gone before the enemy's kill go, Pain Suppression on top of a
defensive already up. Next is the team's burst landing on a free healer, which is partly the
healer's too (see above). **It is an estimate from rules, not a verdict.** The log cannot see
positioning, calls, or a mistake nobody pressed a button for, and the weights are judgement.

**Challenged the next day: see *Was the trinket warranted* below.** Most of the Priest's share
comes from Medallions and overlaps pressed with a teammate two to four seconds from death.

#### Was the trinket warranted, and was the second defensive needed? (2026-09-30)

Chriso's objection to the split above: it scores the Medallion by whether it was on cooldown at a
death, and an overlap by whether two defensives were up at once. Neither asks what the trinket
broke, or whether one defensive was enough for the damage coming in. Measured on the six losses
with `tools/match-review/warrant.php`:

- **For each Medallion:** what came off at that moment, and what our other players were taking in
  the seconds of CC it saved.
- **For each overlap:** the target's health when the second defensive went on, and the damage
  coming in (absorbs included).
- **Time to live** for both: health divided by the incoming rate over the previous 3 seconds.
  It is a rate, not a forecast, but it separates "about to die" from "comfortable".

**The Priest's Medallion in the losses:**

| Game | What came off | Teammate at the trinket | Time to live | What followed |
|---|---|---|---|---|
| 19:47 | never used | | | Monk died at 35.9s with the Medallion up. ~~The Priest was in Garrote (30.2–38.0s)~~: that aura was the bleed, and his last lockout ended at 28.3s (corrected 2026-09-30) |
| 19:54 | Psychic Scream, 1.1s in (~4.9s left) | DK 34%, 83k/s | ~4s | 650k healing in the saved seconds; DK died 14s later, Priest in Intimidation |
| 20:13 | Freezing Trap, 0.7s in | DK 31%, 86k/s | ~4s | DK died 10s later, Priest in Strangulate (64.2–68.2s) |
| 20:22 | Psychic Scream, 1.6s in (~4.4s left) | Monk 62%, 52k/s | ~13s | 637k healing in the saved seconds; Monk died 40s later |
| 20:25 | **only Thunder Clap (a slow)** | DK 47%, 139k/s | ~4s | Pain Suppression 0.5s later; DK died at 93.8s with the Priest in Cyclone + Polymorph |
| 20:30 | Cyclone and Polymorph, 1.1s in (~3.9s left) | DK 50%, 155k/s, then 227k/s | ~2–3s | DK still fell to 27% with the Priest free; died 90s later |

- **Three of the five (19:54, 20:13, 20:30) were pressed with the DK about 2–4 seconds from death.**
  Holding the Medallion meant sitting in a fear, a trap or a Cyclone while that happened. The rule
  scores all three as the heaviest fault because the *next* CC came before the Medallion was back,
  14, 10 and 90 seconds later. That is a question about the second CC, not the first trinket.
- **20:22 is arguable.** The Monk had about 13 seconds at the incoming rate.
- **The one the log reads as spent badly is 20:25, and the rule does not flag it.** Nothing but a
  Thunder Clap came off, and the DK's death 60 seconds later (93.8s) came with the Priest
  Cycloned, the Medallion still on cooldown. A Medallion breaks Cyclone (it did at 20:30). Positions exist only on casts, so
  whether the slow was keeping him out of range cannot be seen.
- ~~**19:47 is the rule's one clean case:** the Medallion unused through a Garrote silence while
  the Monk died.~~ **Wrong (2026-09-30).** There was no silence to break: the Priest had the
  Garrote bleed on him, and nothing that locked him out in the last 8 seconds. The rule has no
  clean case left in these six losses.

**The overlaps the Priest put on in the losses:**

| Game | On | Already up | Health | Incoming, 3s before | Time to live | Then |
|---|---|---|---|---|---|---|
| 19:47, 21.1s | Monk | Fortifying Brew | 47% | 148k/s | 3.4s | fell to 25% **with both up**; died 15s later |
| 19:54, 10.9s | DK | Lichborne | 77% | 110k/s | 7.2s | not inside their go |
| 20:25, 34.2s | DK | Anti-Magic Shell | 41% | 165k/s | 2.7s | the damage during it was 100% physical |
| 20:25, 66.4s | DK | Lichborne | 29% | 134k/s | 2.3s | |

- **Three of the four went on with the target under 3.5 seconds from death.** At 19:47 two were
  not enough: the Monk still fell to 25%. That points the other way from the claim: more was
  needed, not less.
- **Two of the "defensives" already up do not reduce physical damage.** Lichborne is 6% Leech and
  fear/charm/sleep immunity, no damage reduction at all. Anti-Magic Shell absorbs magic only. The
  overlap measure matches any labelled defensive by name, so Pain Suppression on top of either
  counts as stacking. It is not stacking in any sense that matters.
- **The arguable one is 19:54 at 10.9s:** 77% health, outside their go, about 7 seconds to live.

**The DK's own stacks.** ~~Two read as unneeded by health alone~~ (corrected the same day by the
classifier below): at 19:54 (16.8s) his Icebound Fortitude at 71% health **broke a Leg Sweep**. At
20:25 (75.6s) his Anti-Magic Shell went on with the Frost Mage's Ray of Frost and the Warrior's
Avatar running on him. His other two (20:13 at 41.0s, 49% at 196k/s; 20:25 at 68.8s, 38% at
72k/s) were under real pressure. **Health alone cannot judge a defensive that also breaks CC.**

**What this changes.** Of the Priest's 14 weighted points in the split, 9 come from decisions made
with a teammate about 2–4 seconds from death: the three Medallions and three overlaps above. The
rules punish the Priest for keeping the DK alive through one go and then being CC'd in the next.
What the log does support against the Priest is narrower:

- the Medallion at 20:25;
- ~~the unused one at 19:47~~ (not a case: see the correction above);
- possibly 20:22 and the 19:54 Pain Suppression.

The larger thread is the one in *The rematch* above: **their second CC on the Priest landed while
the DK was already low.** Drain, then kill.

**For the rules, not yet changed:**
- Score a Medallion only when nothing that locks you out came off, or when no teammate was in
  danger. A time to live over about 8 seconds would be the first cut.
- Score an overlap only when the target was not in danger, or when the first defensive does not
  cover the incoming school.
- Any threshold is judgement.

`RoundAnalysisService` would need to store time to live at each defensive and trinket. Games
already uploaded would have to be uploaded again to get it, because the raw log is discarded.

#### Why each defensive was pressed (2026-09-30)

Chriso's follow-up: a defensive is not only for health. Icebound Fortitude also breaks stuns and
makes the DK immune to them, so he may press it to burst without being stunned. Or the enemy's
cooldowns were up and it went on before the damage did. Every defensive in the 15 games
(`warrant.php`) is given every reason the log can show, and a verdict from the strongest:

| Reason | What the log shows |
|---|---|
| **danger** | 5s or less to live at the rate of the previous 3s, or 35% health or lower |
| **cc** | a lockout came off the target as it was pressed, or a stun, fear or incapacitate hit them `IMMUNE` while it was up (`SPELL_MISSED ... IMMUNE`: seen, not inferred) |
| **insure** | the target was in their own go (an offensive cast 3s before to 8s after), and the spell grants CC immunity by its own effects (`ccImmunityGrantedBy()`): Icebound Fortitude stuns, Lichborne fear |
| **focus** | their go was on the target, with their offensive cooldowns actually running (inside each one's duration) |
| **alone** | a DPS pressed it while our healer was locked out |
| **none** | none of these |

| Player | Defensives | danger | cc | insure | focus | alone | none |
|---|---|---|---|---|---|---|---|
| Discipline Priest | 25 | 18 | 0 | 0 | 6 | 0 | **1** |
| Unholy DK | 42 | 14 | 12 | 3 | 9 | 0 | **4** |
| Windwalker Monk | 12 | 3 | 1 | 0 | 3 | 1 | **4** |
| Their teams | 110 | 48 | 12 | 2 | 10 | 4 | **34** |

- **The Priest's defensives are almost all under danger.** 18 of 25 had the target five seconds
  or less from death, and 6 more went on with the enemy's cooldowns running. The one with no
  reason is the 19:54 Pain Suppression already noted (77%, about 7 seconds to live).
- **The DK's Icebound Fortitude and Lichborne are CC answers as often as health answers.** 12 of
  his 42 broke a lockout or ate one: Shockwave, Storm Bolt, Rake, Binding Shot, Leg Sweep,
  Chaos Nova, Capacitor Totem, and Howl of Terror, Psychic Scream, Intimidating Shout and Sigil
  of Misery with Lichborne. Three more were the pre-emptive stun or fear immunity inside his own
  go (19:35, 19:54, 19:57). Your read is right, and the old overlap rule could not see any of it.
- **The Monk's Fortifying Brew is the least explained:** 4 of 12, all at 67–100% health with 11
  or more seconds to live.
- **The enemy pressed a third of theirs with no reason the log shows** (34 of 110, against 9 of 79
  of ours). That is not yet a finding. It may be reactions to our CC casts before they land, which
  this does not read.

**What it cannot see:** a CC being cast at the player when the defensive went on (a Hammer of
Justice in flight), positioning, a call. So **none** means *no reason in the log*, not *wrong*.
The thresholds (5s, 35%) are judgement, and Wraith Walk is labelled a defensive although it is
mobility.

#### Was the reason valid? (2026-09-30)

Chriso: having a reason is not the same as the reason being a good one. The test has two halves.
Did the defensive do the job its reason claims, and what did it cost? Measured by `warrant.php`
on our 79 defensives.

**The job: replay the window without it.**
- Each enemy hit the defensive covered is added back at its own reduction, from the spell's
  effects: Pain Suppression 40%, Icebound Fortitude 30%, Fortifying Brew 20%, Anti-Magic Zone 15%
  of magic.
- Absorbs are added back from the defensive's own `SPELL_ABSORBED` lines. Lichborne is his Leech
  healing above his normal rate (Vampiric Aura raises Leech while it is up).
- Health without it = logged health minus everything added back, checked to 3s after it ends.

**Three things decide a press where the damage then stopped:**
- **Did they swap?** Their damage moved to someone else: the defensive worked by being seen.
- **Did their whole output fall?** Something else ended their go.
- **Were their cooldowns ending?** If their offensive cooldowns had 3s or less left, or none were
  up, the burst was ending on its own, and the defensive covered its tail.

| Verdict | Meaning |
|---|---|
| VALID | would have died without it, removed 20%+ of max health, a lockout hit it immune, broke a CC during a go, or forced a swap |
| VALID AT PRESS | in danger when pressed, their cooldowns still up, then the damage stopped. Right on what could be known. Why it stopped is open (below). |
| PARTLY | removed 8–20% of max health |
| LATE | in danger, but their cooldowns had 3s or less left: the burst was already ending |
| WEAK | broke a CC outside any go; stun/fear immunity that no CC tested; or the damage never came (under 8% removed) |

| Player | VALID | VALID AT PRESS | PARTLY | LATE | WEAK |
|---|---|---|---|---|---|
| Discipline Priest (25) | 8 | 9 | 5 | 3 | **0** |
| Unholy DK (40) | 13 | 8 | 1 | 2 | **16** |
| Windwalker Monk (12) | 6 | 0 | 2 | 1 | **3** |

(Two DK presses at 19:51 have no reading: he took no damage, so his health was never logged.)

- **Every one of the Priest's defensives held up. In the losses none was late or weak** (5 VALID,
  4 VALID AT PRESS, 1 PARTLY). Four forced a clean swap or would otherwise have been a death:
  - 19:47: the Monk's damage share went 100% → 47%;
  - 19:54 at 35.5s: the DK would have reached −4%;
  - 20:25 at 66.4s: 66% → 21%;
  - 19:57 at 143s: the DK would have reached −29% (a win).

  All three LATE presses are in wins (19:29, 19:35, 19:57), where a late Pain Suppression cost
  nothing.
- **The DK's weak presses are CC breaks outside any go, and damage that never came.** In the
  losses, the CC breaks were:
  - Icebound Fortitude on a Leg Sweep at 19:54 (16.8s), with nothing of theirs running. This
    reverses the defence of it above: it had a reason, just not a good one;
  - Lichborne on Intimidating Shout at 20:25 (55.8s) and at 20:30 (25.5s).

  The ones where the damage never came:
  - Icebound Fortitude at 20:25 (68.8s): 0% removed;
  - Anti-Magic Zone at 20:30 (97.3s);
  - Wraith Walk at 20:30 (70.2s).

  A fear broken outside a go may still have stopped a go from starting, which the log cannot
  show.
- **By reason:**
  - *danger*: 35 presses, 0 weak, 6 late;
  - *cc*: 7 valid, 5 weak (all quiet-moment breaks);
  - *insure*: 1 of 3 tested (the other two, Icebound Fortitude at 19:35 and 19:57, took no stun);
  - *focus*: 5 valid, 7 partly, 6 weak;
  - *none*: 6 of 8 weak.

  A pre-emptive press is the gamble. A press in danger almost never is.
- **Lichborne's Leech bought 0–8% of his health** across these games. Pressed in danger it is worth
  its fear immunity, not its healing.

**Open: why the damage stopped.** In 17 presses the enemy's whole output fell to 2–48% while
their cooldowns still had 4–22 seconds to run. No swap, no expiry. The next measure is whether our
CC landed on their DPS in that second (a peel), or their team turned to CC our healer.

**The cost half does not discriminate yet.** In the losses almost every defensive was still on
cooldown when its player died. That is because the cooldowns are the data's base values, not
talent-modified ones: Fortifying Brew reads 360s (rule 34). "Missing at the death" needs the
player's real cooldown, which the log's `COMBATANT_INFO` talents could supply.

#### How our DK and Monk play their specs (2026-09-28)

`tools/match-review/rotation.php`, on the 7 Gladiator-level games (10.5 minutes of arena).
The source for the two class guides.

**Unholy DK** (4x Gladiator). PvP talents in all 7: Spellwarden, Life and Death, Necrotic Wounds.

- **The burst opens the same way almost every time:** Death Grip (about 2s before Army, 4 of 9),
  Blinding Sleet (1.3s before, 5 of 9), Army of the Dead, Dark Transformation within a second
  (7 of 9), Soul Reaper about a second later (6 of 9), Putrefy about 2.4s after Army, then
  Necrotic Coil and Vampiric Strike. Asphyxiate lands 4–10s after Army in 4 of 9.
- **Damage:** Vampiric Strike 14% (8.9 casts a minute), Putrefy 10%, pet Necrotic Bolt 8%,
  Necrotic Coil 7%, Dread Plague's dispel burst 6%, Death Coil 6%. Pets over a quarter in all.

**Windwalker Monk** (6x Gladiator). PvP talents in all 7: Turbo Fists, Wind Waker; Grapple Weapon
in 4.

- **The burst:** Paralysis before 11 of 18 Zeniths, Ring of Peace about 2s before Leg Sweep
  (6 times), Leg Sweep 0.9s before Zenith (7 of 18), Gladiator's Badge with Zenith (11 of 18),
  then Rising Sun Kick or Strike of the Windlord within half a second, Fists of Fury within 5s
  (9 of 18).
- **Damage:** Spinning Crane Kick 17%, Rising Sun Kick 14%, Fists of Fury 12%, Blackout Kick 9%,
  Tiger Palm 8.5% (the most-pressed, 6.4 a minute), Rushing Wind Kick 8%, Strike of the Windlord
  6%, Touch of Death 5% (finishes).

Both are one player each, and describe what they did, not what is optimal.

**Why they press what they press** (added 2026-09-29, from the resource on each cast, the buffs up
when it was pressed, and the talents' own text):

- **DK.** Army first, Dark Transformation within a second: *Commander of the Dead* makes Dark
  Transformation give the Lesser Ghouls and Magus +25% for 30s, so the Army has to be out.
  *Gift of the San'layn* turns his strike into Vampiric Strike inside Dark Transformation: 73%
  of his Vampiric Strikes were. *Reaping* makes Dark Transformation reset Soul Reaper and lets it
  hit any target: 78% of his Soul Reapers had it up. *Forbidden Knowledge* turns Death Coil into
  Necrotic Coil for 30s after Army: every Necrotic Coil was inside it, pressed near full runic
  power (median 86 of 100). *Necrotic Wounds* makes Putrefy absorb 8% of the target's healing,
  stacking to 3. Death Coil outside Army went out at a median 76 runic power.
- **Monk.** *Zenith* resets Rising Sun Kick and cuts every chi cost by 1 for 15s, so the chi
  spenders cluster inside it (Strike of the Windlord 70%, Fists of Fury 60%). *Dance of Chi-Ji*
  made 57% of his Spinning Crane Kicks free (up for 56% of them, against 12% of all casts);
  *Blackout Kick!* (Combo Breaker, and Sequenced Strikes after a free Crane Kick) made 79% of his
  Blackout Kicks free. Tiger Palm went out at full energy 67% of the time. Rushing Wind Kick was
  only ever pressed with its proc up. *Hit Combo* pays for never repeating a button.

Both guides carry the player's own build, which the page shows and resolves every cooldown
through.

#### Level of play (added 2026-09-28)

By `guides-from-play.md`'s rule (Gladiator level = every player in the game has a Gladiator
season):

| Level | Games | Record |
|---|---|---|
| **Gladiator** | 19:26, 19:47, 19:51, 19:57, 20:13, 20:19, 20:22 | 4 W, 3 L |
| Gladiator? (one player unknown) | 19:54, 20:08 | 1 W, 1 L |
| Mixed | 19:29, 19:35, 19:43, 20:02, 20:25, 20:30 | 4 W, 2 L |

#### Damage by ability

Ours onto them, pets credited to their owners, all 15 games (162.6M in total). **No single
ability carries the comp.** The top twelve each did 3–7%: Vampiric Strike and Rising Sun Kick
6.7% each, Spinning Crane Kick 6.3%, Penance 4.8%, Fists of Fury and Tiger Palm 4.6%, the DK's
melee 4.2%, Putrefy 4.0%, Rushing Wind Kick 3.9%, Blackout Kick 3.7%, Necrotic Bolt 3.5%, Dread
Plague (Erupt) 3.1%. Touch of Death, for all its 740k finishes, is about 1.8%. The Gladiator-level
games look the same, with Spinning Crane Kick first (7.5%).

#### What our goes that killed had in common

Each of our goes, split by whether a kill followed (in the window or 30s after):

| In the go | All 15 games: killed (16) | not (24) | Gladiator level: killed (6) | not (10) |
|---|---|---|---|---|
| **Psychic Scream on their healer** | 69% | 50% | **100%** | 60% |
| Touch of Death | 25% | 4% | 33% | 0% |
| 2+ of their defensives already down | 81% | 50% | 67% | 50% |
| Paralysis on their healer | 38% | 75% | 67% | 60% |
| Asphyxiate on their healer | 38% | 63% | 67% | 60% |
| Dark Transformation | 88% | 88% | 100% | 90% |
| Zenith | 81% | 83% | 100% | 90% |
| A good (tight) go | 63% | 54% | 33% | 60% |

- **Psychic Scream on their healer is the clearest marker of a go that kills**, and at
  Gladiator level it was in every one. Paralysis and Asphyxiate on the healer do not separate
  in the same way; across all games, goes relying on them converted *less*.
- Touch of Death shows up because it is the finish, not because it made the go.
- Army, Dark Transformation and Zenith are in almost every go, killing or not. They are the go,
  not what decides it.

#### Overlapping defensives

Two defensives on one player at once for a second or more, before the first death:

| | Ours | Theirs |
|---|---|---|
| Wins (9) | 11 (1.2 a game) | 13 |
| Losses (6) | **14 (2.3 a game)** | 9 |
| Gladiator wins (4) | 3 | 5 |
| Gladiator losses (3) | **5** | 4 |

Nearly every one of ours is **Pain Suppression on the DK on top of his own Icebound Fortitude,
Lichborne or Anti-Magic Zone**. Stacking doubled in the losses. Two defensives at once on a
player who needed one is one fewer for the next go.

**Corrected (2026-09-30, then 2026-10-03): do not read this as "avoid overlaps".** Three of the
healer's four overlaps went on with the target under 3.5 seconds from death (*Was the trinket
warranted* below). On 3 Oct, holding Pain Suppression because Barkskin was up lost a game: the
healer was locked out for nine seconds straight after. The overlap rule is no longer a fault
anywhere.

#### Interrupts

Measured for the first time (2026-09-28); the timeline did not read `SPELL_INTERRUPT` before.

| | Our kicks | on their healer | inside our go | Their kicks |
|---|---|---|---|---|
| Wins | 24 | 6 | **18** | 2 |
| Losses | 14 | 4 | **8** | 1 |

- **The enemy barely kicked us: 3 times in 15 games.** Interrupts did not decide these games
  from their side.
- **In the wins, three of our four kicks landed inside our go; in the losses, just over half.**
  Most of ours stop CC casts (Polymorph, Cyclone, Fear) or a caster's damage.

#### Gladiator level only (7 games)

The measures above, on the 7 Gladiator-level games alone, as the Walking Dead guide cites them:

- Our goes followed by a kill: **55% in the wins (6 of 11), none in the losses (0 of 5).**
- In the losses the enemy spent **more** defensives per go than the teams we beat (4.6 against
  3.2).
- Our healer was locked out at our player's death in 1 of the 3 losses (20:13), and at 20:22 the
  lockout ended the same second. The Medallion was already used in both. **Corrected 2026-09-30:**
  this read "all 3", counting 19:47, where the Priest's last lockout had ended 8 seconds earlier
  and the aura on him was the Garrote bleed.
- **Not confirmed at this level:** drain predicting the kill (16 goes are too few), tight goes
  converting better (they did not), and overcommitment (none of ours in the losses).

### 30 Sep: the Jungle, 14 games of 3v3, and our Feral and Hunter against higher-rated ones

Feral (Crawlordx), Beast Mastery Hunter (Doubletapz), Discipline Priest (Jmjay). 7 won, 7 lost.
Written 2026-09-30. Times are the clock the tools print.

**The questions.** What separated the wins from the losses? And how do our Feral and our Hunter
play differently from the higher-rated Ferals and Hunters in the same archive?

**The sample.**
- **Our games:** 14, all on 30 Sep, our MMR 1914–2106 as the log records it.
- **Our experience:** Feral 1x Glad 2645 · Hunter Legend (Shuffle) 2215, no Gladiator season ·
  Priest 1x Glad, 1054 on this character.
- **Ferals to compare with:** Rastic, 46 games on 30 Sep at 1930–2301 MMR, healed by Skylake, in
  Feral / Mage / Discipline. He is the large sample. Plus seven games of seven other Ferals at
  2119–2309 (Zyhsul 5x Glad 3011, Badkittylolz 12x Glad 2809, Meowzetzan 8x Glad 2853, Hawtpants,
  Wokcats, Gdru, Sufferpoints), three of them in our comp.
- **Hunters to compare with:** ten games of eight Beast Mastery Hunters at 2072–2202 (Joonixo 5x
  Glad 2815, Shootonface 4x Glad, Splitbreed 3x Glad, Ketaa, Letmeshoo, Notdru, Leitador,
  Alphaswagboy). **17.6 minutes in all, and they went 3–7**, because most were the teams Skylake's
  side beat. Read the Hunter comparison as "what higher-MMR Hunters press", not "what wins".
- **Rastic's rating is MMR, not experience:** his own profile reads Duelist, 2125 highest. He is
  the comparison because of what he did in 46 games we can measure, not because of a title.
- Not used: the 9 Jungle games from May (a Resto Druid healer at 1437–1684), and Marksmanship
  Hunters, a different spec.

Produced with `killread.php`, `rotation.php` and the new `specread.php`
(`match-review-operations.md`, "One spec, side by side"). Raw output:
`storage/app/private/match-review/jungle-2026-09-30/`.

#### The review table

| Game | Result | MMR (us / them) | Enemy team (healer first; Gladiator seasons, or best rank if none; highest 3v3) | Our goes (followed by a kill) | Defensives spent before the first death (us / them) | First death | Our healer at that death |
|---|---|---|---|---|---|---|---|
| 17:50 | W | 1991 / 2006 | Resto Shaman 3x Glad 2231 · Arms 12x Glad 3081 · Havoc (no profile) | 3 (2) | 4 / 5 | theirs: Arms to Searing Light | - |
| 17:56 | W | 2068 / 1889 | Preservation 1x Glad 2074 · Marksmanship Elite 1820 · Balance Elite 2082 | 3 (1) | 6 / 11 | theirs: Marksmanship to Ferocious Bite | - |
| 18:02 | W | 2092 / 1859 | Resto Druid Legend (Shuffle) 1425 · Devourer Strategist 2009 · Elemental Legend (Shuffle) 2135 | 3 (2) | 8 / 5 | theirs: Elemental to Auto Shot | - |
| 18:08 | L | 2106 / 1892 | Holy Paladin Duelist 1822 · Fire Mage 2x Glad 1850 · Destruction 2x Glad 2419 | 1 (0) | 2 / 1 | ours: **Feral** at 20s to Ignite | no lockout in last 10s |
| 18:11 | W | 1953 / 2058 | Holy Priest 1x Glad 2270 · Fire Mage 1x Glad 1844 · Windwalker 1x Glad 1710 | 2 (2) | 5 / 5 | theirs: Windwalker to Kill Command | - |
| 18:18 | L | 2038 / 2009 | Disc Priest 4x Glad 2516 · Enhancement 3x Glad 2710 · Assassination Elite 2446 | 1 (0) | 4 / 2 | ours: **Feral** at 43s to Sudden Demise | lockout ended 5s before |
| 18:21 | L | 1970 / 2271 | Preservation 8x Glad 1800 · Fire Mage 6x Glad 2168 · Arms Elite 576 | 3 (0) | 11 / 12 | ours: **Hunter** at 151s to Pyroblast | lockout ended 1s before |
| 18:25 | W | 1964 / 1972 | Disc Priest 1x Glad 2270 · Windwalker 1x Glad 1710 · Fire Mage 1x Glad 1844 | 5 (2) | 9 / 9 | theirs: Windwalker to Barbed Shot | - |
| 18:42 | L | 2015 / 1959 | Resto Druid (no profile) · Ret 1x Glad 1968 · Balance 6x Glad 2081 | 3 (0) | 7 / 14 | ours: **Hunter** at 163s to Shooting Stars | lockout ended 0s before |
| 18:49 | L | 1950 / 1998 | Resto Druid (no profile) · Ret 1x Glad 1968 · Balance 6x Glad 2081 | 5 (0) | 17 / 15 | ours: **Hunter** at 221s to Starsurge | lockout ended 1s before |
| 18:56 | L | 1916 / 2238 | Holy Priest 3x Glad 2946 · Destruction 3x Glad 2645 · Assassination 4x Glad 2685 | 2 (0) | 12 / 20 | ours: **Feral** at 145s to Sudden Demise | LOCKED OUT at the death |
| 19:02 | W | 1914 / 2034 | Holy Paladin 8x Glad 2505 · Marksmanship 7x Glad 2360 · Arcane 6x Glad 2168 | 5 (3) | 9 / 19 | theirs: Marksmanship to Unseen Slash | - |
| 19:09 | L | 1969 / 2241 | Holy Priest 3x Glad 2946 · Destruction 3x Glad 2645 · Assassination 4x Glad 2685 | 3 (0) | 8 / 13 | ours: **Feral** at 107s to Sudden Demise | LOCKED OUT at the death |
| 19:17 | W | 1965 / 1914 | Resto Druid Strategist 1985 · Ret Elite 1338 · Marksmanship Duelist 1895 | 4 (1) | 13 / 28 | theirs: Marksmanship to Melee | - |

- **Three of the seven losses were to teams 270–320 MMR above us** (18:21, 18:56, 19:09). The
  other four were at our MMR or below it (18:08, 18:18, 18:42, 18:49).
- **Every kill we got was on a DPS, and every game we lost began with one of our DPS dying:** the
  Feral four times, the Hunter three. The Priest never died first.
- **Our healer was locked out at the death, or had been within the second before it, in 5 of the
  7 losses.** The two exceptions are the two fastest losses, 18:08 and 18:18.
- **The enemy's experience does not split these games** the way it did on 26 Sep: the teams we
  beat average about 6 Gladiator seasons and the teams we lost to about 8, and we beat a 21-season
  team (19:02) and a 15-season one (17:50).

#### Wins against losses

| Our goes | Games won (25 goes) | Games lost (18 goes) |
|---|---|---|
| Followed by a kill | **13 (52%)** | **0** |
| Their defensives spent per go | 3.2 | 4.7 |
| **Our healer locked out as our cooldowns went off** | **5 (20%)** | **11 (61%)** |
| Our healer locked out, share of the go | 13% | 25% |
| Our DPS locked out, share of the go | 5% | 14% |
| Their lockout CC landed on our DPS, per go | 1.8 | 4.1 |
| **Their healer locked out 2s+ during our hardest 6 seconds** | **15 (60%)** | **5 (28%)** |
| Their healer locked out, share of the go | 33% | 22% |

- **Our pressure forced more in the losses, and converted none of it.** That is the 26 Sep
  finding again, with a different team: 4.7 defensives a go against 3.2, and no kill.
- **The clearest difference is timing on both healers.** In the losses their healer was free
  during our hardest six seconds in 13 of 18 goes, and our own healer was locked out as we
  pressed our cooldowns in 11 of 18.
- **Burst landing on their healer's lockout is what converted**, across all 43 goes:

| Our hardest 6 seconds | Goes | Followed by a kill |
|---|---|---|
| Both DPS in it (25%+ each) **and** their healer locked out 2s+ | 19 | **9 (47%)** |
| Both DPS in it, their healer free | 14 | 3 (21%) |
| One player's burst | 10 | 1 (10%) |

- **Drain, then kill, again.** With none or one of their defensives already on cooldown, 2 of 15
  goes led to a kill. With three or more down, 11 of 28 did.
- **The kills are the Hunter's damage.** In the last 10 seconds before each of our seven kills he
  did 47–74% of the damage on the target in six of them. The Feral did 19–38% in those six, and
  51% in the seventh (19:02).
- **In goes that killed, Psychic Scream was on their healer in 85% and Cyclone in 31%**, against
  63% and 13% in goes that did not. Freezing Trap on the healer is in nearly all of them either
  way (85% and 70%).
- **Kicks did not separate anything:** 12 of ours in the wins and 15 in the losses.

#### The four losses at our own MMR, and how each death happened

Read with `specread.php --deaths=`: health every few seconds before the death, every defensive
the player pressed and the health it was pressed at.

| Game | Who died | What the log shows |
|---|---|---|
| **18:08**, lost in 33s to a 1892 team | Feral, at 20s | **100% to dead in 12 seconds under one Combustion, and one defensive pressed: Survival Instincts at 14%, 1.3s before the death.** No Barkskin, Bear Form, Frenzied Regeneration, Regrowth or Medallion. Our healer was never locked out. The Fire Mage did 980k of it. |
| **18:18**, lost in 47s | Feral, at 43s | A Rogue opener. Our healer was locked out for 16 of the 24 seconds from 14.6s. The Feral took a 5-second Kidney Shot, then was **free for the last 11 seconds at 25–56% health**. He pressed Barkskin (59%), Bear Form (28%) and Frenzied Regeneration (21%). **Survival Instincts and his Medallion were not pressed at any point.** |
| **18:42**, lost at 163s | Hunter | **100% to dead in 4 seconds inside a Mighty Bash**, 11 seconds after Avenging Wrath and Execution Sentence. **His Medallion, Aspect of the Turtle and Exhilaration were not pressed once in the whole game.** Our healer's Hammer of Justice ended 3s before, and a Cyclone caught him in the last second. |
| **18:49**, the same team, lost at 221s | Hunter | 78% to dead in 3 seconds. **Aspect of the Turtle at 2% health, 0.1s before the death.** Survival of the Fittest went out 16s earlier at 70%. |

The three against teams far above us:

- **18:21** (their MMR 2271): the Hunter pressed Aspect of the Turtle and Exhilaration both at 1%
  health about 55 seconds before he died, and had neither for the go that killed him.
- **18:56 and 19:09** (2238 and 2241, the same team): the Feral died to a Kidney Shot both
  times (1.9s and 0.8s after it ended, the second from 75% health in four seconds), with our
  healer locked out. At 18:56 he had pressed **Barkskin at 99% health and the
  Medallion at 98%** about 50 seconds earlier, and Survival Instincts 70 seconds earlier. At 19:09
  Survival Instincts went out at 53%, 18 seconds before the death, and had run out 12 seconds
  before it. It was pressed before their Deathmark, not during it.

**Across all seven: Roar of Sacrifice was never pressed.** Not in these games, not in any of the
14. It is in the Hunter's talents in every game.

#### Our Feral beside the others

Per minute alive unless it says otherwise. Crawlordx is 33 minutes; Rastic is 35 minutes below
2150 MMR and 91 above; the seven others are 21 minutes.

| | Crawlordx | Rastic under 2150 | Rastic 2150+ | 7 others at 2100+ |
|---|---|---|---|---|
| Damage onto enemy players | 1,967k | 2,311k | 2,762k | 1,962k |
| Buttons pressed | **38.0** | 47.5 | 50.6 | 46.7 |
| Rake | **4.6** | 7.0 | 7.8 | 5.0 |
| Ferocious Bite | 2.5 | 5.5 | 5.0 | 2.2 |
| Shred | 4.8 | 2.9 | 2.8 | 4.3 |
| Regrowth | **1.4** | 1.7 | 2.9 | 2.8 |
| Skull Bash | **1.2** | 2.5 | 2.8 | 3.7 |
| Wild Charge | **0.7** | 1.5 | 1.7 | 1.6 |
| Cat Form, pressed | **2.3** | 1.0 | 0.9 | 1.7 |
| Healing on himself | **222k** | 341k | 374k | 451k |
| Healing on teammates | **90k** | 171k | 295k | 163k |
| Interrupts landed, per game | **0.8** | 1.3 | 2.5 | 2.7 |
| Medallion, per game | **0.4** | 0.8 | 1.4 | 1.1 |
| Their healer locked out by him, share of the game | **3%** | 15% | 14% | 6% |
| Rake stuns landed, per 10 minutes | 9.0 | 14.5 | 12.8 | 11.3 |
| Damage on the most-hit target, share | 83% | 73% | 63% | 68% |
| Enemies with his Rip on them, average | 0.66 | 0.75 | 0.91 | 1.09 |
| Locked out himself, share of the game | 10% | 13% | 12% | 12% |
| Died, games | **4 of 14** | 0 of 18 | 6 of 28 | 2 of 7 |

What holds against both Rastic and the seven others (the differences that are not one player's
style):

- **He presses about nine fewer buttons a minute** (38 against 47–51) while being locked out no
  more than they are (10% against 12–13%). Time in gaps over 2.5 seconds between presses, not
  counting time locked out, is 14% of the game for him and 9–12% for them.
- **He heals much less:** 222k a minute on himself against 341–451k, and 90k on teammates against
  163–295k. Regrowth on himself is 3.4 a game against 9.5 (Rastic at 2150+) and 8.4 (the others);
  Rastic below 2150 is at 3.3, the same, so the button count alone does not separate them there.
- **He interrupts less:** 0.8 a game against 1.3 (Rastic below 2150) and 2.5–2.7. He takes
  *Savage Momentum* in every game, which takes 10 seconds off Tiger's Fury, Survival Instincts
  and Dash per interrupt. Rastic takes it too and lands up to three times as many.
- **He dies more:** first death in 4 of our 7 losses. Rastic did not die once in 18 games below
  2150, the band our games were in.
- **He presses Cat Form 2.3 times a minute against about 1.** Sorted by what each press did:

| Cat Form presses | Crawlordx (77) | Rastic (119) |
|---|---|---|
| Broke a root or snare (a good press) | 13, 0.38 a minute | 18, 0.14 |
| Straight after Cyclone, breaking nothing | **37 of his 51 Cyclones (73%)** | 60 of 263 (23%) |
| Everything else | 27 | 41 |

  Both have *Fluid Form*: "Shred, Rake, and Skull Bash can be used in any form and shift you into
  Cat Form." After a Cyclone the next Rake does the shifting for free, and a Cat Form press there
  is a global spent on nothing. That is about one a minute.

What is Rastic's and may be the comp or the build, not a gap:

- **Ferocious Bite twice as often, Shred half as often.** He takes *Apex Predator's Craving*
  ("Rip damage has a chance to make your next Ferocious Bite free") in 40 of 46 games, with
  *Rampant Ferocity* and *Blood Spattered*. The seven others bite no more than Crawlordx does.
- **CC on the enemy healer.** Rastic locked their healer out for 14–15% of the game: Maim on the
  healer 8–11 times per 10 minutes and Cyclone 8–10. Crawlordx: Maim 0.9, Cyclone 3.6, with his
  Maim going on the kill target (13.5). In a Jungle the Hunter's trap and the Priest's fear are
  the healer CC, and the seven others sit at 6%, close to him. **But inside our own games it
  tracks the result:** Cyclone on their healer 4.9 per 10 minutes in the wins, 2.0 in the losses.
- **Damage spread over two targets.** Rastic keeps Rip on 0.9 enemies on average at 2150+ and 63%
  of his damage is on one target, against 0.66 and 83%.

Things one side never pressed at all:

- **Heart of the Wild's heal, 1 use in 15 games against 62 in 46.** Rastic's log shows a burst of
  Wild Growth from him in caster form once every 2.0 minutes, with the gaps between them bunched
  at 120–126 seconds. That is Heart of the Wild's 120-second cooldown (the spell data: "perform a
  powerful off-role ability depending on your currently active shapeshift form"); the log writes
  no cast line for it, so the link is **inferred from the timing, not read**. It is 262–274k of
  his healing a minute. Crawlordx has the talent in 12 of 14 games and shows one such burst in 15.
- **Remove Corruption: 0 casts against 72.** Rastic's removed Agony 29 times, Atrophic Poison 26,
  Wound Poison 25, Deadly Poison 22, Crippling Poison 20, **Hex 10**, Curse of the Satyr 8. In our
  14 games our players took Wound Poison 38 times, Deadly Poison 30, Crippling Poison 27, Atrophic
  Poison 11, Kingsbane 5 and Hex 2. Three of our losses were to an Assassination Rogue.
- **Typhoon: all 8 other Ferals took it**, and 5 of 8 took Incapacitating Roar where Crawlordx has
  Mighty Bash. *Nurturing Instinct*, *Rejuvenation* and *Lore of the Grove* are in his build and
  in nobody else's.
- **PvP talents:** Rastic ran Wicked Claws in 46 of 46 (healing reduction from Rake and Rip);
  Crawlordx in 9 of 14, with High Winds in 5 and Freedom of the Herd in 2.

**When he presses defensives** (presses a game | median health when pressed):

| | Crawlordx, wins | Crawlordx, losses | Rastic under 2150 | Rastic 2150+ |
|---|---|---|---|---|
| Regrowth on himself | 4.4, at 70% | **2.4**, at 52% | 3.3, at 82% | 9.5, at 72% |
| Barkskin | 1.0, at 80% | 1.3, at 67% | 0.9, at 69% | 1.7, at 66% |
| Frenzied Regeneration | 0.6, at 61% | 0.6, at 41% | 0.2, at 76% | 0.5, at 22% |
| Survival Instincts | 0.1 | 0.7, at 53% | 0.3, at 54% | 0.5, at 38% |

The medians are close. The deaths above are what differ: two with Survival Instincts unpressed or
pressed at 14%, and two with everything spent a minute early.

**Gear.** Median item level 344, the same as every Feral here.
- Head, shoulders and feet have no enchant. Three of the four Ferals checked slot by slot have
  all three (Badkittylolz, Sufferpoints, Zyhsul); Rastic has none of them either.
- His wrists are a 331 Aspirant piece with no gem; the others wear a 344 piece, three of the four
  with a gem in it.
- Trinkets are the same pair everyone has (Medallion and Insignia of Alacrity).
- **Every other Feral, and Doubletapz, carries self-applied buffs he never has:** *Rune of
  Masterful Cunning* (up 64–76% of the game on them, 0% on him) and *Arcanoweave Insight*
  (36–44%); the Ferals also have *Rune of Lynxlike Reflexes* (17–30%). They do 0.5–0.7% of their
  damage through *Rune of Unleashed Fire*. None of these is in the site's spell data, and the
  log's gear line does not say what grants them. The one enchant that differs is on the rings:
  he has id 7965 on both, and the seven other players whose rings were read (four Ferals, three
  Hunters, Doubletapz among them) have 7969, 8027 or 8023. **A lead, not a finding: worth a look
  in game.**

#### Our Hunter beside the others

| | Doubletapz (33 min) | 8 others at 2050+ (18 min) |
|---|---|---|
| Damage onto enemy players, per minute | **2,292k** | 2,050k |
| Their healer locked out by him, share of the game | **15%** | 12% |
| Freezing Trap on their healer, per 10 minutes (average length) | **12.8 (4.2s)** | 9.1 (3.0s) |
| His offensive cooldowns followed by 2s+ of lockout on their healer | **63%** | 42% |
| Interrupts landed, per game | 1.1 | 1.4 |
| Buttons pressed, per minute | 33.3 | 39.2 |
| Time in gaps over 2.5s, not locked out | 23% | 15% |
| Kill Command, per minute | 7.7 | 9.1 |
| Cobra Shot, per minute | 6.5 | 3.0 |
| Counter Shot, per game (first press, median) | 1.3 (at 74s) | 2.5 (at 26s) |
| Disengage, per game | 0.9 | 2.3 |
| Concussive Shot | 1 cast in 14 games | 1.3 a minute |
| **Roar of Sacrifice, per game** | **0** | 0.9 |
| Master's Call, per game | 0.5 | about 1.3 |
| Exhilaration, per game (median health) | 0.6 (45%) | 0.9 (42%) |
| Aspect of the Turtle, per game (median health) | 0.3 (**16%**; 2% in the losses) | 0.5 (26%) |
| Survival of the Fittest, per game | 1.1 | 1.4 |
| Medallion, per game | 0.4 | 0.6 |
| Healing on himself, per minute | 97k | 180k |

- **His damage and his control are better than the higher-MMR Hunters here.** More damage, more
  and longer traps on the healer, and his Bestial Wrath lands with their healer locked out more
  often. His interrupts are good ones: Cyclone 6 times, Polymorph 2, Fear 2.
- **What he does not press is the defensive and utility half of the kit.**
  - **Roar of Sacrifice, never.** The others pressed it 9 times in 10 games: 5 on their other DPS
    (at 0–19%, 20–39% twice, 60–79% and 80–99% health) and 4 on themselves. Our Feral was the
    first to die in four games.
  - **His own defensives come late or not at all.** Aspect of the Turtle four times in 14 games,
    at a median 16% health. Two of his three deaths are above: every button unpressed at 18:42,
    Turtle at 2% at 18:49.
  - **Counter Shot half as often and nearly a minute later** into the game.
  - **Master's Call went on himself 6 times and on Jmjay once**, never on the Feral. (A Feral
    breaks roots by shifting, so that may be right; it is here because it is measurable.)
- **He fills with Cobra Shot where they press something else.** 6.5 a minute against 3.0, with
  Kill Command 7.7 against 9.1. Cobra Shot is 5% of his damage.
- **Gear:** his chest is item level 310 where every other Hunter's is 344, his boots are 331, and
  head, shoulders and feet have no enchant (most of the others have two or three of those).
- **Build:** all 8 others took *Bloody Frenzy*; he did not. 4 of 8 took *Kindred Beasts* where he
  has *Chimaeral Sting*; Joonixo, the highest-rated, runs his exact three PvP talents.
- **Tranquilizing Shot is not a lead.** He never pressed it and the others did about once a
  minute, but what theirs removed was mostly Mark of the Wild, Power Word: Fortitude and Arcane
  Intellect.

#### What to change, in the order the evidence supports it

1. **Both DPS: press the big defensive when their cooldowns go out, not at the bottom.** Four of
   the seven losses were at our own MMR or below, and each is a DPS dying with buttons unpressed
   or pressed at 2–14% health. Survival Instincts at the Combustion (18:08) and after the Kidney
   Shot (18:18); Medallion into Turtle in the Mighty Bash (18:42). This is the largest and the
   most certain.
2. **Hunter: Roar of Sacrifice on the Feral.** Zero uses, and the Feral is the first death in
   four games.
3. **Go when their healer is locked out and ours is free.** Our burst landed on a locked healer
   in 60% of goes in the wins and 28% in the losses; our healer was locked as our cooldowns went
   in 61% of goes in the losses. The Hunter already lines his Bestial Wrath up with the trap
   (63%). A go pressed into our own healer's CC is a go to delay by a few seconds.
4. **Feral: more globals, and the right ones.** Drop the Cat Form press after Cyclone (about one
   a minute), and spend the space on Regrowth, Skull Bash and Rake. He is nine buttons a minute
   behind every other Feral in the archive without being locked out more.
5. **Feral: Cyclone their healer.** 4.9 per 10 minutes in our wins, 2.0 in our losses, and it is
   in 31% of the goes that killed against 13% of those that did not.
6. **Feral: Heart of the Wild and Remove Corruption.** One use and zero uses, against a Feral who
   presses them every two minutes and 1.6 times a game.
7. **Gear, both.** The Feral: three missing enchants, a lower wrist piece, and the rune buffs
   (start with the ring enchant). The Hunter: a 310 chest and the same three enchants.
8. **Hunter: Counter Shot and Disengage earlier and more often,** in place of some Cobra Shots.

#### What this cannot say

- **One session.** 14 games, 7 losses, 7 deaths. Every per-game pattern here is a lead.
- **Rastic is one player in a different comp**, with a Mage doing part of the control. Where he
  and the seven others disagree (Ferocious Bite, healer CC), the difference is his, not the
  spec's. The seven others are one game each.
- **The Hunter comparison is 17.6 minutes of players who mostly lost.** It shows what they press,
  and Doubletapz out-damages and out-controls them.
- **Whether a defensive was available is not known.** Cooldowns are the data's base values
  (rule 34), so "unpressed" is stated only where the button was never pressed in the game, or not
  for longer than its base cooldown.
- **The log cannot see** positioning, line of sight, a call, or who the team meant to kill.
  "Buttons a minute" counts presses, not whether they were the right ones.
- **Health is read from damage and heal events on the player**, so it is a second or so stale
  when nothing is hitting them.

#### Follow-up (2026-10-01): how Rastic's Feral spends, and what his build trades for it

Chriso's read after the first pass: Rastic presses more, gets more out of the instant Regrowths
that finishers give, builds towards free Ferocious Bites rather than Shred and Moonfire, Rakes
and stuns more, and decurses whatever he can. Is that what the log shows? And what happens
inside Incarnation, and inside a go, for him and for the high-rated Ferals Skylake's teams played?
Measured with `tools/match-review/feralread.php`. The samples are the same as above: Crawlordx 14
games, Rastic 18 below 2150 and 28 above, and the seven others one game each.

**The build: Rastic drops every talent that makes Shred and Moonfire hit harder, and takes the
three that feed Ferocious Bite.** Talents in Crawlordx's build in all 14 games and in none (or
few) of Rastic's 46, and the reverse. The text is the site's spell data:

| Only Crawlordx | What it does | Only Rastic | What it does |
|---|---|---|---|
| Moment of Clarity | Omen of Clarity procs 30% more often, stacks, and the next **Shred** does 15% more | Apex Predator's Craving (40 of 46) | Rip damage can make the next **Ferocious Bite free and deal maximum damage** |
| Merciless Claws | **Shred** does 25% more to a bleeding target | Rampant Ferocity | **Ferocious Bite** also hits everything nearby, and spending extra energy on it adds up to 100% |
| Lore of the Grove (12 of 14) | **Moonfire** does 10% more | Blood Spattered | **Ferocious Bite** does 8% more for each enemy carrying his Rip, up to 6 |
| Nurturing Instinct | magical damage and healing 6% more | Primal Wrath (15 of 46) | a finisher that puts Rip on everyone within 10 yards |
| Veinripper (Rastic 6 of 46) | Rip and Rake last 25% longer | Innervate (25 of 46) | mana for the healer |
| Mighty Bash, Rejuvenation | | Incapacitating Roar, Typhoon, Forestwalk | |

So "Shred and Moonfire more" is not a habit on top of the same build; it is what the build
rewards. The question is which build is better, and the log can only compare what each produced:

**Damage per press and per 100 energy spent** (a bleed's number includes every tick of it):

| | Crawlordx | Rastic under 2150 | Rastic 2150+ | 7 others |
|---|---|---|---|---|
| Rip | 152k \| 764k | 187k \| 937k | 212k \| 1,060k | 143k \| 718k |
| Rake | 63k \| **183k** | 49k \| 142k | 57k \| 165k | 59k \| 169k |
| Ferocious Bite | 42k \| 170k | 47k \| 229k | 53k \| **286k** | 46k \| 188k |
| Shred | 29k \| 123k | 26k \| 110k | 29k \| 118k | 22k \| 99k |
| Moonfire | 26k \| **89k** | 23k \| 78k | 29k \| 98k | 22k \| 77k |

- **For everyone, Moonfire and Shred are the least damage per energy**, Rake is about 1.5 times
  Shred and twice Moonfire, and Rip is by far the most. Crawlordx's Shred talents buy him about
  the same Shred as everyone else's (29k a press, the same as Rastic at 2150+). **Chriso's "the
  Shreds don't add damage" is right in that sense:** each energy spent on Shred or Moonfire is
  the worst trade on the bar. They are still the builders that reach a target Rake cannot
  (Moonfire at range), which the log cannot score.
- **Rastic's Rip and Bite hit harder** (Rip 187–212k a press against 152k; Bite 229–286k per 100
  energy against 170k). That is Blood Spattered and Apex on the Bite, and Rip on more targets.

**Combo points and free Bites.** Everyone spends five points on a finisher 79–97% of the time,
Crawlordx included (Rip 89%, Bite 79%). The difference is how many finishers there are:

| | Crawlordx | Rastic under 2150 | Rastic 2150+ | 7 others |
|---|---|---|---|---|
| Ferocious Bite pressed free | 0 of 84 | 32 of 191 | 116 of 457 | 1 of 47 |
| Predatory Swiftness procs, per minute | 2.8 | 3.1 | 3.4 | 4.4 |
| ... spent / let expire | 84% / 15% | 88% / 11% | 92% / 7% | 88% / 11% |
| ... spent on Regrowth | 56% | 58% | 67% | 80% |
| Sudden Ambush, spent on Rake / Shred | **48% / 50%** | 63% / 30% | 60% / 29% | 38% / 52% |

- **The instant Regrowth is Predatory Swiftness, not Omen of Clarity.** "Your finishing moves
  have a 100% chance per combo point to make your next Regrowth or Entangling Roots instant, free,
  and castable in all forms." Omen of Clarity is Clearcasting, a free Shred off auto attacks.
- **Rastic gets more instant Regrowths because he presses more finishers**, and the free Bites are
  a quarter of his Bites at 2150+. He also lets fewer expire (7% against 15%) and puts more into
  Regrowth rather than Entangling Roots (67% against 56%).
- **Sudden Ambush** ("finishing moves ... make your next Rake, Shred or Swipe do 50% more and
  critically strike") goes into Rake about twice as often as Shred for Rastic; Crawlordx splits it
  evenly.

**Rake stuns: the same rate, more Rakes.** A Rake stuns about as often per press for everyone
(Crawlordx 20%, Rastic 17–21%, others 25%). Rastic lands more stuns because he presses 7.0–7.8
Rakes a minute against 4.6.

**Tiger's Fury: Crawlordx already snapshots his bleeds as well as anyone.** Rip pressed inside
Tiger's Fury: 60% against Rastic's 60–69% and the others' 46%. Rake: 59% against 45–50% and 36%.
That is not the difference. **What follows Tiger's Fury is:**

| Presses in the 8s after Tiger's Fury | Crawlordx | Rastic under 2150 | Rastic 2150+ | 7 others |
|---|---|---|---|---|
| Rake | 1.26 | 0.92 | 0.98 | 0.61 |
| Shred | 0.78 | 0.50 | 0.57 | 0.56 |
| Ferocious Bite | **0.19** | **1.03** | **0.89** | 0.27 |
| Control of any kind (Cyclone, Maim, Skull Bash, Roar, Roots…) | **0.60** | **1.03** | **1.10** | 0.63 |

Rastic Bites and controls after Tiger's Fury where Crawlordx Rakes and Shreds. **But the seven
others look like Crawlordx here, not Rastic**, so this is Rastic's build (free Bites) and comp,
not something every high-rated Feral does.

**Inside Incarnation (Berserk).** About 23–24 seconds a window for both.

| Per window | Crawlordx (26) | Rastic under 2150 (28) | Rastic 2150+ (67) |
|---|---|---|---|
| Own damage per second inside / outside | 42k / 28k | 53k / 32k | 58k / 40k |
| Ferocious Bite | 1.8 | 3.4 | 3.6 |
| Rake | 2.1 | 2.9 | 3.6 |
| Shred | 1.7 | 1.0 | 0.7 |
| Prowl | 1.0 | 1.3 | 1.6 |
| Feral Frenzy | 0.8 | 0.4 | 0.4 |
| Skull Bash | 0.4 | 0.8 | 0.9 |

- **How the window opens is the clearest difference.** Incarnation lets you Prowl once in combat
  (its second aura, 252071, is exactly that flag), and a Rake from stealth stuns. Rastic opens
  with **Prowl then Rake in 79 of 92 windows (86%)**. Crawlordx does in 15 of 26 (58%), and opens
  with **Feral Frenzy in 9 of 26** (Rastic: 3 of 92), then Rip or Maim.
- The seven others are mostly not in this: three of them play the other hero tree (Ravage), and
  their Incarnation count per window reads 0.1.

**Inside the team's goes** (offensive casts chained 10s apart, window to 15s after the last; the
`--strict` go):

| | Crawlordx | Rastic under 2150 | Rastic 2150+ | 7 others |
|---|---|---|---|---|
| Damage per second inside goes | 38k | 43k | 49k | 35k |
| Share of own damage inside goes | 75% | 77% | 73% | 70% |
| Rip, share of go damage | 17% | 21% | 21% | 23% |
| Rake | 15% | 14% | 16% | 15% |
| Ferocious Bite | 6% | 11% | 10% | 4% |
| Shred | **7%** | 3% | 3% | 4% |
| Moonfire | 7% | 6% | 6% | 6% |

The seven, one game each (Shred, Moonfire, Rake, Bite, Rip as shares of their go damage):
Meowzetzan 9/6/11/10/16, Sufferpoints 0/5/9/9/18, Wokcats 2/8/15/7/24, **Zyhsul (5x Glad,
2309) 4/0/21/2/15**, Hawtpants 1/7/13/0/27, Gdru 5/6/17/10/21, Badkittylolz (12x Glad) 9/8/14/7/21.

- **Chriso's read holds for Shred:** in goes it is 0–9% of the high-rated Ferals' damage (4%
  pooled) against 7% of his. **Not for Moonfire:** 6–8% for five of the seven, the same as his.
  Zyhsul pressed no Moonfire at all and put 21% into Rake.
- Crawlordx does more damage per second in goes (38k) than the seven (35k), and less than Rastic.

**What this adds to "What to change":**
- **Open Incarnation with Prowl → Rake**, not Feral Frenzy. 86% of Rastic's windows against 58%.
- **Spend Sudden Ambush on Rake**, not Shred. It makes the next one do 50% more and crit, and Rake
  is the better trade per energy anyway.
- **Let fewer Predatory Swiftness procs expire** (15% against 7%), and put them into Regrowth.
- **The talent swap is a real option, not a proven one.** Rastic's Bite build out-damages the
  Shred build here, but he is one player in another comp, and the seven others, most of whom take
  neither Apex nor Blood Spattered, do no more damage than Crawlordx. Worth a session of games on
  it, measured the same way.

### 1 Oct: Skylake with LFG partners, against the same Skylake with a fixed team

Chriso's question: was the 1–7 LFG session today his own play, after going 28–18 with a fixed
Feral/Mage team on 30 Sep? Measured with `sessionread.php` and `specread.php`. The full write-up,
with the question as he asked it, is `docs/reviews/2026-10-01-disc-lfg-session.md`.

| | Fixed team, 30 Sep | LFG, 30 Sep | LFG, 1 Oct |
|---|---|---|---|
| 3v3 games | 46, 28–18 | 14, 5–9 | 8, 1–7 |
| Enemy Gladiator seasons per game | 7.9 | 4.4 | 14.4 |
| Their goes that killed one of us | 19% | 42% | 56% |
| Skylake died first | 0 | 0 | 0 |
| Last Pain Suppression before our first death, median | 43s | 44s | 38s |
| Medallion available at our first death | 28% | 0% | 29% |

- **His own measures barely move between groups** (lockout a minute, casts a minute, idle time,
  Pain Suppression and Medallion timing). The team and the opponents moved: today's enemies had
  nearly twice the Gladiator seasons.
- **Two measures did move, and both fit "I didn't know when the go was":** his offensive cooldowns
  had a teammate's within 6s 60% of the time, against 74% with the fixed team; he was locked out
  within a second of pressing one 40% of the time, against 22%. His median first Medallion was at 37
  seconds, against 78.
- **The presses were needed; the stretch after them is where games went.** Checked with
  `warrant.php` after Chriso objected that holding them would have lost the teammate sooner:
  - In today's losses, 11 of his 13 defensive presses went on a teammate at 35% or below, or with
    5s or less to live, inside an enemy go. None was weak, against 5 of 44 in the fixed team's
    losses.
  - His Medallions mostly broke Freezing Trap, Fear, Sleep Walk or Strangulate during their go.

  With both Pain Suppression charges spent (modelled as two charges, 180s each, an upper bound),
  enemy goes killed one of us 25% of the time with the fixed team, 67% with LFG on 30 Sep, and 86%
  (6 of 7) today. With a charge left: 16%, 33%, 36%. Today's teams also kept attacking almost as
  often in that stretch (1.3 goes a minute against 1.5). The first version of the review said to hold
  the second charge; the data says play the no-charge stretch for time instead. A lead on 8 games.

### 2 Oct: Disc talents, the Shadow Word: Pain build against the Radiance build

Chriso's hypothesis: the Shadow Word: Pain talents give a lot more healing than the Radiance ones,
because he spends fewer globals keeping someone healthy. He swapped mid-lobby on 2 Oct (shuffle
`c8c8db8b…`):
- **Rounds 1–3 (lost, lost, lost):** Harsh Discipline ×2 and Enduring Luminescence.
- **Round 4 (lost):** switched to Encroaching Shadows, Revel in Darkness and Shield Discipline.
- **Rounds 5–6 (won, won):** also Improved Purify and Inner Focus, in place of Mind Control and Weal
  and Woe.

His three Radiance-build 3v3 games were the 15:39–15:47 losses on 1 Oct. Measured with
`specread.php` (new talent filter) and the lobby's stored per-round output. Same six players in every
round, re-dealt.

| Per minute alive, same lobby | Rounds 1–3, Radiance | Rounds 4–6, Shadow Word: Pain |
|---|---|---|
| Atonement healing | 1,154k | **1,682k (+46%)** |
| Penance healing | 730k | 649k |
| Shadow Word: Pain damage | 163k | **285k (+75%)**, on 1.55 enemies on average against 0.91 |
| His damage onto enemies | 631k | **899k (+42%)** |
| Casts | 25.2 | 24.8 |
| Direct-heal casts (Shield, Radiance, Shadow Mend, Plea) | 7.4 | 8.0 |
| His heal + absorb, against his team's damage taken | 110% | 112% |
| Enemy team's damage taken, per second | 92.9k | **118.3k (+27%)** |

- **"A lot more healing": not in total.** Atonement healing rose by nearly half, but Penance healing
  and shield absorbs fell. Heal plus absorb against the damage his team took is the same (110% and
  112%).
- **"Fewer globals keeping someone healthy": not supported.** He pressed as many buttons, and slightly
  more direct heals.
- **What did change is damage.** The same globals did 42% more damage and still healed through
  Atonement: 59% of his healing came from Atonement, against 47%. His side's enemies took 27% more
  damage a second, and their healer healed more. That fits the Brain's hypothesis that damage spends
  the other healer's globals (`{#damage}`).
- **Not across all players.** Other Disc Priests in the archive show no healing gain from the same
  talents: in 3v3, Atonement 909k with them against 1,073k without. His Shadow Word: Pain covers more
  enemies than theirs (1.24 against 0.95), so the gain is in how he plays the build, not in the build
  alone.
- **Results cannot settle it.** 2–1 against 0–3 inside the lobby with teammates re-dealt each round,
  and 0–6 over his six Radiance games in all. A lead.

### 2 Oct: every game pooled, and the cooldown ledger

Chriso's questions: which patterns hold across all of his games, not one session? And does
"drain, then kill" get to the root, or is the root that defensives are tied to cooldown timers?
A 60s Barkskin fairly answers a 60s Kingsbane. Pain Suppression and Frenzied Regeneration may
still be needed if the healer is CC'd and no peel goes out, and then the team has nothing for the
next go. The method, and why it is written down, is in `match-review-operations.md`, *Reading
defensives: three questions, in order*.

**The sample.** Every stored game of user 2: 246, by logging character Skylake (Disc, 102 3v3 and
18 shuffle rounds), Dijonhoney (Holy Paladin, 57), Crawlordx (Feral, 32) and Env (Assassination,
30). That is 684 enemy goes. Read with `patternread.php` and `cdledger.php`.

#### What holds over every game, and what does not

- **Drain, then kill holds in all seven character-and-bracket groups.** Our goes started with 2+
  of their defensives down led to a kill in 29–60% of cases, against 11–22% with 0–1 down
  (Skylake 3v3 29% against 20%, 325 goes; Crawlordx 3v3 33% against 11%).
- **Burst landing on their healer's CC does not hold in the largest sample.** Over Skylake's 325
  3v3 goes, 26% killed with their healer locked out 2s+ at our peak, and 26% with them free. It
  held in the 26 Sep and Jungle sessions and in shuffle (43% against 28%). **Unsettled**, not a
  finding.
- **"No go converted in the losses" is close to true by definition.** A loss is our team dying.
  Compare goes by condition across all goes instead.
- **Skylake is locked out more in losses:** 12.4s a minute against 8.4s in 3v3, and 11.2s against
  6.6s in shuffle. He was locked out, or within a second of it, at our first death in 27 of 49 3v3
  losses. Opponent strength is a confound.
- **"Dying with the Medallion available" was listed as a DPS habit on 2 Oct without asking whether
  a lockout was on the player at the death.** Unchecked; see the operations file's question 2.

#### The cooldown ledger

Every cooldown resolved from the player's own talents (`--cds`). "Big" means a 90s or longer
cooldown. Whether their go killed one of us:

| Our big answers down as their go started | 3v3 | Shuffle |
|---|---|---|
| none | **12%** (27 of 221) | 14% (12 of 86) |
| one | 22% (19 of 86) | 20% (5 of 25) |
| two or more | **38%** (57 of 149) | 35% (31 of 88) |

**It is not just that late goes kill more.** Inside the same stretch of game time (3v3):

| Their go started | none down | one down | two or more down |
|---|---|---|---|
| before 60s | 15% (26/179) | 41% (9/22) | 35% (6/17) |
| 60–120s | **3%** (1/36) | 17% (5/29) | **43%** (23/53) |
| after 120s | 0% (0/6) | 14% (5/35) | 35% (28/79) |

**Going while our own answers are down.** At each of our goes, how many of our big answers were
down, then whether their next go killed:

| When we went | 3v3 | Shuffle |
|---|---|---|
| none down | 14% (32 of 225) | 23% (19 of 82) |
| one down | 17% (9 of 53) | 32% (6 of 19) |
| two or more down | **43%** (52 of 121) | 37% (22 of 59) |

**The trade, press by press.** Each of our defensives pressed inside their go, against the
offensives that drew it. "Fair" means it came back within 15s of their first offensive of that go.

| | Presses (3v3) | Still down at their next go | That go killed |
|---|---|---|---|
| fair | 215 | 24% | 32% (12 of 38) |
| expensive | 554 | **95%** | 39% (150 of 384) |

What that says about Chriso's framing:

- **Cadence is real.** An expensive answer is almost always still down when their next go
  arrives (95% against 24%). That is the Barkskin-against-Kingsbane point, measured.
- **But one expensive press is not what loses.** The next go killed about as often after a fair
  press as after an expensive one (32% against 39%). The line is crossed at the **second** big
  answer: one down is 17–22%, two or more is 35–43%.
- **So the root is the team's state at the next exchange, not the press.** Judge a single press
  by whether it was needed (`warrant.php`; the healer's were, on 26 Sep and 1 Oct). Judge the
  team by what it holds when an exchange starts. **With two or more big answers down, don't go;
  play for time until they are back.** That is the 1 Oct conclusion. Over all the games, our
  going with two or more down was followed by their go killing us 43% of the time in 3v3, against
  14% with none down.
- **Whether waiting would have helped is reasoning, not measurement.** The ledger shows that the
  state predicts the kill. It cannot show that holding our go would have moved theirs.

**What it cannot see:** which player a defensive went on (coverage is the team's), charges that
recharge one at a time (coverage overstated), and default-build answers a player did not take
(coverage overstated). The full list is in the operations file.

### 2 Oct: two of Chriso's hypotheses, tested

**"I dispel less because of the pressure on us, or because I'm busy fearing."** Not supported.
Read with `dispelread.php`, 3v3 since 20 Sep. "Something to dispel" is a debuff that Purify itself
was seen removing somewhere in the archive (71 of them). The spell data has no dispel type.

| Disc Priest | Games | Seconds a minute with something to dispel | Removed per minute of that |
|---|---|---|---|
| Skylake | 102 | 105 | **0.34** |
| Originull (8× Gladiator, RMP) | 15 | 119 | 1.34 |
| Jmjay | 14 | 108 | 0.93 |
| All other Disc Priests | 66 | 117 | 1.30 |

- **His team has as much to dispel as anyone's.** He removes about a quarter as much of it.
- **Under pressure he dispels more, not less:** 0.43 a minute in his high team-damage games
  against 0.24, 0.41 when he was locked out most against 0.26, and 0.36 when he feared most
  against 0.29. So the gap is a priority, not a side effect of pressure.
- **It is not crowd control.** Every Disc Priest rarely removes CC from a teammate. Counting a
  teammate in purifiable lockout or root for 2s+, with the priest free as it landed, the removal
  rate is Skylake 6%, Originull 4%, the rest 8%. The gap is damage over time and debuffs:
  Shadow Word: Pain, Searing Light, Judgment, Moonfire, Chilled.
- **Whether those dispels are worth a global is judgement the log cannot settle.** A Purify
  costs a Penance. Unstable Affliction punishes the dispeller. Purify's 8s cooldown is not
  modelled here.

**"Against comps that force nearly all our defensives in the first go, we shouldn't rush in."**
The first half holds. The second is not supported in these games: it reads the other way, as a
lead. From the stored analysis of Skylake's 102 3v3 games:

- **Their first go forcing 4+ of our defensives (Medallion aside): won 10 of 27 (37%),** against
  53–62% when it forced fewer.
- **Against comps averaging 3+ forced:** we went first and won 12 of 21 (57%); they went first and
  we won 7 of 20 (35%). Against every other comp, going first made no difference (56% against
  55%). Taking the initiative against a burst-heavy comp makes them answer instead of open. That
  fits drain from both sides, but 41 games is a lead, and going first may also mean the opening
  was already good.
- **Our first CC:** on their kill target, won 24 of 36 (67%); on their healer, 16 of 36 (44%);
  cross-CC, 13 of 30 (43%). Against a Resto Druid, opening CC on the Druid won 1 of 6, and on
  anyone else 8 of 17.
- **Comp-specific advice is thin from one player's games.** In 102 games, only three enemy comps
  were met three or more times. Advice per comp needs other players' games against that comp,
  and the spell data's model of it, alongside the player's own.

### 3 Oct: Skylake with Doubletapz and a Balance Druid, two fast losses

Chriso's read: Doubletapz did not have the reaction time to stop the enemy's crowd control at
this rating. Both games were lost to the first death, which came about 15 seconds into the
enemy's first go:

| Game | Them | First death | Crowd control on Skylake |
|---|---|---|---|
| 11:50, 41s, MMR 2105 / 2101 | Holy Priest, Unholy DK (7× Glad), Arms (10 Gladiator seasons between them) | Balance Druid at 22.8s, to Execute | Intimidating Shout, Holy Word: Chastise, Storm Bolt with Psychic Scream, Strangulate: **all instant** |
| 11:53, 30s, MMR 2071 / 2088 | Holy Priest, Frost Mage (2× Glad 2626), Assassination | Balance Druid at 24.4s, to a Kidney Shot go | Cheap Shot, **Polymorph (1.1s cast)**, Chastise, Psychic Scream |

- **In 11:50 there was nothing to kick.** Every lockout on the healer was instant. Skylake used
  the Medallion at 14.1s on the Intimidating Shout, was then held from 15.8s to 24.7s, and tried
  Pain Suppression four times at 20.2–20.9s ("can't do that while fleeing / silenced"). The
  Druid died at 22.8s.
- **In 11:53 the one kickable crowd control on Skylake went through with Counter Shot ready.**
  The Polymorph began at 13.6s, and at 13.7s Doubletapz pressed Intimidation on their healer.
- **Every control Doubletapz landed in both games went on their healer, inside the enemy's go**
  (Intimidation at 11.2s and 13.7s, Freezing Trap at 15.8s; a trap at 12.7s in 11:50 caught no
  one). Bestial Wrath went out at 15.5s in 11:50, with their Bladestorm, Avatar and Army running.
  He answered their go with ours. Roar of Sacrifice was not pressed, as in all 14 games of 30 Sep.

**Reaction time is not what the log shows** (`kickread.php`, 19 games since 1 Sep, against 66
games of other Hunters in the archive): with an enemy cast-time crowd control going, Doubletapz
alive, free and with Counter Shot ready, **he kicked 11% of the chances, the same as every other
Hunter (11%), and his kicks landed a median 1.0s after the cast began (others 1.1s).** He presses
Counter Shot 1.1 times a game against their 2.0, so it is ready more often and used less often.
The difference is what he spends his globals on during the enemy's go, not how fast he presses.
Two games are a lead; range and line of sight are not in the log.

#### The Pain Suppression that was held because of the overlap rule (11:50)

Chriso: "I trinketed the fear, and normally I'd Pain Suppression, but I saw Barkskin go out on the
Druid, and the analysis keeps pointing at overlapping defensives, so I didn't. I got chain-CC'd and
he died. I changed my play because of the data, and that lost us the game."

The log agrees, second by second:

| Time | Druid's health (damage that second) | What happened |
|---|---|---|
| 13.9s | 61% | Barkskin on the Druid |
| 14.1s | 61% (0) | Skylake's Medallion breaks Intimidating Shout. **He is free until 15.8s** |
| 15.8s | 58% | Holy Word: Chastise on Skylake |
| 16.2s | 50% (86k) | Colossus Smash on the Druid |
| 17.5–20.7s | 50% → 36% (96–160k a second) | Storm Bolt and Psychic Scream, then Strangulate on Skylake until 24.7s. He tries Pain Suppression four times, 20.2–20.9s: "can't do that while fleeing", then "silenced" |
| 21–22.8s | 11% → dead (270k, 152k) | Execute |

**The overlap rule was wrong here, and so is the narrower one proposed after *Was the trinket
warranted*.** At 14.1s the Druid was at 61% with Barkskin up and almost nothing coming in, so
both "two defensives at once" and "score an overlap only when the target was not in danger" call
a Pain Suppression then a waste. What made it right was what was **about** to happen: the
enemy's go was running (Bladestorm, Avatar, Army from 7.2s) and they still held every lockout
for the healer. A press that has to go out before the healer is locked out is decided by the
**healer's lockout risk**, not by the target's health. None of the rules measures that yet.

**And the product taught the wrong lesson.** The overlap item is still a fault in
`MatchAnalysisService::faults()`, so it shows on the game card's "Worth a look" and in the loss
split, and *Overlapping defensives* above still reads "stacking doubled in the losses" without the
correction under it. Chriso acted on it, against what `warrant.php` had already shown about his
presses.

#### Crawlordx with Doubletapz and LFG healers, four losses (3 Oct)

| Game | MMR (us / them) | Their Gladiator seasons (ours: 1) | First death |
|---|---|---|---|
| 11:59, 45s | 1951 / 2012 | 1 | Doubletapz at 31s, to Bladestorm. Survival of the Fittest 1s before |
| 12:01, 115s | 1930 / 2116 | 4 | Doubletapz at 106s, to Execute |
| 12:14, 152s | 1968 / 2154 | 5 | Doubletapz at 134s, our healer locked. Survival of the Fittest 4s before |
| 12:18, 33s | 1960 / 1962 | 7 | Doubletapz at 22s, to The Hunt |

The enemy MMR was 61–186 higher in three of the four. Doubletapz was the first death in all
four, and **in three of them none of our team's big defensives were down when the killing go
started**: the go killed through a full set of answers, with his own defensive late or not
pressed. That is the 30 Sep pattern again (*Our Hunter beside the others*). The healer was a
different LFG player in two of the four.

### 4 Oct: Skylake's dispels moved, and the dispel measure was counting shapeshifts

**After the 2 Oct read, Skylake's Purifies on teammates rose session by session.** Counted from
the stored dispel rows, Purify onto someone else only:

| Session | Games | Purify on a teammate | Per game |
|---|---|---|---|
| 25 Sep | 14 | 17 | 1.2 |
| 26 Sep | 15 | 10 | 0.7 |
| 30 Sep | 66 | 67 | 1.0 |
| 1 Oct | 11 | 13 | 1.2 |
| 2 Oct | 17 | 37 | 2.2 |
| 3 Oct | 8 | 30 | **3.75** |

Per minute of something to dispel, 3 Oct was 1.46 against 0.27–0.56 in his five earlier sessions
of 3+ games: outside his range, not noise. The same session's idle time was his worst (28%
against 17–24%). A change he made after the read, then, and one with a visible cost; whether it
won games is not yet answerable on 8 of them (4-4).

**The Improve page's dispel count included removals that are not dispels.** The log records a
SPELL_DISPEL for any removal: Phantasm stripping a slow when a Priest fades, a Druid's shapeshift
breaking a root, Blessing of Freedom, Cleanse the Weak's extra removals.
- Crawlordx: **115 of 133** "dispels" were Cat or Bear Form. Remove Corruption on a teammate: 8
  in 36 games.
- Skylake: 36 of 245 were Phantasm.
- The "something to dispel" lists took in what only those remove: 16 of the 91 debuffs on the
  Disc Priest list (Crippling Poison, Consecration, Chains of Ice) had only ever been removed by
  Phantasm.

Now a dispel is a spell whose spell data carries a `Dispel (38)` effect (Purify, Cleanse,
Remove Corruption, Nature's Cure, Detox, Purify Spirit, Cleanse Spirit). None of the impostors
has one. Every number above moved: Skylake 0.43 → 0.39 against 1.24 → 1.16; Dijonhoney from
"level" to **behind** (1.03 against 1.59), which made dispels his focus; Crawlordx from about
two a minute to 0.62 against 2.19. `dispelread.php` was already right: it decides what is
purifiable from Purify alone.

### One hit, explained

19:51 on 26 Sep, Unholy DK → Retribution Paladin, `Dread Plague (Erupt)`, **538,666**, overkill
27,854, **critical** (369,784 base × 1.46). Every other Dread Plague event in that game did
6,000–17,000. He had the full Unholy chain up for fifteen seconds — Army of the Dead, Dark
Transformation feeding Commander of the Dead, Ghoulish Frenzy and Gift of the San'layn, with
Essence of the Blood Queen stacking off Vampiric Strikes right up to the moment it fired.

**The Dread Plague descriptions do not explain it; his PvP talent does.** All three Dread Plague
ids (1240996, 1241171, 1242564) carry one copied description ("explodes when the host dies"), and
nothing died first. The trigger is a dispel: at 19:51:44.006 the Paladin cast **Cleanse Toxins on
himself, removing Dread Plague**, and the Erupt landed 1ms later. The DK's PvP talents are Spellwarden,
Necrotic Wounds and **Life and Death** (288855): *"Dread Plague deals 200% of its remaining damage
to the target when dispelled."*

Checked across the whole archive (2026-09-27), dispels of Dread Plague from a DK with Life and
Death:

- **Self-dispel → the Erupt hits the host, who is also the dispeller.** 8 of 8, within 0–4ms
  (Cleanse Toxins, Emergency Salve, Mending Bandage).
- **Someone else dispels → the Erupt hits the dispeller, not the host.** 27 of 28. Cleanse, Cleanse
  the Weak, Detox and Blessing of Sacrifice put it on the Paladin or Monk who cast them, 0–23ms
  later. The tooltip's "the target" means whoever removed it. Restoral, a group-wide cleanse, is
  the exception seen: in one game the Erupt hit the host instead, in another nothing landed.
- **DKs without Life and Death: no Erupt after a dispel**, 0 of 44.

**The size is not an outlier for this DK.** An Emergency Salve at 20:10 took 56,665 + 509,982
absorbed = 566k, non-crit. The magnitude is only partly accounted for: a 24s plague ticking ~9k
every ~1.46s is ~150k in total, so 200% of what was left 6s in is ~200–250k base against 369,784
observed. The 1.5x gap is unexplained — plausibly the DK's damage buffs applying again to the burst,
or duration extensions (Death Coil adds 1s each) — **[HYP], not measured.**

---

