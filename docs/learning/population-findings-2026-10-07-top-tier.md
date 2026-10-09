# What the best players' games show (7 Oct 2026)

Chriso's question: "take the highest MMR games on file and see if there is anything in the data
that could indicate the best play… would it be possible to learn anything from games with the
highest glad count or MMR?"

Tested over the population files (793 rounds, 1,586 teams), with every player's arena history
looked up from their Blizzard profile (1,209 of 1,287 players found, 468 with at least one
Gladiator season). Tools: `tools/match-review/popfeatures.php` (one row per round, with names),
`popexperience.php` (the histories), `popelite.py` (the reads). The two data files name players,
so they stay in `storage/app/` and are never committed.

## How the games were tiered

- **By team MMR:** rated 3v3 only. A Solo Shuffle round carries no team rating, and 2v2 runs on
  its own scale. 714 teams have one; 144 of them are 2100+.
- **By the players' history:** the average of each team's best 3v3 rating ever, when at least
  two of its three players were found. This works for Shuffle too. 110 teams average 2400+
  (Gladiator rating); only 8 average 2700+.

The top of this archive is 2100–2500 MMR and players with Gladiator seasons. It is not the AWC.
Every game comes from Chriso's own log, so one team in every round is his.

## Experience wins, but only modestly

- The more experienced team (a 400+ gap in average best 3v3) won **59%** of 379 rounds.
- With a 100+ gap it won 56% of 659.
- 3v3 teams averaging a 2400+ best won 60% of the time; teams below 1900 won 44%.

The best players lose four games in ten to teams with less history than theirs. Read every
"the top does X" below with that in mind.

## How the top plays differently (style, win or lose)

The two tierings agree on every line here. Each pair of numbers reads 2100+ MMR against below
2100, then 2400+ history against below 1900.

| Measure (per team) | By MMR | By history |
|---|---|---|
| First go starts | 10.1s vs 11.9s | 10.8s vs 12.4s |
| Goes with damage cooldowns together (joint) | 73% vs 65% | 69% vs 62% |
| Their defensives drawn per go | 4.5 vs 3.9 | 4.3 vs 3.7 |
| Kicks a minute (team) | 0.39 vs 0.28 | 0.38 vs 0.23 |
| CC casts a minute (team) | 1.82 vs 1.60 | 1.82 vs 1.54 |
| CC off the kill target | 62% vs 56% | 64% vs 55% |
| Free exchanges given away (C14) | 0.09 vs 0.21 | 0.07 vs 0.25 |
| Idle share of time alive | 15.1% vs 15.9% | 13.8% vs 16.8% |
| DPS damage per free minute vs the spec's median | 1.14 vs 1.06 | 1.13 vs 1.02 |

In short, the top opens sooner and goes together. It makes the other team spend more, kicks and
crowd-controls more (more of it on the healer and the off-targets), wastes less time, and almost
never presses a defensive when nothing was coming. That last line is open question C14, the
"free exchange": a defensive pressed outside the other team's goes while the other team spends
nothing. Top teams give a third as many of these away.

## What decides a game at the top

This read is paired: the winning team against the losing team of the same round, so the lobby,
the patch and the comps are held. "Winner higher" is the share of rounds in which the winner
measured more (ties left out); 50% means no signal.

| Measure | Both teams 2100+ MMR (63 rounds) | Neither team elite (695 rounds) |
|---|---|---|
| Enemy healer CC per go | **66%** of 58 | **64%** of 615 |
| Their defensives drawn per go | **71%** of 58 | 56% of 594 |
| More defensives up than them at 2:00 | **74%** of 27 | 58% of 219 |
| More defensives up than them at 1:00 | 61% of 46 | 58% of 454 |
| Locked out a smaller share of time alive | 65% of 63 | 65% of 695 |
| Goes joint | 60% of 45 | 57% of 426 |
| Kicks a minute | 47% of 58 | 48% of 506 |
| CC casts a minute | 43% of 63 | 54% of 688 |
| First go sooner | 55% of 62 | 45% of 681 |

Three readings:

1. **What wins at the top is what wins below it.** CC on the healer in a go, defensives drawn, and
   answers held separate winners from losers at 2100+ just as they do lower down. These are the
   lines the Basics tab already measures, so the coach measures what decides games at this
   rating as well.
2. **At the top, the resource trade decides more.** Drawing their defensives (71% against 56%)
   and holding more of your own at 2:00 (74% against 58%) separate winners more sharply at
   2100+. This is arena-structure.md Part 20's relative resource state, seen most clearly where
   the basics are shared by both teams. The samples are small (27 and 58 rounds), but both
   results point the same way as the full archive.
3. **Some top-tier habits are the entry price, not the decider.** Top teams kick far more than
   lower teams, but inside a game the team that kicked more did not win more (47%, 48%). The
   same holds for CC casts. Kicking is part of how the top plays; more kicking than your opponent
   is not how a top game is won.

"Tight goes" (presses close together) went slightly with *losing* in both reads (34% at 2100+,
46% below). It is left unexplained and not used.

**Only 21 rounds have both teams elite by history.** Too few to read: listed in the script's
output, not here.

## The players with the most Gladiator seasons

Each player counts once here. The logging player and his regular partners would otherwise be
most of the rows. Each habit is the player's total against what average players of the same
specs did over the same rounds (1.00 = the spec's average); a band reads the median player.

| | Never Gladiator (722 players) | 1–2 seasons (257) | 3+ seasons (196) |
|---|---|---|---|
| Won (per player) | 46% | 55% | 62% |
| Died first | 20% | 16% | 12% |
| DPS kicks a minute | 0.56 | 0.96 | 1.18 |
| DPS CC casts a minute | 0.95 | 1.20 | 1.22 |
| DPS idle share | 0.99 | 0.86 | 0.79 |
| DPS damage per free minute | 0.90 | 1.21 | 1.37 |
| Healer CC casts a minute | 0.29 | 0.89 | 0.95 |
| Healer idle share | 0.98 | 0.99 | 0.82 |
| Healer healing per free minute | 0.68 | 1.46 | 1.54 |

A Gladiator DPS kicks about twice as often as a DPS of the same spec who never made Gladiator.
They also crowd-control a quarter more, sit idle a fifth less, and die first less often. A
Gladiator healer crowd-controls about three times as often as a never-Gladiator healer of the
same spec.

Caveats:
- The damage and healing rows mix seasons and gear.
- Gladiators meet stronger opponents, which should push their numbers *down*, not up.
- The never-Gladiator healers are mostly Solo Shuffle players. The kicks rows for healers are too
  few to read.

## What this means for the coach

- **Basics first, at every rating.** The Basics tab's three lines (healer locked in the go,
  defensives drawn, answers held) hold up at the top of this archive, so they are the right
  first thing to measure. They should not be replaced by "play like a Gladiator" style lines.
- **A "how the top plays" layer is supported as a baseline, not a target.** It would cover
  opening sooner, going together, kicking and CCing more (healers too), less idle time, and no
  free exchanges given away. A player far below these numbers is playing below the level they
  want, but matching them does not win a game on its own.
- **The free exchange (C14) is a good candidate for a tip.** It separates the tiers sharply
  (0.07–0.09 against 0.21–0.25 a team). Inside a game it is rare, so it cannot separate winners
  (most rounds tie at zero).
- **No "best play" is visible.** No measure separates winners from losers at the top much past
  70%. The best teams lose 4 in 10 to weaker ones. Comps and the matchup are not held in the
  style read. What the archive shows is how the top plays, and that what decides their games is
  the same resource trade the arena model describes.

## How to re-run

```
php -d memory_limit=3G artisan wow:population     # if the archive changed
php -d memory_limit=2G tools/match-review/popfeatures.php
php tools/match-review/popexperience.php           # only new players are looked up
python tools/match-review/popelite.py
```
