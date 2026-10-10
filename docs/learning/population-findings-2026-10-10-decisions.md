# Defensive decisions by situation: what the archive says (2026-10-10)

The step past comparing players. A comparison says a player is different; this asks which choice
went better **in the same situation**. One row per enemy go, from the defending side, over 914
games (4,426 goes before a first death; 1,794 of them 3v3). Tool:
`php tools/match-review/decisions.php [--bracket=3v3] [--spell="Pain Suppression"] [--since=]`.
Needs `wow:population` from 2026-10-10 on: the compact game now keeps each go's `cover` (the
cooldown ledger at the go's start), the defending side's presses inside it, and the first death.

## The three parts of a row

| Part | Measured as |
|---|---|
| Situation | big answers (90s+) already down at the go's start: 0, 1, 2+; pressure: **heavy** when two or more attackers pressed offensive cooldowns or the defenders' healer was locked in the go's peak. Only the attacker's inputs: peak damage is after mitigation, so it depends on the choice being judged |
| Choice | **early**: the first answer went out before its target was in danger (35% health or 5s to live); **late**: only once in danger; **none**. And how many answers the go drew. Medallion set apart |
| Outcome | a defender died in the go or within 30s of it; at the attackers' next go, two or more big answers down and whether it killed; the round |

## What it shows (all brackets; 3v3 alone in brackets where it differs)

| Finding | Numbers | How far to trust it |
|---|---|---|
| **The first answer before danger goes with fewer deaths, in every situation.** | Died, early against late: 0 down light 5% vs 33%; 0 down heavy 20% vs 26%; 1 down light 14% vs 22%; 1 down heavy 26% vs 33%; 2+ down light 23% vs 33%; 2+ down heavy 33% vs 43%. 3v3: 17 vs 25, 21 vs 22, 30 vs 37 | **Weak as a cause.** "Late" means the target reached danger, which is closer to dying by definition. Among goes that reached danger either way, early-then-danger died *more* (39% vs 34%; 3v3 35 vs 30). An early answer that kept a go out of danger cannot be told apart from a go that was never going to get there |
| **Pressing nothing into a heavy go with your big answers already down is the worst cell.** | 2+ down, heavy: none 56% died (164), one answer 42% (209), two or more 35% (992). 3v3: 50%, 37%, 32% | Moderate. The direction is the one the model predicts; "none" can also mean the defender was locked out, which the row does not yet hold (Phase 2) |
| **Into a light go, holding goes with winning.** | 0 down, light: none 4% died / 71% won (70), one 7% / 75% (69), two or more 15% / 47% (86). 2+ down, light: none 17% / 75%, two or more 26% / 48%. 3v3, 2+ down light: none 4% / 85% (26) | Moderate. "Light" is coarse: a go that drew two answers may simply have hit harder than its attackers' inputs say |
| **Spending costs the next go less than expected.** | 0 down, heavy: one answer leaves 2+ big down at the next go 4% of the time, two or more 56%; the next go killed 23% against 30% | The cost is real but smaller than the 1 Oct ledger suggested for our own games (95% still down) |

## Reading

Two things look like advice, and both are about matching the answer to the go rather than how
many were spent:
1. **When the go is heavy and your big answers are already spent, answer it anyway.** Holding into
   a drained, heavy go is where defenders die most (56%).
2. **When the go is light, hold.** Spending two or more into a light go goes with losing the round
   (47-48% against 71-75%).

The early-against-late split is **not** advice yet: the like-with-like check reverses it. It needs
the target's health at the go's start and the defender's lockout (Phase 2) before it can say
anything.

## Phase 2: the same read at VERSION 12 (same day)

`RoundAnalysisService` VERSION 12 stores each answer by owner (back or not, owner locked out or not
at the go's start), the go's target's health at its start, and each player's median item level and
PvP talents. Re-measured: 914 games (`wow:population --fresh`) and 431 stored rounds.

| Check | Result |
|---|---|
| Was "pressed nothing" forced (every answer down, or every owner locked out)? | **Almost never: 8 of 4,426 goes.** "None" is a choice, so the drained-and-heavy finding stands |
| Drained and heavy, pressed nothing, holding the target's health | Fresh at the start: 54% died (96) against 30% early, 39% late. Hurt: 60% (67) against 39%, 47%. 3v3: 49% and 55% |
| The same with gear even (medians within 5 item levels) | 56% (118) against 33% early, 44% late |
| Does the item-level gap alone move it? | Little: defenders ahead 25% died, even 27%, behind 29% |
| Early against late with the target fresh at the start | Early still dies less (heavy, 0 down: 20% vs 26%; 2+ down: 30% vs 39%), but the like-with-like check (goes that reached danger) still reverses it. Not advice |

### Pain Suppression (`--spell="Pain Suppression"`), heavy goes on a fresh target, where it was back

| | Held | Owner locked out | Pressed early | Pressed late |
|---|---|---|---|---|
| Every Disc Priest: died | 17% (184) | 22% (78) | 24% (341) | 24% (271) |
| 2+ big answers down at their next go | 33% | 40% | 57% | 67% |
| Skylake alone (`--guid=Player-3725-0C448541`): died | 8% (60) | 32% (22) | 28% (124) | 26% (117) |

Read with care. Holding goes with fewer deaths and with Pain Suppression for the next go, but a
priest holds it when the go is not landing on anyone, which the attackers' inputs do not fully
capture. What the table does support: pressed early or late made no difference (24% either way),
so the timing inside a go is not where Pain Suppression games are lost; and Skylake presses it in
75% of the heavy goes where it is back, against 67% for the other Disc Priests in the archive.
Skylake stunned at a go's start with it back: 32% died (22 goes, a lead only).

## What it still cannot say

- Whether a held answer was held because the go was not landing. The next step is the damage the
  go's target took in its first seconds, before any answer, as a pressure measure.
- Charges are modelled as independent (`CooldownLedgerService::coverage()`), which overstates "up".
- `--guid` counts "left out" before the player filter: the header's numbers are the archive's.
