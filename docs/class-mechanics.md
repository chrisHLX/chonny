# Class mechanics: how a spec's output works

Status: current, 2026-11-01. 25 specs have an Empowered spells box on PvP Guides, from 46 reviewed
buffs. Disc Priest was measured by hand; the other 24 came through the general tool. See "What is not
built" for the rest.

This is the process for working out how a spec's buffs, talents and abilities combine, recording it,
and redoing it when a patch changes them. Guides, the match review and player reads can then all use
the same record.

## The words

**Empowered cast.** One press of a main ability made stronger by buffs the player built beforehand.
The word overlaps with Evoker's empowered spells; we accepted that, because it is the word players use
and Blizzard's own Power of the Dark Side tooltip says "empower your next Penance".

An empowered cast has five parts:

| Part | What it is | Disc example |
|---|---|---|
| Payoff | The press | Penance |
| Empowers | The casts that build it, and what each gives | Radiance gives Harsh Discipline, Mind Blast gives Power of the Dark Side, Evangelism gives Archangel |
| Timer | How long each empower lasts | Listed at 18 to 30 seconds |
| Talents | What must be taken for it to exist, and what changes its size | Harsh Discipline, Holy Ray, Prompt Prognosis, Twinsight |
| Result | The measured change | Plain ×1.00, all three ×1.83 |

**The timer is part of the mechanic.** An empower does not have to be spent straight away, so the
question is when to spend it, not only whether.

An empowered cast is not a rotation (timing is loose), a cooldown (the empowers are often ordinary
buttons), a go (that is the team's commit) or a setup (that is control on the enemy). The burst guide
says what a go looks like. This says what makes one press big.

## What gets recorded

For each empowered cast, per spec and hero tree:

- the payoff, its empowers, and which press gives each, with how often that link held
- each empower's listed timer, how long players hold it, and how often it runs out
- the talents it depends on, by tree (class, spec, hero)
- the measured result of each empower alone and stacked, against the tooltip's number
- the patch build it was measured on
- open questions: what does not add up yet
- a label on each claim: [OBS], [DER] or [HYP], as in `arena-structure.md`

## The process

Run it per spec, per hero tree.

1. **Pick the abilities.** Rank them by share of the spec's output.
2. **Take one spike from a high-rated game.** Read the talents, buffs and tooltips around it.
3. **Work the number out.** The game runs on fixed rules, so if the tooltips multiply out to the hit,
   one example has shown the mechanic. Prompt Prognosis was confirmed this way from a single cast: its
   tooltip gives ×2.25, and bolt 2 was 75,423 against a predicted 75,433.
4. **Whatever is left over becomes a question.** Record it. That same cast still had about ×1.17
   unexplained after its known buffs.
5. **Answer it from other casts.** First the same player in the same game, then other games. Compare
   hits with the same other buffs up.
6. **Check every link.** Does the press give the buff? Does the size match the tooltip? How long is
   the empower held, and how often does it run out?
7. **Record it**, with the build and a label.
8. **Pool only to ask how often, or what is better.** One example can show how a spell works. It
   cannot show that a play was right, how common it is, or that a talent beats another.

A measured effect needs a mechanism before it counts. Each must pass:

- The tooltip or spell data gives a way for it to happen. Strategic Infusion measured ×1.23 on Rake,
  but it is a crit buff and the hits measured were not crits, so it was thrown out.
- It has a real sample before pooling is trusted. Find Minerals measured ×1.18 on Rip from 483 hits.
- It holds with the other buffs held equal. Harsh Discipline first looked like 11% bigger bolts. It is
  ×1.00: it travels with Archangel in goes.
- The result holds on more than one player, across separate sessions.

## Running it for every spec

```bash
php -d memory_limit=3G tools/match-review/empowered.php scan <dir>     # every raw log into per-spec hit files, about 15 min
php -d memory_limit=3G tools/match-review/empowered.php report <dir>   # candidates with tooltips in <dir>/candidates.txt, about 8 min
# read candidates.txt, then record decisions in data/empowered-casts/reviewed.json
php -d memory_limit=4G tools/match-review/empowered.php build <dir>    # data/empowered-casts/{class}/{spec}.json
php tools/match-review/empowered.php spike "<Name-Realm-US>" <match> "<Ability>"   # one game, hit by hit
```

`<dir>` holds player names, so it stays out of the repo. The committed files hold none.

**The scan and report never decide.** A buff reaches a page only when `reviewed.json` approves it,
the same split as `tagaudit.php`. `only` limits it to abilities the tooltip covers, `from` overrides
the press found in the log (empty means it procs), and `payoff` picks the ability for the stacked bars.

Each candidate has to lift a main ability by at least ×1.07, with 60 hits on each side, 3 players, and
both halves of the players showing it. The buff's name must also belong to the class's talents or spells.

Approval needs both: the tooltip states a direct increase to the hit measured, and the number fits.
Of 297 candidates that passed every automatic check, 43 were approved, about one in seven. So the
automatic checks are not enough, and this step cannot be skipped.

**What fools a hit-size comparison.** All of these passed the automatic checks:

- **Damage scaled by a resource.** Finishers scale with combo points, so a buff that arrives with more
  points looks like a damage buff. Subtlety's Eviscerate showed ×1.48 for Ancient Arts, which only
  shortens two cooldowns.
- **Hits that depend on crits.** Fire's Ignite grows from crits, so nearly everything read as a multiplier
  on it, up to ×2.3. Fire has no box for this reason.
- **Crit and haste buffs.** Hits measured exclude crits, so a crit buff can't raise them. Strategic
  Infusion's ×1.23 on Rake and Killing Machine's ×1.39 on Obliterate are false, probably standing in for
  something that arrives at the same moment.
- **The buff being the same event as the hit.** Ability names can cover several kinds of hit: Riptide's
  initial heal and its ticks, Feral Frenzy's hit and its bleed.
- **Healing that depends on the target.** Healing taken changes with what's on the ally, and heals over
  time pile up when a team is in trouble, so a defensive like Pain Suppression or Frenzied Regeneration
  appears to raise healing. Preservation's Emerald Communion read ×3.7 on Lifebind. Healers came out
  worst: besides Disc, four got one or two buffs, and Holy Priest and Preservation got none.
- **The tooltip not covering it.** A buff can be real and the ability wrong. Voidform's 20% reads ×1.66 on
  Shadow Word: Madness, which has its own scaling, so only the hits close to the tooltip were kept.

**The two numbers answer different questions.** A row holds the other buffs apart. The stacked bars are
raw medians. They can disagree: Coalescing Water is ×1.47 on its row and ×1.05 in raw bars, because
Riptide's ticks are in there. So the bars only show for two or more buffs, with a note that others may
also be up, and a single-buff spec has no bars.

**Only hit size is measured generally.** Disc needed a hand measure because Harsh Discipline adds bolts,
which the general tool can't see. Any buff that adds hits, or shortens a cast, or ramps across a channel
is missed or misread. Teachings of the Monastery reads ×5.29 on Blackout Kick and Momentum Boost ×1.99
on Fists of Fury. Both are real mechanics, but not ones a size comparison can state, so neither is listed.

## After a patch

`wow:patch-update` writes what changed to `spell_changes`. The process then is:

1. List the spells in any record whose tooltip, cooldown or effects changed, and any talent added,
   moved or removed.
2. Mark those records "needs checking" and keep showing them only with that label.
3. Rerun steps 2 to 7 for those abilities on games played on the new build.
4. Compare against the record. A number that moved reopens its question, and a new talent that touches
   a payoff starts a new one.
5. Records nothing touched stay as they are, but still get a pooled recheck, because hidden PvP
   adjustments do not always appear in the spell data.

## Worked example: Disc Priest, Oracle

From the local archive: 868 games with a Disc, 181 players, 163 Oracle and 18 Voidweaver.

**Empowered Penance** [OBS]

| Empower | Comes from | Link held | What it does | Tooltip |
|---|---|---|---|---|
| Harsh Discipline | Power Word: Radiance | 99.6% | 4 bolts to 6, bolt size ×1.00 | +3 bolts |
| Power of the Dark Side | Mind Blast or a Shadow Word: Pain tick | 86% | Bolt size ×1.29 | ×1.30 |
| Archangel | Evangelism | 99% | Atonement per damage ×1.18, bolt size ×1.00 | ×1.15 |

Evangelism drives two of the three: 1,379 of 1,383 instant Radiances came with it up, and 64% of Harsh
Discipline charges came with it up. 83% of Evangelisms were followed by a Penance within 8 seconds.

Healing from one Penance on an enemy, 5,290 casts at 3 Atonements, each against that player's plain
casts, overheal included so missing health does not cap it:

| Empowers up | Healing |
|---|---|
| None | ×1.00 |
| Archangel | ×1.08 |
| Harsh Discipline | ×1.22 |
| Power of the Dark Side | ×1.23 |
| Harsh Discipline + Archangel | ×1.38 |
| Power of the Dark Side + Archangel | ×1.45 |
| Harsh Discipline + Power of the Dark Side | ×1.66 |
| All three | ×1.83 |

**Timers** [OBS]

| Empower | Listed | Held before spending | Ran out unused |
|---|---|---|---|
| Harsh Discipline | 30s | 2.4 to 2.6s | see open questions |
| Power of the Dark Side | 20 to 30s by copy | 2.3 to 2.5s | 4% |
| Weal and Woe | 20s | 1.5 to 2.1s | 1 to 3% |

These barely change between under 1800, 1800 to 2099 and 2100 and up. Players at every rating spend
empowers within about 2.5 seconds and rarely lose one. So the gap is not wasted buffs. Whether holding
one to line up with the others does better is the open question.

**Talents by tree** [OBS]

- Spec tree (either hero tree): Harsh Discipline, Power of the Dark Side, Dark Indulgence, Evangelism,
  Archangel, Holy Ray, Castigation, Weal and Woe, Master the Darkness.
- Oracle: Prompt Prognosis, Twinsight, Preventive Measures, Prophet's Insight. These add to Penance.
- Voidweaver: Entropic Rift, Voidheart, Void Infusion, Overwhelming Shadows. These go into Mind Blast
  and the Shadow side, so its empowered cast is likely a different record. Not measured yet.

**Open questions**

- Harsh Discipline gives +2 bolts where the tooltip says +3, and 50% more bolts adds only 22% healing.
- 18% of Harsh Discipline charges disappear about 1.3 seconds after they are made with no Penance near.
  That is far too short to be the 30-second timer, so something else removes them.
- Archangel is ×1.18 per heal but ×1.08 across a whole cast.
- 14% of Power of the Dark Side procs are not traced to a press or a tick.
- About 0.6 instant Radiances per Evangelism, where the tooltip says 2.
- About ×1.17 left over on the example cast. Gladiator's Insignia is the first suspect.
- Twist of Fate and Power Infusion are not yet held equal in the stacked table.

## Worked example: Feral Druid

234 player-games, 37 players. Tiger's Fury: direct hits ×1.14 to ×1.16 against a tooltip of ×1.15; Rip
and Rake ×1.22. Ferocious Bite with Tiger's Fury, Coiled to Spring and Unseen Predator's Craving is
×1.46, on small samples of 21 to 77 hits per row.

Open: why Rip and Rake measure above the tooltip, and whether Strategic Infusion and Overflowing Power
were standing in for Rake from stealth. Melee swings are not counted yet.

## Reads this allows

Each read describes what happened and leaves the judgement to the player, following the three tests in
`VISION.md`: is it right, does it change what they do, does that improve results.

- **Talent taken, mechanic unused.** A Disc with Harsh Discipline who rarely has a charge on Penance.
- **Empowered casts in a game.** How many Penances were full, part or plain, and in which moments.
- **Lining up.** Empowers spent one at a time when they could have landed together.
- **Hero tree.** What an Oracle or Voidweaver Disc's main empowered cast is, for a new player choosing.

## Reading the log

Traps found while measuring this, all checked on real lines:

- On `SPELL_DAMAGE` read from the end of the line: amount -11, amount before mitigation -10, absorbed
  -5, crit -4. On `SPELL_HEAL`: amount -5, overheal -3, crit -1.
- A fully absorbed hit is `SPELL_MISSED` with `ABSORB`, and it carries the amounts: two fields after
  `ABSORB` is the amount absorbed, three after is the amount before mitigation. Skipping these lost
  whole Penances.
- A buff can be logged a moment before the press that made it. Check both orders.
- A channelled press keeps its buff until the channel ends, so a buff dropping seconds after the press
  was still spent on it. Testing for the press at the moment the buff drops reported Power of the Dark
  Side as 95% wasted when the true figure is 4%.
- Pets carry Atonement. Count players only.
- Prompt Prognosis's strong bolt is logged second, not first.
- Damage over time holds the buffs from when it was applied, so tie a tick to the application.
- Overheal counts in gross healing on purpose. Healing that lands is capped by missing health, so it
  cannot compare casts.

## What is not built

Built: the tool (`tools/match-review/empowered.php`, plus `disc-penance.php`, `disc-radiance.php` and
`disc-timers.php` for Disc's hand measures), the review file, and the box on each spec's PvP guide
(`App\Support\EmpoweredCasts`, `partials/empowered-casts`).

Still to do:

1. Timers in the box. The scan records each buff's usual length, but "held" and "ran out" exist only for
   Disc so far.
2. Hit counts, cast time and ramps in the general tool, so Harsh Discipline-style buffs can be found.
3. Combo points and other resources held equal, which would open up Rogue and Feral finishers.
4. Absorbs and healing that depends on the target, which would open up healers.
5. Voidweaver's own record, and a split by hero tree. Each box pools both trees today.
6. Hand-work the headline number of each spec with `spike`, as Disc's was. So far only Disc has had this.
7. Store records and questions where a query can read them, and tie a patch's `spell_changes` to them.
8. Add the reads to the match review. New measures need `RoundAnalysisService` to keep hit sizes and
   buffs, and because the server keeps no raw logs, every app re-sends its games after that VERSION bump.

**No box yet:** Holy Priest, Preservation, Devastation and Augmentation Evoker, Fire Mage, Elemental
Shaman, Demonology and Destruction Warlock, Devourer Demon Hunter, and the tanks. Candidates existed for
most, but none passed review. The box says so rather than showing a guess.
