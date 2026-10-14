# Psychic Scream windows: what the log can support (2026-10-14)

An exploration, not a feature. The question (Chriso): do strong Disc Priests recognise, anticipate or
create valuable fear windows that weaker ones miss, in comparable situations, rather than just being
handed better ones by their teammates? Nothing here is on a card or the site. The scripts were
scratch files (positions and windows read straight from the archive's raw logs through
`ArenaMomentService::readTimeline`); rebuild them from this note if the work continues.

Data: the archive's 735 Disc Priest rounds with an enemy healer (986 of its 1,002 games are Chriso's
own logs, so every game has one of his characters in it). Bands by the priest's Gladiator seasons
(cached Blizzard lookups, 119 of 126 priests found): Chriso 334 rounds; never Gladiator 256 rounds,
86 priests (median game MMR 1731, mostly Solo Shuffle); Gladiator 1-2 seasons 105 rounds, 22 priests
(1974); Gladiator 3+ 32 rounds, 11 priests (2034).

## Positions are usable (see `docs/combat-log-ingest.md`, "Position and facing")

Checked against Psychic Scream's radius (victims a median 5.2 yards from the cast, 90% within 8.6)
and facing (94.6% of facing-required casts point within 90 degrees). A position exists only when the
unit acts or is hit: 82% of moments have both priest and healer placed within a second; the rest are
left out rather than interpolated.

## Three states, one window per continuous run

Every half second, per priest:
- **Reachable:** Scream off cooldown (45s; 30s gave the same picture), the priest free of crowd
  control, their healer alive, within 16 yards (about two seconds' walk), not already controlled,
  disorient diminishing returns fresh, no immunity aura, no Tremor Totem of theirs in the last 10s.
- **Feasible:** reachable, and going for it would not expose the priest: no enemy offensive cooldown
  in the last 8s, priest health 50%+, under 20% lost in 3s, and the healer not within 10 yards of
  one of their damage dealers unless the priest is already in range.
- **Valuable:** feasible with an allied offensive cooldown in the last 8s; **best** adds their
  healer's trinket used within 120s.

A **window** is one continuous run of reachable moments, labelled by the best state it reached;
counts below are unique windows, with the rounds and players behind them, and every 95% range is
resampled by player (by round for Chriso, the only player in his band), so overlapping moments
never count twice.

## What the log supports

**The immunity list holds.** Of 1,725 enemies within 8.5 yards of a Scream (fresh positions), 192
were not feared: 42% under an immunity aura (Phase Shift 35, Anti-Magic Shell 11, Precognition 8,
Bladestorm 7, Cloak of Shadows 6, Divine Shield 4), 39% already in disorient diminishing returns, 7%
already controlled, 1% Tremor up; 25% none of these (line of sight is the likely rest, and it is not
in the log). Alter Time was on the list wrongly: three targets under it were feared.

**Fears end measurably.** Of 354 of Chriso's fears that landed on their healer: ran their course 58%
(about 6s in PvP), trinketed 21%, **broken by his own team 12%** (after a median 2.1s; Shadow Word:
Pain the most common breaker), Tremor Totem 6%. Other priests: 63%, 18%, 11%, 4%. A fear broken by
your own damage after two seconds is a cost the log names exactly.

**Reachable is mostly not feasible, and the reason is the enemy's go.** Of 723 reachable windows that
never became feasible, 84% began during an enemy offensive cooldown and 36% had their healer
standing with a damage dealer (overlapping). Those are not missed fears; the priest was usually the
one being trained.

**Approaching is the decision, not the luck.** Chriso's feasible-or-better windows that began out of
range: when he walked in to 8 yards (125 windows, 99 rounds) he pressed 66% of the time; when he
stayed out (133 windows, 92 rounds), 4.5%. Walking in did not show a cost in these windows (no 30%
health drop within 5s, against 4.5% for staying out), and it did not show a payoff either (an enemy
death within 10s 3.2% against 6.0%). The priest walks in when it looks safe, so this cannot say
what walking in would have done in the windows he left.

**Held windows can be read.** After a valuable window the priest did not use, the next Scream in
25s: Chriso, a better one (on their healer, in a go, trinket down) 6.9% (2-13%), none at all 51%;
never-Gladiator priests 0% (52 windows, 31 players); Gladiator priests 37.5% (17-56%) but from
32 windows of which 17 are one player's. A lead about one priest, not about Gladiators.

## Comparable situations, by band: no answer yet

Share of windows used, by the best state reached (unique windows; 95% by player):

| | reachable | feasible | valuable | best |
|---|---|---|---|---|
| Chriso | 22% of 357 | 30% of 168 | 42% of 94 | 43% of 56 |
| never Gladiator | 22% of 202 (58 players) | 29% of 114 | 25% of 59 | 64% of 22 |
| Gladiator 1-2 | 30% of 131 (16) | 31% of 71 | 18% of 17 (4 players) | 48% of 25 (4) |
| Gladiator 3+ | 35% of 29 (7) | 38% of 13 (3) | 43% of 7 (2) | 75% of 4 (2) |

Once windows are matched on feasibility, there is no clear gradient, and the Gladiator bands rest
on 2 to 16 players. The first pass's "Gladiators use more reachable windows" (44% against 30%) did
not survive separating feasible from reachable. **The data cannot yet say whether expert priests
exploit comparable situations better.** It needs Gladiator Disc Priests' own logs, many of them.

## Observed, inferred, unobservable

- **Observed directly:** positions and facing when a unit acts, crowd control and its diminishing
  returns, immunity auras, trinkets, Tremor summons, offensive cooldowns, every Scream and whom it
  hit, how each fear ended and who broke it, health, deaths.
- **Inferred:** reachability (walking speed), risk (pressure and stacking as proxies), value (allied
  go and trinket as proxies; kill rates after fears in those states are about twice the others),
  the approach before a fear.
- **Not observable:** line of sight and walls, where idle units stand, intent and calls, whether a
  hold was deliberate (only what followed it), what the opponent noticed.
