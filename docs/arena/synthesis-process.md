# How to fold a new source into the arena model

The process for turning a piece of prose — a pro's video transcript, an interview, a
coach's write-up, your own dictation after a session — into changes in
`arena-structure.md`.

**Say "synthesise `<file>`" and this is what runs.** It is written down because the
value compounds only if every source is processed the same way; a source read once and
paraphrased is a source lost.

---

## The shape

```
docs/arena/sources/<name>.md            raw, verbatim, never edited
docs/arena/sources/<name>-distilled.md  the source note — quotes kept, noise dropped
arena-structure.md                      the model. Changes only via a distillation.
arena-open-questions.md                 what the source raised and could not settle
```

Three rules that make it work:

1. **The raw file is never edited or deleted.** Re-distillation happens against it. A
   later question ("did he say anything about healer globals?") is answerable only
   from the raw.
2. **The model changes only through a distilled note.** No going straight from a video
   to a rule. The note is the audit trail — every claim in `arena-structure.md` should
   be traceable to a quote in a distilled file or to the player's own review.
3. **The distilled note keeps the exact words.** Paraphrase loses the thing that made
   the source worth having. `kalvish-blizzcon-2026-distilled.md` is 12KB of mostly
   quotation and that is correct.

---

## Step 1 — Land the raw file

Drop it in `docs/arena/sources/<who>-<event-or-date>.md`. No cleanup, no reformatting,
timestamps and transcription errors left in. Add a header if it has none: who, what,
when, and **why this source is worth reading** — a highlight reel is not the same
kind of evidence as a post-hoc explanation of a plan that worked.

## Step 2 — Read it whole, once, before writing anything

No skimming for keywords. The Calvish opener insight was in an aside about a
teammate's question after a loss; a keyword search for "opener" would have found the
wrong paragraph. The first pass is for understanding what this person believes, not
for harvesting quotes.

## Step 3 — Write the distilled note

Fixed shape, so notes are comparable across sources:

- **Header** — who, what, when, why this source is good, and the *standing caveat*:
  what meta, patch, bracket and format it comes from.
- **Numbered sections**, each = one claim. Each section carries:
  - a one-line claim, stated plainly
  - **`[confirms Part N]` / `[corrects Part N]` / `[adds — not in the model]` / `[contradicts Part N]`** — assign this before writing the body, it forces the comparison
  - the verbatim quote(s), blockquoted
  - one or two lines on what it does to the model, and nothing more
- **A "does NOT support" section.** What the source is silent on, so nobody later
  quotes it as if it covered that. This is the section that keeps the model honest and
  it is the one that gets skipped.
- **A distillation log table** at the foot — date, what was pulled, where it went.

Drop: logistics, drama, results, personalities, anything that does not change how a
game is played. Calvish's ping/ego/practice-room material is ~35% of that transcript
and none of it made the note.

## Step 4 — Diff against the model

Go through the note's section tags and act on each:

| Tag | Action |
|---|---|
| **confirms** | Retag the claim in `arena-structure.md` to say what now supports it — a `[HYP]` a source has now observed becomes `[OBS]`. **This is a change of provenance, not a promotion**: see that file's "How to read this file", which records why the tags are three kinds of support rather than three grades. Add the quote if it is better than what is already cited. If it was already `[OBS]` from one source, note that a second, independent source agrees — that is a real strengthening. |
| **corrects** | Rewrite the part. Keep the old reading visible if it was plausible; say what changed it. Never silently overwrite — the reversal is information. |
| **adds** | New part, or a new subsection. Tag it `[OBS]` if the source observed it, `[HYP]` if the source asserts it without showing it, `[DER]` if it follows from data we already hold. |
| **contradicts** | **Do not resolve it.** Record both readings in the model, and open a question in `arena-open-questions.md` naming what would settle it. A model that quietly picks the source it likes is worthless. |

## Step 5 — Update the open questions

- Strike through anything the source answered, and move the settled rule into the
  model.
- Add anything it raised and could not settle, with the `[YOU]` / `[ARCHIVE]` /
  `[DATA]` / `[SOURCE]` / `[TEST]` tag for who can close it.
- Re-check the `[SOURCE]` list — a new source usually changes what the *next* one
  should be.

## Step 6 — Report what changed

In the reply, not only in the files: what was confirmed, what was corrected, what is
new, and what the source was silent on. The last one matters as much as the first.

---

## Standing cautions

**A pro's claim is contingent on the meta they won in.** From the review:

> "What if there is a playstyle or talents that counter that idea — then he loses
> BlizzCon and we are reviewing someone else's blog."

So a source note records *what a strong player believed and acted on, in a stated
patch and format*, never a law. When a patch invalidates a claim, the note stays and
gets a dated line in its log — a belief that stopped being true is more instructive
than one that was never written down.

**One source is one team's reading of games they won.** Two independent sources
agreeing is a different tier of evidence from one source saying something twice. Say
which you have.

**Winners' accounts are survivor-biased in a specific way:** they explain why the plan
worked and rarely why the alternatives would have failed. Calvish's account of comps he
did not have to play is confident and untested, and he says so himself. Weight the VOD
review above the planning chapters.

**Prefer the archive where it can answer.** If a claim is measurable against the 689
matches, a measurement beats a quote. Open the question rather than promoting the
claim.

---

## What good looks like

The Calvish pass, as a benchmark: 97KB raw → 12KB note → 11 parts of the model changed
→ 2 new open questions → 1 claim explicitly flagged as needing verification before it
can be used. Roughly one framework change per 9KB of source. A pass that produces no
corrections has probably been read for agreement rather than for difference.
