# Distilled: relative resource state (Chriso with ChatGPT), 2026-10-07

**Source:** `chriso-chatgpt-resource-state-2026-10-07.md`.
**Who:** Chriso (the project's player, Gladiator) in conversation with ChatGPT, pasted in two parts.
The second part includes ChatGPT reading Claude's assessment of the first.
**Why it is worth reading:** it names what the model's mechanics serve. The model has the answer
pool, overlap, goes, resets, cadence and dampening, but never says what they are *for*, so the
cycle (go → answer → reset) ends up standing in for the objective.

**Standing caveat.** Two kinds of material, weighted very differently:
- **Chriso's Freezing Trap paragraph** is first-hand: a Gladiator's reasoning about a trade he
  recognises from play. It is a realisation, not an account of one recorded game, so it is
  `[OBS]` only as "the player reports this", and its empirical half is opened as a test (C14).
- **Everything ChatGPT wrote** is a language model articulating that realisation, and in the second
  message it is reading Claude's assessment back. Its agreement with this project's model is not
  corroboration, the same caveat as the Gemini note. Nothing is `[OBS]` on its authority.

Most of what follows is `[DER]`: it follows from mechanics or from Parts the model already holds.
No patch, bracket or format is stated; it inherits the model's, coordinated 3v3.

---

## 1. The objective is a favourable relative resource state; a go is one way to get there

`[adds — not in the model]` (assembles Parts 1, 2, 4, 12 and 19.3)

> "The objective of arena is not to execute the most complete go; it is to create the most
> favourable relative resource state, and a go is only one method of doing that."

ChatGPT's own correction to that wording, adopted:

> "The strategic objective is to create a more favourable relative resource state, such that a
> future commitment has better prospects of forcing a decisive outcome... you could theoretically
> have more cooldowns, more defensives, more answers than the opponent and still lose because of
> positioning, an unexpected CC chain, a bad target, execution."

The pieces are already in the model: Part 1 (a go is "a commitment of scarce resources aimed at
changing the answer pool"), Part 12 (playing the clock: damage between goes is the win
condition), Part 19.3 ("the go that is not sent is a move"). What is new is stating the objective
*above* the cycle, as a **lens, not a complete theory of winning**, which is how Part 20 writes it.

## 2. The player's trade: crowd control that costs more than it forces loses the exchange

`[adds — not in the model]`, `[OBS]` as the player's report; the empirical claim is C14

> "If going for CC costs you more defensives then it's not worth it consequently if you can force
> more enemy cool-downs while staying back and just doing damage then you win the trade you dont
> need to force the same chain of go reset. Even though you don't spend resources and they might
> not get used for 1 min eg freezing trap you have won the state by not having to used defensives
> and still force them"

> "Basically say instead of freezing trap you pulled dps out of healers range and loss causing
> them to retreat or use defensives whilst staying healthy. You didn't use trap but you
> essentially got more value from positioning"

The first-hand core of the source. It extends Part 4 (damage's third job) from "pressure spends
their healer's casts" to "pressure can force their cooldowns while yours stay up", and it puts
positioning (Part 14.1) on the list of ways to change the state. That positioning is something
the log cannot see is the point: a trade the analysis can partly measure (their defensives
forced, yours unspent) can be won by a means it cannot.

## 3. Preservation is value, and regeneration is its cost: one decision, not two rules

`[adds — not in the model]`, `[DER]`

> "Not spending a resource is not automatically a failure to use it."

> "Cooldowns are renewable resources. Holding one has a cost because time spent holding it can
> delay future uses."

> "If we simply say 'Preserving resources is valuable,' someone could reasonably conclude
> 'Therefore I should hold my cooldowns.' But that contradicts the other fundamental property of
> WoW: a cooldown that isn't used doesn't begin regenerating."

Written alone, either rule is wrong. Together they make one decision: what using it now and
starting its recharge is worth, against keeping it available. The regeneration half is arithmetic
(a 2-minute cooldown pressed at 0:30 gets three uses in a 5-minute round; held to 1:30, two).
**This does not weaken Part 6's tiering**, and the source agrees: *"The new principle should
explain why sometimes holding is correct, not overturn the existing observation that lower-rated
players frequently lose value by holding cooldowns indefinitely."*

## 4. A reset is not neutral

`[adds — not in the model]`, `[DER]` (Part 12 at the scale of one reset)

> "During a reset, cooldowns regenerate, DRs recover, dampening changes the value of healing and
> defensives, positioning changes, and each team's available resources change at different rates.
> Therefore the value of a reset depends on which team benefits from the resulting state."

Part 12 asks which side the clock favours over a round; this asks it between two goes.

## 5. Logs describe what happened; they do not determine the optimal action

`[adds — not in the model]`, `[DER]`; also a reading rule for `match-review.md`

> "Logs describe what happened; they do not by themselves determine the optimal action."

> "It means the recommendation is an interpretation, not a fact extracted from the log."

The strategy / tactics / execution labels around it are generic and are kept to one line in
Part 20. The sentence is the useful part: it is the boundary of what a match review can claim,
and it backs the review's existing rule "a count is a question, never a fault".

---

## What the source does NOT support

- **That preserving resources wins more games.** Stated by ChatGPT as an example of an empirical
  claim that needs testing, and it is: C14.
- **Any rate, threshold or bracket.** When damage from range out-trades crowd control, against
  which comps, at what rating: nothing here says.
- **That relative resource state explains winning.** The source itself limits it to a lens:
  positioning, comms, execution and target choice can decide a game the pool count favours.
- **Anything about Solo Shuffle**, or about a specific game. The Trap example is a realisation,
  not a recorded round.
- **Corroboration of anything already in the model.** ChatGPT's agreement with Parts 1–19 is the
  model being read back.

---

## Distillation log

| Date | What was pulled | Where it went |
|---|---|---|
| 2026-10-07 | Relative resource state as the objective, written as a lens with ChatGPT's own limit | `arena-structure.md` Part 20.1; pointer in Part 1; first line of `docs/arena/structure-summary.md` |
| 2026-10-07 | The player's Freezing Trap trade, and positioning as a way to change the state | Part 20.2, `[OBS]` as the player's report; test C14 |
| 2026-10-07 | Preservation and regeneration as one decision, keeping Part 6's tiering | Part 20.3, `[DER]`; test C15 |
| 2026-10-07 | Resets are not neutral | Part 20.4, `[DER]` |
| 2026-10-07 | Logs describe what happened, not the optimal action | Part 20.5; `match-review.md` reading rule 8 |
