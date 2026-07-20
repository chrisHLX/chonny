# Module Upload Format

Used by the "Upload Module" tool (`/modules/upload`) to create a Module + its quiz questions from
two pasted/uploaded files instead of typing everything into the dashboard forms by hand. **Claude:
use this exact format whenever asked to draft a module's content or questions for upload** — don't
invent a different shape.

No filesystem placement or naming convention matters here — these are uploaded through a web form,
not read off disk. Any filenames are fine.

## Two files

1. **Content file** (`.md`) — front matter + the module's page-1 markdown body.
2. **Questions file** (`.yaml`, optional) — the quiz question bank for this module. A module can
   be uploaded with no questions file at all (knowledge-only content, no quiz yet).

## Content file

```yaml
---
title: "Arena Fundamentals"
subject: "World of Warcraft: The War Within"
proficiency: "Beginner"
---

# Arena Fundamentals

...markdown body — this becomes page 1 of the module, shown to learners...
```

- `title` — required. Becomes the Module's name and the page-1 title.
- `subject` — required. Must **exactly** match an existing Subject name (case-sensitive) — e.g.
  "World of Warcraft: The War Within", "StarCraft 2", "League of Legends", "Poker". Check
  `/modules/create`'s subject dropdown if unsure of the exact string.
- `proficiency` — required. Must exactly match an existing Proficiency name **for that subject**
  (tiers vary per subject — e.g. "Beginner", "Casual"). Check the subject's proficiency tiers in
  the admin content manager if unsure.
- Everything after the closing `---` is stored verbatim as Markdown.

## Questions file

```yaml
questions:
  - type: mcq
    question: "What is the primary win condition in most arena matches?"
    difficulty: easy        # optional: easy | medium | hard (default: easy)
    skill_type: recall      # optional: recall | analysis | application (default: recall)
    concepts: ["Role Fundamentals"]   # optional — must match existing Concept names for the subject
    answer:
      correct: "Killing the enemy healer"
      options: ["Killing the enemy healer", "Highest damage", "Surviving the timer", "Using CC most"]

  - type: true_false
    question: "Crowd control creates pressure by preventing the enemy from acting."
    answer:
      correct: true

  - type: ordering
    question: "Place the following steps of a kill attempt in the correct order."
    answer:
      steps: ["Confirm cooldowns ready", "Apply CC to the healer", "Commit offensive cooldowns", "Secure the kill"]

  - type: matching_pairs
    question: "Match each concept to its definition."
    answer:
      correct: { "Pressure": "Forcing the enemy to spend resources", "Tempo": "Who controls the pace" }
      pairs:
        keys: ["Pressure", "Tempo"]
        values: ["Forcing the enemy to spend resources", "Who controls the pace"]

  - type: open
    question: "Explain why crowd control matters in arena PvP."
    answer:
      correct_keywords: ["pressure", "cooldown", "kill window"]
      ideal_answer: "CC removes the enemy's ability to respond, converting pressure into kills."
```

**`answer` must be shaped exactly as shown per type** — it is stored as-is, with no translation.
This mirrors the app's real DB shape for each question type (see CLAUDE.md's "Question Answer
JSON Shapes" section) — don't invent a different structure.

Recognized `type` values: `mcq`, `true_false`, `matching_pairs`, `ordering`, `open`.

## Validation — all-or-nothing

The whole upload is rejected with a list of every problem found if anything is invalid — nothing
is written to the database until everything passes:

- `title`, `subject`, `proficiency` are required in the content file's front matter.
- `subject` must match an existing Subject (exact name).
- `proficiency` must match an existing Proficiency for that resolved subject.
- Each question's `type` must be one of the five recognized values above.
- Each question's `concepts` (if given) must match existing Concept names for that subject —
  a typo'd concept name fails the whole upload rather than silently being dropped or inventing a
  new Concept row.

## Re-uploading

Uploading again with the same `title` + `subject` updates that module in place — page-1 content is
replaced, and the question bank is fully replaced to match whatever's in the new file (not merged
or appended). Safe to fix a typo and re-upload; it does not create a duplicate module.

## After upload

- The module is created **unpublished**; `status` becomes `ready` once it has at least one
  question attached.
- Click **Try Module** (on the upload-success screen, or from `/modules/manage`) to enroll
  yourself and run through the real quiz before showing it to anyone else.
- Once satisfied, use **Assign as Next Step** (admin only, on the module's edit page) to put it
  directly in front of a specific user — this does not require the module to be published first.
  Publishing (via the checkbox on the module's edit page) is a separate decision about whether it
  should also appear in the general public browse list / recommendation pool.
