# Defensive trading: what the archive says (2026-10-07)

Chriso's framing: the counter to a go is effective trading, and higher-rated teams trade more.
Tested on the population files (795 games, 1,577 side-games) with `tools/match-review/poptrades.py`.

| Question | Result |
|---|---|
| Do higher-rated sides spend more defensives per enemy go? | **Yes.** Above the median side MMR (1898): median 4.5 a go. Below: 2.7. |
| Within a rating band, does spending more go with winning? | **No, the reverse.** Above the band's median: 41% wins (high MMR), 38% (low). Below it: 61%, 57%. A side spends more when it is under more pressure, the same trap as a healer's healing. |
| Does the number of defensives a go drew predict whether it killed? | **No.** 0 drawn: 30%; 3 drawn: 21%; 5+: 30%. The count says nothing; which ones, and what is left for the next go, is the question. |
| Does a kick on their healer inside the go draw more defensives? | Yes, and it kills more: 4.9 defensives drawn and 45% killed (93 goes), against 4.2 and 27%. Inside the go's peak, not before it (before is not measured). |
| Earlier, from our own games (1 Oct, `cdledger.php`) | With two or more big answers down when their go starts, it killed 35–43%; with one, 17–22%. An expensive answer is still down at their next go 95% of the time. |

**Reading.** More defensives is not better trading. Stronger teams spend more because stronger
teams' goes demand it, and both sides of the evidence point at the same thing: the state at the
next exchange. A trade is good if the team still has an answer when the next go comes. That makes
trading a scheduling question (their cadence against our cooldowns), not a count.

**Stacking.** Percentage reductions multiply, they do not add: Barkskin 20% and Survival Instincts
50% leave 0.8 × 0.5 = 40% of the damage (60% reduced, not 70%); add Pain Suppression 40% and it is
0.8 × 0.5 × 0.6 = 24% (76% reduced, not 110%). Values from the site's resolved spell text.
Not yet checked against hit sizes in a log.

**Built from this (same day).** The Matchup Lab's "How a side trades its answers" (`TradePlanService`)
and the Basics tab's "Your answers to their goes" (their goes that met two or more of your big
defensives down, graded; answers per go, shown and not graded).

**Savage Momentum.** A Feral lands 0.58 kicks a free minute at the median this season (0.0 at the
25th percentile, 1.03 at the 75th). At 10s each that brings a 3-minute Survival Instincts back in
about 2:44 at the median and 2:34 at the 75th percentile, not 2:30.

## Is the relative resource state visible? (same day, `tools/match-review/popstate.php`)

793 archived rounds, both teams. At fixed points in the round (nobody dead yet), each team's
defensives and offensive cooldowns on cooldown, from the timeline; the win rate of the team with
more of its own up than the other team's against the team with fewer.

| At | More of our DEFENSIVES up than theirs | Fewer | | More of our OFFENSIVES up (held) | Fewer (spent) |
|---|---|---|---|---|---|
| 0:30 | 54% (351) | 50% (233) | | 50% (293) | 52% (299) |
| 1:00 | **59%** (329) | 45% (191) | | 50% (260) | **58%** (232) |
| 1:30 | 55% (264) | 43% (143) | | 47% (226) | 54% (161) |
| 2:00 | **53%** (163) | **32%** (98) | | **40%** (131) | 51% (112) |
| 3:00 | 40% (53) | 28% (25) | | 27% (30) | 46% (41) |

**Both halves of the trade show.** Holding more answers than the other team goes with winning,
and the gap widens through the round (5 points at 0:30, 21 at 2:00). Offensive cooldowns run the
other way: the team that has *spent* more of its offensives wins more. Read together that is
resource conversion: offence spent to take their defensives, while keeping yours. It matches
Part 20.3's "holding has a cost" for offence, and Part 2 for defence. A correlation, with the
usual confound (a winning team gets the chances to press its offensives), so not a proof of what
to do.

**C14, free exchanges** (a defensive pressed outside the other team's goes, with none of the other
team's within 15s): rare under this narrow definition, 205 rounds where the teams differ. The team
that won more of them won 51% against 44%. Leaning the right way, not yet settled.
