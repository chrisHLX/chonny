# Claude's Guides

A deliberately isolated, experimental folder — everything under `data/claudes-guides/` and the
pages that read it (`/claudes-guides`, `App\Livewire\ClaudesGuides`; `/burst-guides`,
`App\Livewire\BurstGuides` — see "Burst Guides" below) is self-contained by design, so this can
be tried, changed, or thrown away without touching any other system on the site. Nothing outside
this folder writes to it, and nothing outside `ClaudesGuides.php`/`BurstGuides.php` reads it.

## What this is

An experiment in the opposite direction from the Canonical Context Module Template (see
CLAUDE.md): those modules come from a real Gladiator-rated player's own dictation, structured and
fact-checked afterward. These guides go the other way — starting from real data already sitting
in this project (the arena-log pipeline, the precomputed spell kits, `arena-structure.md`'s
framework) and layering **my own reasoning** on top, with no human dictation involved at all.

**This is explicitly NOT the same trust tier as a dictated canonical module.** Every game *fact*
in a guide (cooldowns, categories, real observed cast sequences) is exactly as reliable as
anywhere else on the site — it's read live from the same database and the same precomputed files
everything else uses, via `App\Http\Services\SpecKitComputer`/`ArenaLogService`, never frozen or
duplicated. But the *analysis and framing* — which cooldown matters most, how a sequence should be
read, what a defender should conclude — is AI-synthesized, not expert-verified. Each guide says so
directly, and the site never presents it with the same confidence as the Discipline Priest or
Feral Druid modules.

## How a guide is built

1. Check what real data already exists for the class/spec first (`data/arena-logs/playstyle/`,
   `data/arena-logs/rotations/`, `data/spell-kits/`) before pulling anything new.
2. If a fresh pull genuinely is warranted, run it — but per standing instruction, any new match
   data pulled this way gets the SAME downstream treatment every other match pull does
   (`php artisan wow:refresh-match-derived`, see that command's own docblock) so Burst Windows /
   Class Guide / CC chains for that spec benefit too, not just this one page. A guide's own
   `dataSources` block (see the JSON shape below) records whether this happened and when.
3. Write the guide as a single JSON file, `data/claudes-guides/{classSlug}/{specSlug}.json`.
   Prose sections carry the reasoning; spell-highlight sections reference real spell_ids only
   (never a name or a frozen number) so the page always renders current, live game data — same
   "don't freeze what will go stale" discipline as the Canonical Context Module Template's own
   per-page ability tables.
4. The real burst-window sequence is never hand-copied into a guide file — `ClaudesGuides.php`
   reads `data/arena-logs/rotations/{class}/{spec}.json` directly, the exact same file and
   resolution path (`ArenaLogService::resolveWindowSteps()`) Class Guide/Burst Windows already
   use, so it can never drift from what the rest of the site shows for the same spec.

## Guides so far

All eleven were built entirely from data already on file (existing `data/arena-logs/playstyle/`,
`rotations/`, and `spell-kits/` output) — none needed or performed a fresh arena-log pull, so
`wow:refresh-match-derived` wasn't triggered by writing any of them. Each guide's own `dataSources`
block records this. Class/spec selection for guides 2–11 was mine (asked to "pick the classes"
both times) — chosen deliberately for role/mechanic spread across two batches of five: the first
batch covered a control caster, a healer, a pet/attrition melee, a DoT-detonate caster, and a
high-tempo melee; the second added a DoT caster/healer contrast pair, a second healer for a direct
healer-vs-healer comparison, a pure execute-based melee, the widest CC kit in the series, and the
highest-rated sample in the series.

- **Rogue / Assassination** — `rogue/assassination.json`. 10 matches, rating 2422–2679.
  Cross-references `arena-structure.md` Part 14 (the same spec's kill-window worked example from
  earlier this project's history) rather than re-deriving that analysis from scratch. First guide
  in the series; establishes the template every guide since follows. Its real burst window
  (Kingsbane → Envenom → Crimson Tempest → Mutilate → **Kick** → Envenom → Mutilate → Mutilate →
  Envenom) already contained the cross-spec pattern documented below — just not called out as such
  until several guides later; the guide's own text was amended afterward to say so.
- **Mage / Frost** — `mage/frost.json`. 10 matches, rating 2952–3111. A control-first spec, the
  structural opposite of Rogue's damage-and-control fusion — real per-talent data shows several
  utility/reset talents going entirely unused across the sample, and the real burst window
  includes a Counterspell mid-sequence.
- **Paladin / Holy** — `paladin/holy.json`. 10 matches, rating 2272–2705. The first healer in the
  series, with an adapted structure (defense-first framing rather than a kill-conjunction one).
  Real data surfaced a genuinely surprising finding: the spec's own highest-*damage* window is a
  melee-weave sequence (Crusader Strike/Denounce), not a healing one — a direct consequence of
  burst-window detection being damage-event-based, honestly flagged as such rather than
  over-claimed as "how Holy Paladin heals." One of only three guides in the series with no
  control ability inside its burst window.
- **Death Knight / Unholy** — `deathknight/unholy.json`. 10 matches, rating 3022–3123. Written
  strictly against this patch's actual reworked kit (Putrefy, Festering Scythe, Necrotic Coil —
  all checked against real imported spell descriptions, not older memory of the spec) rather than
  assumed prior knowledge. Real data suggests Asphyxiate (a strong stun) is being held for a
  specific moment rather than spent reactively.
- **Warlock / Destruction** — `warlock/destruction.json`. 10 matches, rating 2218–3062 (the widest
  spread in the first batch). Confirmed via the spell's own description that Malevolence is
  explicitly a Wither-detonator, not an independent burst cooldown — real data also flags Rain of
  Fire as taken every match but never once cast in the sample, a genuinely useful "AoE tool, wrong
  format" finding.
- **Monk / Windwalker** — `monk/windwalker.json`. 10 matches, rating 2240–2708. The highest-tempo
  spec in the first batch — its real burst window packs 13 casts into 12 seconds.
- **Priest / Shadow** — `priest/shadow.json`. 10 matches, rating 2178–3020. A DoT-attrition spec
  (closer to Unholy DK's model than to a single-cooldown burst spec) — its real burst window
  includes both a Silence and a defensive cooldown (Fade) woven directly into the sequence.
- **Shaman / Restoration** — `shaman/restoration.json`. 10 matches, rating 2235–2757. The second
  healer in the series, deliberately written to contrast against Holy Paladin — where Paladin's
  highest-damage window turned out to be pure melee-weave with no healing at all, this spec's own
  highest-damage window still drops a real healing totem in the middle of it. One of only three
  guides in the series with no control ability inside its burst window (Purge, a dispel, appears
  instead — a different kind of non-pure-damage inclusion).
- **Warrior / Arms** — `warrior/arms.json`. 10 matches, rating 2553–3111. The most mechanically
  direct spec in the series (no pet, no DoT, no resource puzzle) — its real burst window is the
  densest pure-melee sequence found so far (14 casts in 12 seconds), and includes both an
  interrupt (Pummel) and a Stun (Shockwave).
- **Druid / Balance** — `druid/balance.json`. 10 matches, rating 2173–2894. The largest real CC
  kit in the series (8 abilities across 5 DR categories) — its burst window is the strongest
  version of the cross-spec pattern found anywhere in this series: **two** different control
  abilities (Cyclone and Mighty Bash) inside the same measured window, not just one.
- **Demon Hunter / Havoc** — `demonhunter/havoc.json`. 10 matches, rating 3035–3123 — the
  highest-rated sample and highest-measured-damage burst window (over 2 million) in the whole
  series. Also the clearest exception to the cross-spec pattern: its burst window has no control
  ability in it at all, honestly recorded as such rather than smoothed over.

**A cross-spec pattern, noticed while writing these, not assumed going in:** counting all eleven
guides, **8 of 11** real burst windows include a control or interrupt ability inside the actual
highest-*measured-damage* window, not just damage abilities — Rogue (Kick), Mage (Counterspell),
Death Knight (Blinding Sleet), Warlock (Fear), Monk (Spear Hand Strike), Priest (Silence), Warrior
(Pummel + Shockwave), and Druid (Cyclone + Mighty Bash, the only guide with *two*). Only three
don't: Paladin and Restoration Shaman (both healers, whose windows include non-damage activity of
a different kind — melee-weaving and a healing totem, respectively, rather than control) and
Havoc Demon Hunter (a clean, pure-damage exception). Flagged as a real, recurring observation worth
treating as a working hypothesis for any future guide in this series — not asserted as a universal
law from an eleven-spec sample, but consistent enough across this many independently-picked specs
to be worth taking seriously rather than dismissing as noise.

## Burst Guides (added 2026-09-04, rebuilt 2026-09-06)

A second, separate page/subfolder, sharing this parent folder but otherwise independent of the
eleven hand-written guides above — `/burst-guides`, `App\Livewire\BurstGuides`, reading
`data/claudes-guides/burst-guides/{class}/{spec}.json`. One plan per spec, for every spec with
real rotation data (34 currently) rather than only the 11 with a hand-written guide — computed,
never authored.

### What it answers

For a given spec: **how long its go actually lasts, how many globals fit inside it, and what to
press in order** — grouped into the four things a burst actually consists of:

- **Set up** — what you land before committing, so the damage can't simply be healed or walked
  away from.
- **Commit** — the cooldowns you stack together. The window starts here.
- **Execute** — what you spend the window on.
- **Fill** — what takes every global left over, with how many times per window it's really used.

Plus **Also pressed** — things that genuinely show up in real windows but aren't part of dealing
damage (mobility, defensives, utility), surfaced rather than silently dropped.

### How it's built

`php artisan wow:build-burst-guides` → `App\Http\Services\BurstGuideBuilder`. Read that class's
docblock for the full derivation; the essentials:

- **It aggregates the whole corpus, not one window.** `{ARENA_LOG_ARCHIVE_PATH}/rotations/{class}/
  {spec}.jsonl` holds every real archived burst window for a spec — 200–1900 of them across dozens
  of matches. Every figure is a per-ability rate across all of them (how often it appears, its
  median timing, casts per window), so a step earns its place by being *typical*.
- **Timing comes from the data.** Each spec's own global cooldown is measured from real cast
  cadence (the method in `data/arena-logs/GCD and Go Analysis.md`), and every step's position is
  its median offset from the spec's biggest cooldown. Phases follow from those offsets — nothing
  assigns them independently.
- **Window length prefers a real buff duration** from the cooldowns stacked at the start, since
  that IS the window as a matter of game mechanics — but only when the data doesn't contradict it
  and it's within a plausible bound, otherwise it falls back to the measured spread. Which basis
  was used is stored (`goLengthBasis`) and the page says so.
- **Control placement is measured from real matches**, not curated: `wow:analyze-cc-targeting`
  scans every archived log for control landing on an opposing player, resolves that player's spec
  from the match metadata, and reports how often each ability is used on the enemy healer —
  normalised against chance, because in 3v3 an ability spread evenly hits the healer a third of the
  time. 110,772 real applications give a usable sample for 81 of 132 CC-tagged spells. The measured
  share is shown on the card, so the evidence is visible rather than just a verdict. Falls back to
  the curated `spells.chain_target` where there is no sample, and to a `dr_category` inference
  beyond that; which tier was used is always marked. `dr_category` still constrains the label —
  control that breaks on damage can't hold the target you're bursting, so a low healer rate there
  means incidental AoE catching (peel), never "use it on your kill target".
- **Nothing is hand-authored per spec.** The only English on the page explains what a phase means,
  never what a particular spec should do.

**Why it was rebuilt on 2026-09-06.** The original version replayed the single highest-damage
30-second window from ONE match, minus defensives, truncated the moment a 2+-step block repeated.
Direct user feedback — that it "didn't really understand the mechanics and relied on examples
only" — was correct on both counts. One anecdote from one player became the guide (including a
mid-burst re-stealth), and the truncation rule's blind spot for single-ability repeats left
Assassination Rogue's committed output ending `Ambush, Ambush, Ambush, Ambush`. The replaced
implementation, `ArenaLogService::buildBurstGuideSequence()`, was deleted rather than left dead.

**What's stored vs. resolved live:** spell_ids plus measured statistics only (presence, casts per
window, median offset, role, phase) — never a frozen name, cooldown or duration, same discipline
as every other file in this folder. `App\Livewire\BurstGuideClassBlock` resolves each id against
live game data at render time.

**Build-time only.** The corpus it aggregates lives in the arena archive and is deliberately not
committed here (hundreds of megabytes across 38 specs) — only the small computed result is. A
spec with no corpus is reported and skipped, never silently degraded to a weaker source. The
command also prunes guides for specs that no longer have promoted rotation data; without that, the
four specs culled from the archive on 2026-09-05 kept serving guides built from deleted matches
(caught by the test suite, not by inspection).

**Page shape — a thin parent + 13 lazy-loaded per-class children, not one all-at-once render**
(2026-09-04, after direct user pushback on an inflated/misleading memory-cost report — see
CLAUDE.md for the full trace). `App\Livewire\BurstGuides` only lists which class slugs have data
on disk and mounts one `<livewire:burst-guide-class-block lazy/>` per class (Livewire 3's
`#[Lazy]`). `App\Livewire\BurstGuideClassBlock` carries the real per-class resolution, scoped to
one class's 2–4 specs, with its own `spellCacheVersion()`/`deployedCodeFingerprint()`-keyed cache
so viewing one class never invalidates another. It deliberately does NOT reuse
`SpecKitComputer::resolveEntriesForSpellIds()` — that path's relation-hydrating rehydration was
measured at ~430MB peak across all specs; it reads each spec's precomputed kit file directly as
raw JSON for the same already-talent-computed numbers.

**Kept fresh automatically:** `php artisan wow:refresh-match-derived` runs `wow:build-burst-guides`
as its final step, right after regenerating and promoting rotation data.

**Tested against the committed output.** `BurstGuideBuilder` can't run in CI (no corpus), so
`tests/Feature/Livewire/BurstGuidesTest.php` asserts the invariants every committed guide must
satisfy — exactly one anchor, sequence ordered by median offset, phase agreeing with the timing it
was derived from, globals following from window ÷ GCD, GCD within real bounds, and a minimum
window count so a guide can never silently regress to an anecdote.
