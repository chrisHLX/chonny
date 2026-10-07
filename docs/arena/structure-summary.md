# Arena structure: a one-page summary

The arena model (`arena-structure.md`) on one page: the objective, the vocabulary, then the shape of a match,
with what the match analysis measures at each stage. Agreed with Chriso as the summary on
2026-10-07. Each line points to the Part of `arena-structure.md` it comes from; go there for the
sources and the reasoning, and edit the model there first, then this page.

The tags are the model's own:
- **[OBS]**: observed, in real games or from players.
- **[DER]**: derived from mechanics or data we hold.
- **[HYP]**: reasoned and not yet tested.

## The objective

**The aim is a more favourable relative resource state than the opponent's**: both teams' answer
pools, so that your next commitment has a better chance of deciding the game. A go is one way to
change that state, not the point of the game. Pressure, positioning, clean defensive trades and
surviving a go cheaply are others, and a go sent just because one is available can lose the
exchange. [DER] (Part 20)

It is a lens, not a full explanation of winning: positioning, comms, target choice and execution
still decide games. And a log shows what happened, not what should have been done: that part is
always an interpretation. (Part 20.5)

## Vocabulary

**Buttons, by what they do**

- **Offensive cooldowns:** raise your damage for a window (Avatar, Combustion). The analysis
  counts a cooldown as a commitment from 45 seconds up.
- **Defensive cooldowns:** reduce or prevent damage on one player (Pain Suppression, Ice Block).
  Short ones that still matter, like Feint, are listed by hand (`short-defensives.json`).
- **Mixed:** buttons that can be either, judged by how they are pressed. Avatar is classified
  mixed and is used offensively in play; Vanish is read press by press (`contextual-cooldowns.json`).
- **Crowd control:** anything with a DR category. Only stuns, silences, disorients and
  incapacitates **lock someone out**; a root or slow does not stop a healer healing.
- **Peel:** crowd control used to save a teammate rather than set up a kill.
- **Interrupts:** a kick, worth most on the healer or inside your own go.
- **Trinket / Medallion:** breaks crowd control. It only buys time to press something else (Part 2).
- **Utility:** dispels, mobility, immunities, everything else.
- **Buffs:** effects you apply that change what a button is worth, such as an offensive buff
  aligned with a cooldown.
- **Passives:** never pressed, but they move thresholds: procs, damage reduction, execute ranges.
- **Talents:** decide which buttons exist at all. Chosen per matchup; some sit on a choice node,
  so you get one or the other (Part 13).

**The ideas the structure is built on**

- **Answer pool:** the list of buttons each enemy still holds: trinket, personals, healer
  externals, escapes, immunities. A kill is damage against that list, not against a health bar.
  [OBS] (Part 2)
- **Globals:** each player's casts. A go is judged by how few free casts it leaves the enemy.
  [OBS] (Part 5)
- **DR window:** about 18 seconds before a crowd-control category is at full value again. Good
  players count in DR windows and goes, not seconds. [OBS] (Part 7)
- **Cadence:** the period your buttons are lined up on ("every 30 seconds, off Maim"). [OBS] (Part 7)
- **Dampening:** healing, and this patch defensives too, weaken as the round goes on, so the
  answer pool loses value over time. [OBS] (Part 12)

## Structure

A match is a loop, not a straight line: **setup → go → answer → reset → repeat**, until a go
kills or dampening decides it. A reset can turn straight into a go, and an answer can become your
go. [OBS] (Part 1)

### 0. Before the gates

- Decide which side the clock favours in this matchup: playing for the go, or playing the clock.
  [OBS] (Part 12)
- Pick talents for the matchup. [OBS] (Part 13)
- Plan the opener: which global lands on whom, at the same moment. Openers are worked out between
  games, not looked up. [OBS] (Part 5)

### 1. Setup (before damage)

- Have resources ready so the go is not delayed: bleeds up, combo points built. [OBS] (Part 9)
- The narrower your damage window, the more setup happens before it opens: pre-crowd control
  (sap, gouge), standing on the target. [DER] (Part 8)
- Force hidden information: bait the kick, call it, cast into the gap. [OBS] (Part 10)

### 2. Go

- **A go is committing scarce buttons to change their answer pool.** [OBS] (Part 1)
  - A **kill go** is into an empty or near-empty list.
  - A **strip go** is into a full list, to force answers out for the next go.
  - Both are correct when planned as what they are. (Part 2)
- The kill target is whoever's list is shortest right now, chosen again every go. [DER] (Part 2)
- The best shape leaves **no good reply**: crowd control on the healer and on the target's helper
  on the same global, so every answer is unavailable, too slow, or too expensive. [OBS] (Part 5)
- Crowd control on an enemy under their own offensive cooldown pays twice: it stops their damage
  and starts your go. [OBS] (Part 6)
- *What the analysis measures:*
  - each go as a chain of links, tight when each is within 5 seconds;
  - the hardest 6 seconds of damage, and whether their healer was locked out or kicked during it;
  - the defensives it forced;
  - whether it led to a kill within 30 seconds.

### 3. Answer (their go, or yours being answered)

- Answer cheapest first, and say what each defensive is being saved for. [DER] (Part 2)
- **Overlap is the most expensive mistake:** two answers on one threat. You are not allowed to
  play again until those buttons come back. [OBS] (Part 3)
- The fix is pre-agreed triggers: **"enemy presses X → you press Y"**, per matchup. [OBS] (Part 3)
- A short defensive against long offensives: use it to move, then layer peel or line of sight on
  top. [OBS] (Part 3)
- *What the analysis measures:*
  - defensives spent outside their goes;
  - overlaps, owned by whoever pressed the second one;
  - their go set beside your team's answers: ready and never pressed, pressed, or on cooldown when
    it began;
  - the healer's state at each death.

### 4. Reset (between goes)

- A reset is not neutral: both teams recover at different rates, so it favours whoever the new
  state favours. [DER] (Part 20.4)
- Keeping a button and spending it both cost something: kept, it is available for a better use;
  spent, its recharge starts. "Use it early" stays right for most players. [DER] (Parts 20.3, 6)
- Count what comes back first, in DR windows and goes ("one more DR before they have trinkets").
  [OBS] (Part 7)
- Leave a target only for a named reason. Ask: does disengaging force them to spend something?
  [OBS] (Part 9)
- Damage has a third job between goes: pressure spends the enemy healer's casts on healing instead
  of crowd control. Where that stops being worth it is unknown. [HYP] (Part 4)
- A broken link costs the next go too: line your cadence up again before retrying. [OBS] (Part 7)
- Feeling stuck with no pressure (the "dead zone") is a symptom: the go did not carry enough
  crowd control to force anything. [OBS] (Part 9)

### 5. Repeat, and the clock

- Be patient inside a window. Rushing it is a failure even world champions make; sitting on it
  until their answers return is the opposite failure. [OBS] (Part 8)
- Dampening makes the same answer pool worth less every minute, so the side the clock favours
  gains with each reset. [OBS] (Part 12)

### 6. The kill

- A kill is an empty list plus enough damage. It becomes computable through **memorised
  thresholds**, not calculation mid-game ("Touch of Death below 15%, can't be dodged"). [OBS] (Part 11)
- *What the analysis measures:*
  - the killing blow and who did the damage in the last 10 seconds;
  - whether the healer was locked out;
  - how long the go had run;
  - the defensives used in the last 30 seconds.

## What none of it can see

Positioning, and what was said on comms. The model names both as gaps. [OBS] (Part 14)
