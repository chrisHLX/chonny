# Cooldown Graph Engine — summary from a Gemini session

**Who / what:** ChrisO, in conversation with Google Gemini, 2026-09-23. ChrisO went in
asking what *other fields of inquiry* relate to `arena-structure.md` — game theory and
similar — and the model proposed several. The graph framing was the one he found worth
keeping, and asked for it to be built.

**Provenance, and why it is a weaker tier than the other sources in this folder.** This
is **not** a player account. Kalvish's transcript is a world champion explaining games he
just won with a VOD in front of him; the v1 review is a Gladiator correcting a document
from his own experience. This is a language model reasoning out loud, and one that had
`arena-structure.md` in its context — so where it agrees with the model, that is not
independent corroboration, it is the model being read back. Everything here is
**[HYP] until something else confirms it**, and the distilled note says so per section.

**Why it is worth keeping anyway:** it is a *formalisation* proposal, not a claim about
how arena is played. Its value is in the shape it proposes (two curves, their
intersection, the four named obstacles) rather than in any assertion about the game. A
formalisation can be judged on whether it is computable from data this project holds,
which is a question that does not need a pro to settle.

**Standing caveat:** no patch, no bracket, no format. It inherits whatever
`arena-structure.md` was as of 2026-09-22 and adds reasoning on top.

---

Here is a clear breakdown of everything we have explored, organized into what we are building, why it matters, how it works, and where the unresolved problems lie.

---

## 1. What We Are Trying to Do

We are designing a **dynamic, graph-based strategy engine** for World of Warcraft 3v3 arena guides on **MindCollector**.

Rather than relying on generic text guides that get ignored or confused, the goal is to generate **visual resource graphs and precise JSON-driven tactical guides** that prove *why* and *when* a team wins or loses a matchup.

---

## 2. Why We Are Doing It

* **WoW Arena is a Temporal Resource Game:** Arena isn't just about health bars or static rotations. It is an asymmetric game of **recharging resource loops (cooldowns)**, **30-second setup windows (DR cycles)**, and **combat timing**.
* **Traditional Guides Fail:** Standard guides treat WoW like PvE (stating *"use X when Y happens"*). They fail to model how trading a short cooldown for a long one creates a "ticking clock" that causes a team to lose two minutes into the future.
* **AI Hallucinations Need Guardrails:** LLMs (like Claude, Gemini, or Opus) don't naturally understand high-level arena play. By combining a core philosophy document (*The Brain*), a hardcoded, immutable spell database, and a strict JSON schema, we can force models like Gemini 2.5 Flash or Claude Opus to act as precise logic engines without hallucinating mechanics or spells.

---

## 3. The Core Concept: The Cooldown Graph Engine

The engine models an arena match (from 0 to 300 seconds) as two overlapping state curves:

1. **The Offense Curve (Threat Spikes):** Spikes upward every 30 seconds (DR resets / minor setups) and reaches massive peaks every 90–120 seconds (major offensive CDs + cross-CC).
2. **The Defense Curve (Answer Pool):** Drops every time a team spends a defensive CD (Trinket, Pain Supp, Turtle) to flatten an incoming threat spike.
3. **System Collapse (The Red Kill Window):** When an Offense Spike occurs while the enemy's Defensive Capacity line is near zero (their "Answer Pool" is empty), the lines intersect. This visually proves the exact window where a kill is mathematically guaranteed.

---

## 4. Where There Are Still Unsolved Problems (The Obstacles)

While the math works well on paper, simulating real human arena play into a clean graph introduces several complex edge cases that still need to be solved in the system's logic:

### Problem 1: Branching Decision Paths (Mitigation vs. Control)

* **The Issue:** A threat like Warrior *Avatar* can be answered in multiple ways—by spending a major defensive (*Pain Suppression*), applying crowd control (*Disarming/Stunning the Warrior*), or kiting (*Leaping away*).
* **The Need:** The engine cannot assume a rigid 1-to-1 answer (e.g., "Avatar ALWAYS means Pain Supp"). It requires a **Priority Cascade** that evaluates low-cost control options first, falling back to high-cost defensives only if the defender is locked down in CC.

### Problem 2: Human Reaction Time & CC Stagger

* **The Issue:** Computer models assume instant, perfect trades. Real players have a 0.5–2 second reaction lag, or they get caught in a stun *before* pressing a button.
* **The Need:** The graph engine must incorporate a **"CC Stagger Window."** If a healer is trapped, their defensive line must be artificially suppressed to $0$ until they trinket or the CC expires.

### Problem 3: Dynamic Cooldown Reductions (CDR)

* **The Issue:** Modern WoW abilities rarely have static timers. Passives, resource spenders, and talents constantly reduce cooldowns during live play (e.g., a 60s CD becoming a 38s CD).
* **The Need:** The engine cannot rely on static cooldown array numbers. It needs a dynamic tick-rate logic that adjusts recovery curves based on spending rates.

### Problem 4: Cognitive Overload & Panic Overlaps

* **The Issue:** High-level teams trade 1-for-1 efficiently. Mid-tier teams panic under high pressure and overlap defensives (e.g., pressing both *Karma* and *Pain Supp* for the same push).
* **The Need:** Introducing an **"Execution Efficiency/Rating Slider"** into the model to simulate suboptimal play and wasteful defensive depletion.

---

## Next Operational Step

To move from theory to execution, the next step is building the **JSON Schema & Data Model** that enforces this "Priority Cascade" and "Time-Step Logic," allowing models like Gemini 2.5 Flash or Opus to generate precise, hallucination-free match simulations.

---

## ChrisO's framing when he brought it over

> this is from a chat with gemini, I was trying to figure out if there were other fields of inquiry that are related to our arena structure md like game theory etc. We found or rather it suggested a few things and I found the graph idea the most interesting. Can you build this as a part of our app that a player can enter classes and the system can use this to predict the better or most likely team to win or lose based on the graph or decisions made?
>
> I don't know if thats possible but I think its the part that we don't really have for the guides. If we have a way of understanding who is most likely to win and why then the guide writing can have different spectrums to write the guide against
>
> This is also a good document for our brain and I think it should be kept in the folder where we keep other documents like this. There is overlap with our arena structure.md but this was a summary from a chat that used that document.
