# Machine-drafted guides

*Moved here word for word from CLAUDE.md on 2026-10-04, to keep that file to orientation and rules.*

Guides a model wrote, **published to be corrected**. The correction is the point, not the guide:
the one input the game data cannot supply is what forces what (`arena-structure.md` Parts 6, 11),
and a specific, checkable, wrong-in-places plan is far cheaper to correct than a right one is to
author. 16 drafts live in `data/machine-guides/`.

- `user_guides.authored_by_model` is a **name** ("Claude Opus 5"), not a boolean — the byline
  prints it, and a second model later is plausible. NULL means a person wrote it.
  `scopeMachineAuthored()` / `scopeHumanAuthored()`.
- **`guides:author {path}`** (`--author=mindcollector`, `--model=`, `--draft`) publishes from a
  committed JSON draft — a single file or a whole directory. **Never author a guide through
  tinker:** a guide written in a REPL exists only in that database, can't be reviewed in a diff,
  can't be re-run after a patch changes an ability, and can't be reproduced on another
  environment. **Idempotent by slug** — re-running replaces sections and steps wholesale (the
  draft file is the source of truth) while keeping the guide row, so its URL, views and reader
  notes survive an edit. Abilities are referenced **by name** and resolved to an external spell id
  once, against the current patch; an unknown name **fails loudly** rather than writing a step
  that renders "ability no longer found". Nothing about a spell is frozen in — cooldowns, DR
  maths and immunities resolve live on every page load, same as a player's guide.
- **A draft must name a build one character could actually have.** `guides:author --dry-run`
  resolves every reference and prints talent conflicts without writing a row; a real publish runs
  the same check and **warns**. `TalentFeasibilityService` flags two abilities of one spec that sit
  on the same `CHOICE` node — Shadowfury/Howl of Terror, Mighty Bash/Incapacitating Roar,
  Avenging Wrath/Avenging Crusader. The first reader caught one of these by eye ("the idea is
  correct, the spells available is not"); the check then found **four impossible plans across the
  11 published drafts**. Matched **by name**, never by id: `talent_node_entries.spell_id` is an FK
  to `spells.id` while guide blocks store the external id, and the talent entry's copy is routinely
  not the pressable copy a step resolves to. It checks choice-node exclusivity only — not point
  totals or gate rows — because that is the constraint that makes a plan impossible rather than
  merely expensive. It is a warning, not a failure: a defensives section may legitimately name an
  alternative the enemy might have taken.
- **The data does not model who a spell can be cast on.** Banish (demons/elementals) and Shackle
  Horror (pets/NPCs) both shipped in published plans as control on players, hedged as "probably
  doesn't work, but if it does it's free". The hedging was the error. Nothing in the pipeline can
  catch this class of mistake — see `knowledge-gaps.md`, 2026-09-18.
- **Re-authoring DESTROYS anchored reader comments.** `user_guide_comments.user_guide_section_id`
  and `user_guide_block_id` are `cascadeOnDelete` and `guides:author` replaces sections wholesale,
  so every note attached to a section or step dies on a re-run — 26 of 33 in the 2026-09-23 sweep.
  **Run `guides:export-feedback` and commit the output before re-authoring anything.**
- **`guides:export-feedback`** (`--out=`, `--all`) writes every machine guide, its steps and every
  note as markdown. **This is the loop**: corrections come back here and get folded into
  `arena-structure.md`, which is the only thing carrying knowledge between sessions. Markdown on
  purpose — a note is unreadable without the step it is attached to. Run it **on the server**,
  where the notes are.
- **Two different things are both called "notes", and readers were confusing them.** The
  *author's* annotation on a step (`payload.note` on the block, attributed by model name) and a
  *reader's* criticism of it (`user_guide_comments`). Keep them visually distinct — the first
  reader couldn't tell whether his note was overwriting the author's.
- **Reader comments anchor per section**, not per step. Step-anchored comments still exist, are
  still rendered and still exported; a block-anchored comment also records its section id.
  Un-anchored comments are the thread at the foot of the page. A comment's anchor is re-derived
  from the guide's own rows, never trusted from the request.
- **`/claudes-comp-guides` is not `/wow/claudes-guides`.** The first (`Guides\MachineGuides`) is
  machine-drafted `user_guides` — commentable, correctable. The second is hand-authored JSON per
  class/spec under `data/claudes-guides/`, read-only, no comments, carrying its own `"patch"`
  field unrelated to the DB.
- **Every machine-drafted guide is written from `arena-structure.md`, whose public statement is
  `/brain` (`data/brain/brain.md`, rendered by `App\Livewire\Brain`).** Before drafting a guide,
  read the model; a claim that is `[HYP]` there must not be asserted as fact in a guide. Comments
  on `/brain` are corrections to the model itself and outrank a correction to any single guide —
  they are the top of the same feedback loop `guides:export-feedback` sits at the bottom of.
  The brain document's section ids (`{#answer-pool}`) are comment anchors: **reword a heading
  freely, never change an id.**

- **Guides stay public to read; acting on one needs an account** (2026-09-29, after a same-day
  sign-up wall was reversed: a cold visitor will not sign up for content they have not sampled).
  Any reader can vote whether a guide is accurate for the current patch without an account; copying
  a guide into the planner asks a guest to sign up and returns them to the guide (sign-up now honours
  the intended URL, as login always did). Inside a Livewire component `redirect()` is Livewire's own
  redirector, not an HTTP response: store `url.intended` by hand.
- **The site admin manages machine guides** (`UserGuide::isManagedBy()`, 2026-09-28): they stay owned
  by the engine account `mindcollector` (so their URLs and bylines never change), but an `is_admin`
  user can read their drafts, edit them and publish them. A person's guide is managed by its author
  only. **`guides:author` rewrites `status` on every run**: without `--draft` it publishes, with it it
  unpublishes, whatever the admin set by hand.
- **A draft can attach each roster spec's talent build** (`"builds"`), written onto the guide's own
  slot build the way the builder's "use my character's talents" does, and shown on the page
  ("Talents this guide is written for"). Talents are referenced `Name[:rank][#node]`; `#node` is
  Blizzard's node id. **Hero-tree talents ALSO exist as copies in the spec trees under the same
  Blizzard node id** (Infliction of Sorrow: San'layn and the Unholy spec tree), so a name or even a
  node id can match two rows; keep the later row, as `resolveCombatantTalents()` does with
  `keyBy('external_node_id')`. **And a talent node id is NOT stable across environments:** the
  import merges talent nodes that share a name and keeps one, and which survives differs between
  databases built from identical files (Detox: node 101150 locally, 101090 on production). The
  guide resolver treats `#node` as a disambiguator and falls back to the name when it names exactly
  one node.
- **A guide drawn from observed play carries its level** (`evidence_level`, `evidence_games`,
  `evidence_note`, from the draft's `"evidence"` object; see `guides-from-play.md`).
- `Guides\Show` bylines a machine guide "{model} guide · drafted by a model" instead of
  "Player-written guide", and closes by saying the mechanics are derived while **the plan is a
  guess** — inviting correction. Do not let a model's draft render like a derived fact.
