# Achievements and rewards: design

Status: **proposal, 2026-10-17.** Nothing below is built. Correct it here before it is.

## What it is for

Players earn achievements, and AI credits with them, for the things that make them better and keep
them in the coach's loop: play, read the review, work on the one thing, see it change. The reward
follows the improvement the coach already measures. It is never a reward for grinding.

## The rules

1. **Reward what the coach measures, against the same yardstick.** Every improvement achievement is
   one of the Improve page's measures (`ImprovementService`), read against the players of the same
   spec the player actually meets, or against the spec's norms (`wow:population`). Never a raw count:
   "never call a count a fault" (`match-review.md`) has a twin, never reward a count either.
2. **Sustained, not lucky.** A single session swings too much (below: one Disc Priest's sessions run
   from 0.27 to 1.76 dispels a minute). An achievement needs the rate held over a window of rounds,
   with a minimum of chances behind it.
3. **Your own games only.** Only rounds the player recorded themselves (the logging character),
   rated, and measured on the server. The app never sends a measurement, so nothing can be claimed
   from the client.
4. **Each tier once, and a monthly cap on credits.** A tier is earned once per character. Credits
   earned are capped per month so the cost of rewards has a ceiling.
5. **A guard measure that must not get worse.** Pushing one number can make play worse (dispelling a
   one-second slow while a teammate dies). Each improvement achievement names one guard measure from
   the same rounds that must stay inside the player's usual range.
6. **Never a penalty.** Achievements are only ever earned. Nothing is lost, and nothing is worded as
   a fault.

## The kinds

| Kind | Earned for | Examples | Credits |
|---|---|---|---|
| Getting started | The steps into the loop | App connected; first game uploaded; 10 games; Battle.net linked | small |
| Learning | Knowing the game | Every level of your spec's class quiz passed (`QuizService`); the arena basics check | small |
| Improvement | A measure reaching a tier, held | Dispels (below); interrupts on high-priority casts; crowd control on their healer inside your goes; defensives spent outside their goes (lower is better); not dying first | the most |
| Following through | Working on the one thing | The Improve page's focus measured better next session, and held | large |
| Community | Helping others | A published guide; a correction on a guide that was taken | small |

"Most improved" variants sit beside each improvement achievement: the player's own rate up by half
over their previous window. A player far below a tier still has something to earn.

## Rewards: AI credits

The credits system already exists (`CreditService`, `credit_transactions`, with `ai` and `learned`
credit types and a `reward` action). What spends AI credits for a player today is nothing: the sink
is "Ask about this game" (`docs/ai/ask-about-this-game.md`, not built). Until it opens, earned
credits build a balance that shows beside each achievement ("for when Ask opens"), or the first pass
gives badges only. That is a decision below.

The price of a credit follows the price of a question. One question costs about US$0.20 to 0.40 on
Opus, about $0.10 to 0.15 on Sonnet (that doc's estimates). At 5 credits a question, a credit costs
4 to 8 cents. A cap of 50 earned credits a month (10 questions) keeps the most a player can cost in
rewards at US$2 to 4 a month.

## How it would work

- **Definitions in code**, `config/achievements.php`: key, kind, game, spec (or every spec), measure,
  tiers, window, minimum chances, guard measure, credits per tier. Never a database enum: the list
  grows.
- **`user_achievements`**: user, character, achievement key, tier, earned at, and the evidence (the
  value, the threshold, the rounds it rests on). Unique per user, character, key and tier.
- **`AchievementService::evaluate(User)`**, run after each upload's pages are built (`BuildCoachPages`).
  It reads the same rows the Improve page reads, awards what is newly earned, and writes the credits
  through `CreditService::addAiCredits` (action `reward`), in one transaction. Run twice, it awards
  nothing twice.
- **Shown** on the Improve page beside each measure ("Bronze at 1.0, you are at 0.57 over your last 20
  rounds"), on the player's profile on the site, and as a notice in the app when one is earned. An
  admin page lists awards and credits paid.

## The first pass: Disc Priest dispels

**The measure.** The Improve page's "Dispels": dispels per minute that a debuff the spec can remove
sat on a teammate (`ImprovementService::measure`: dispels by the player ÷ minutes of dispellable
debuff on the team, rounds at analysis version 6 or later, at least 60 seconds of chance). It
counts what was possible, so a quiet game does not count against anyone.

**The numbers** (local copy, 17 Oct 2026, the games uploaded to Chriso's account):

| | Dispels a minute of chance |
|---|---|
| Skylake (Disc), 256 rounds | 0.57 |
| The other Disc Priests met, 306 rounds | 1.05 |
| The 57 other Disc Priests with enough chance: quarter / middle / top quarter / top tenth | 0.57 / 1.03 / 1.51 / 2.71 |
| Skylake by session (3+ rounds a day), 25 Sep to 15 Oct | 0.27 to 1.76 |

**The achievement**, "Cleanse the pressure" (Disc Priest; the same shape fits every healer with a
dispel):

| Tier | Held at | Where that sits | Credits |
|---|---|---|---|
| Bronze | 1.0 | the middle Disc Priest you meet | 5 |
| Silver | 1.5 | the top quarter | 10 |
| Gold | 2.5 | the top tenth | 20 |

- **Held over** the player's last 20 Disc rounds, with at least 3 minutes of dispellable debuff on the
  team across them. One good night (1.76 on 7 Oct) does not earn it; a habit does.
- **Guard:** healing per free minute over the same rounds stays at or above the bottom of the
  player's own range. Dispels bought with healing that was needed do not count.
- **Most improved:** the rate up by half on the player's previous 20 rounds (0.57 to 0.86), 5 credits.
- **Tiers from everyone, per season:** the first pass can fix the three numbers above. The second
  adds the dispel rate to the population norms, so each tier is a quartile of every Disc Priest in
  every measured game, and moves with the season.

## Build order for the first pass

1. The dispel rate into `wow:population`'s norms, per spec, with its quartiles.
2. `user_achievements`, `config/achievements.php` with the Disc dispel achievement and three
   getting-started ones, and `AchievementService::evaluate` after `BuildCoachPages`.
3. Credits through `CreditService` with the monthly cap; or badges only (below).
4. The Improve page's "next tier" line, the profile, the app notice, the admin list.
5. Tests: the window, the minimum chance, the guard, once per tier, the cap, re-runs award nothing.

## Decisions for Chriso

- **Credits now or badges first.** Credits now build a balance nothing spends until Ask opens.
- **The monthly cap.** 50 credits suggested.
- **Fixed tiers or seasonal ones** from the norms.
- **Public or private.** Achievements on a public profile and a leaderboard, or only the player's.
