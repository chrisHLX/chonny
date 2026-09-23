# Writing a machine guide

How to draft a comp guide for `data/machine-guides/`. Short on purpose — if this file gets
long it has stopped being usable for the same reason the guides did.

**Read first:** `arena-structure.md` (the model), and
`docs/guides/reader-corrections-2026-09-23.md` (what the last 16 got wrong).

---

## The one rule

**The sequence and its notes are the guide. Everything else is overhead.**

From the reader, on the last batch:

> "The prose and reasoning is too long. The actual sequence + notes makes a lot more sense."

> "That's a great point — it's just buried in prose that's mostly useless."

A heading that states the plan lands. A step note that names a specific interaction lands.
The paragraph between them does not get read.

---

## Budget — hard limits

| Part | Limit | Why |
|---|---|---|
| `summary` | **160 chars** | One line. Who dies and what beats you. |
| `text` sections | **2 per guide, 400 chars each** | If it needs a third, it belongs in a step note. |
| step `note` | **90 chars** | One fact or one condition. Not a sentence about a sentence. |
| Whole file | **~2,500 chars** | The 2026-09 batch averaged 7,900. |

Run `php artisan guides:author <file> --dry-run` before publishing — it resolves every
ability name and flags talent conflicts.

---

## Shape

```json
{
  "slug": "rmp-vs-tsg",
  "title": "RMP vs TSG",
  "summary": "One line: the target, and the thing that beats you.",
  "team":  ["rogue/subtlety", "mage/frost", "priest/discipline"],
  "enemy": ["warrior/arms", "deathknight/unholy", "druid/restoration"],
  "sections": [
    { "kind": "text", "title": "Kill the Warrior", "body": "..." },
    { "kind": "sequence", "title": "Opener", "steps": [
      { "spec": "rogue/subtlety", "spell": "Sap", "note": "On the Druid. Free — costs nothing before the go starts." }
    ]},
    { "kind": "defensives", "title": "What the Warrior answers with", "steps": [
      { "spec": "warrior/arms", "spell": "Die by the Sword", "note": "30% all damage, 8s. Back every go." }
    ]}
  ]
}
```

`kind` is `sequence` | `defensives` | `text`. Abilities are referenced **by name** and
resolved against the current patch — an unknown name fails loudly rather than shipping a
broken step.

---

## What a guide must contain

Five things, from Part 16 of the model. Most belong in a heading or a note, not a paragraph.

1. **Who dies, and why — ranked, never a single verdict.** Count answers per enemy player
   and say the count. If the comp can kill anyone, say *that*, because naming one target
   throws away what makes it strong.
2. **What the go denies.** Which of them is left with a free global, and what they do with it.
3. **The period.** "This repeats every 30s off Maim." State the slowest aligned term.
4. **Which side the clock favours.** Decides whether a failed go is survivable.
5. **What must be true before the go starts.** Pre-CC down, resources built, position held.

---

## Step notes — what a good one looks like

A note says **why this step, here** in one clause. Condition, cost, or interaction.

✅ `"Fresh category after the Sap — first control on the Druid at full duration."`
✅ `"Only if he has no trinket. Otherwise this is a strip."`
✅ `"30% all damage, 8s. Back every go."`
✅ `"Instant — lands the same global as the stun."`

❌ `"This is an important part of the sequence because it sets up the following steps and
   allows the team to establish pressure while denying the enemy healer the opportunity to
   respond effectively."`

If the note is explaining the *idea* rather than the *step*, it belongs in the heading.

---

## Headings do work

The reader singled one out: *"the heading is excellent, explains the game plan clearly."*

Write headings as claims, not labels. **"Kill the Warrior, expect two goes"** over
**"Kill target"**. **"Keep the healer out — the real chain"** over **"CC chain"**.

---

## Errors the last batch made — do not repeat

**Check the kit before you write the step.** Most of these were in the data already:

- Count **every** answer. Rallying Cry was missed twice.
- Read the tooltip. Die by the Sword is 30% all damage, not a parry effect.
- Charges matter. Pain Suppression is 2 charges on 2 minutes.
- Mobility is an answer. Blink out of the stun, Heroic Leap away, Disengage, Shadowstep.

**Two abilities on one choice node cannot both be taken.** Shadowfury/Howl of Terror,
Strangulate/Asphyxiate, Mighty Bash/Incapacitating Roar. `--dry-run` catches these.

**Some spells cannot target a player at all.** Banish (demons/elementals), Shackle Horror
(pets/NPCs). Nothing in the pipeline catches this — it is on you.

**Do not open on a hard cast.** *"It's really hard to land casted spells from nothing — an
easier approach would be stun, trap, into something."*

**The enemy also has CC, and control breaks on damage.** A plan that assumes they stand
still is not a plan.

**Do not build on an ability nobody plays.** Mighty Ox Kick was in a plan and has never been
seen taken.

---

## Voice

**Propose. Do not hedge, and do not pretend.**

The model's Part 0 splits claims by confidence, not permission: a ranked kill target is
something to state with its reason attached, not something to avoid. Being specific and
wrong is useful here — the guides exist to be corrected. Being vague is not.

So: say which player dies and why. Say what you are unsure of in the one place you are
unsure of it, in a sentence. Do not spread the uncertainty through every paragraph.

Never write a number the data cannot produce. `(varies)` and unresolved formula text must
never reach a reader.

---

## Publishing

```bash
php artisan guides:author data/machine-guides/x.json --dry-run   # resolve + talent check
php artisan guides:author data/machine-guides/                   # the whole directory
php artisan guides:export-feedback --all --out=feedback.md        # run on the SERVER
```

Idempotent by slug: re-running replaces sections and steps while keeping the guide row, its
URL and its view count.

**But re-authoring destroys anchored reader comments.**
`user_guide_comments.user_guide_section_id` / `user_guide_block_id` are `cascadeOnDelete`
and sections are replaced wholesale, so every note attached to a section or a step dies.
**Export the feedback and commit it before re-authoring anything** — that export is the only
copy, and those corrections are the whole return on publishing.
