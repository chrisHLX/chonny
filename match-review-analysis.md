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

#### The review table

| Game | Result | MMR (us / them) | Enemy team (healer first; Gladiator seasons, or best rank if none; highest 3v3) | Our goes (followed by a kill) | Defensives spent before the first death (us / them) | First death | Our healer at that death |
|---|---|---|---|---|---|---|---|
| 19:26 | W | 1930 / 1924 | Mistweaver 5x Glad 2129 · Elemental 1x Glad 2092 · Arms 2x Glad 1956 | 2 (2) | 2 / 6 | theirs: Dianpaoer (Elemental) to Touch of Death 748,186 | - |
| 19:29 | W | 1961 / 1986 | Preservation 3x Glad 2461 · Fire Mage 1x Glad 1962 · Windwalker Duelist 2369 | 3 (2) | 9 / 12 | theirs: Ffz (Fire) to Strike of the Windlord 48,583 | - |
| 19:35 | W | 1999 / 2047 | Holy Paladin Elite 2216 · Affliction Legend (Shuffle) 2067 · Frost Mage Duelist 1997 | 4 (2) | 10 / 11 | theirs: Squivv (Affliction) to Melee 646 | - |
| 19:43 | W | 2041 / 1968 | Holy Paladin Elite 2216 · Affliction Legend (Shuffle) 2067 · Frost Mage Duelist 1997 | 3 (1) | 9 / 13 | theirs: Squivv (Affliction) to Penance 14,473 | - |
| 19:47 | L | 2061 / 2098 | Resto Druid 4x Glad 2535 · Affliction 6x Glad 2594 · Assassination 4x Glad 2140 | 1 (0) | 2 / 4 | ours: Captnmurphy (Windwalker) to Sudden Demise 76,336 | LOCKED OUT at the death |
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
| 19:47 | L | 1 | 4.0 | 2.0 | 3.0 | 82% | 75% | 0 of 1 |
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
| **Our healer locked out at the moment of our death** (enemy healer, in wins) | **2 of 8** | **4 of 6** (a fifth ended 0s before) | |

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
  our healer was locked out in 4 of 6 losses, and in a fifth the lockout ended that same second.
  When we got a kill, their healer was locked out in only 2 of 8.

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

Both are one player each, and describe what they did, not what is optimal. Resources (runes,
runic power, chi, energy) are not read yet, so the guides cannot say why a button was pressed
when it was.

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
- Our healer was locked out at our player's death in all 3 losses (19:47, 20:13; 20:22 ended
  the same second). The Medallion was already used in 2 of them (20:13, 20:22) and unused in
  the third (19:47, silenced from the Rogue opener).
- **Not confirmed at this level:** drain predicting the kill (16 goes are too few), tight goes
  converting better (they did not), and overcommitment (none of ours in the losses).

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

