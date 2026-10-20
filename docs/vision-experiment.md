# Reading a game from the screen: the vision experiment

**Status: open experiment. Two frames read, one matching spike run and failed.** Started 2026-10-20.
This file is the context a new session needs to continue it. Both frames are Twitch stills with no
combat log, so nothing here is scored against ground truth.

## The question

Everything MindCollector knows today comes from the combat log: a structured account the game
writes about itself. The experiment asks a different question.

> **What can be known about a game from the picture alone, and how reliably?**

It matters for two reasons, and they are worth keeping apart because they have different answers:

1. **As an input.** Could video be an observation source where no log exists? (Weak on current
   evidence — see "What this is not for".)
2. **As an information boundary.** The log records *what happened*. It cannot record *what the
   player could see*: whether the healer was on screen, whether a pillar broke line of sight,
   whether a cast bar was visible. Every coaching read the site makes today silently assumes the
   player had perfect information. The picture is the only source that can say otherwise.

The second is the one worth pursuing. It serves the current focus (understanding your own games)
instead of replacing it, and it needs no new abstraction layer.

## What this is not for

Do not justify this work as "opening up games without combat logs". It does not survive checking:

- **League of Legends** exposes Riot's `match-v5` API with a full timeline endpoint.
- **StarCraft II** — this repo *already* uses structured data. `data/sc2/techtree.json` is read
  from the game through Blizzard's API at patch 5.0.14.

Both of the games on the roadmap already explain themselves through structured data. A general
"shared timeline of observations" layer spanning several games is premature until a second game
actually needs one. See CLAUDE.md's first principle: fewest moving parts that do the job.

## The protocol

A model that knows WoW can produce a confident, plausible description of a frame without reading
the frame. That failure is invisible unless the method forces the distinction, so **every claim
carries its source**:

| Tag | Meaning |
|---|---|
| `[READ]` | Read off specific pixels. Name where — which bar, which frame, which icon. |
| `[INFER]` | Not visible. Deduced from what is visible plus how the game works. |
| `[PRIOR]` | Not visible and not deducible. Pattern-matched from knowing WoW. **A `[PRIOR]` that turns out correct is still a failure** — the system guessed. |
| `[UNKNOWN]` | A competent observer would want this and it cannot be got from the frame. |

This mirrors `arena-structure.md`'s `[OBS]`/`[DER]`/`[HYP]`, and inherits its correction: these are
**not a confidence ladder**. They say what kind of thing would falsify the claim. A `[READ]` is
re-checkable by looking at the frame again, which is what makes it the checkable one.

**Two scores matter**, and the second is the real one:

1. What fraction of `[READ]` claims survive checking.
2. **How many `[PRIOR]`s are emitted at all.**

### Rules that keep the result meaningful

- Observations are committed **before** the log is consulted. A post-hoc read is worthless.
- The frame is handed over with **no context** — no comp, no map, no outcome.
- Prefer a frame from a **video**, not a native screenshot. Video is the realistic case and a
  harder read (compression, letterboxing).

## Frame 1 — 2026-10-20

A video still, letterboxed and compressed. Black Rook Hold Arena, 3v3, six players alive.

### What was read correctly off the frame

Scoreboard (`Gold Team: 3 Players Remaining`, `Time Remaining: 18:08`), zone name, level 90
nameplates, all six player names split into the right teams, three enemy health values
(916 K / 1042 K / 1146 K), two nameplate health readings, a `Cyclone` cast with its target named,
a floating `Absorb` indicator, two cooldown timers on enemy frames, and a damage meter.

**A cross-check that worked, and is worth reusing:** Soulrulerxc's nameplate read `1.1M` and his
arena frame read `1146 K`. Two independent regions of the UI agreeing on one unit is a cheap,
automatable confidence signal — and the basis of a self-check a real detector could run.

### The trap in this frame

**The damage meter is not this match.** Its six names include Addarray, Wutainx and Longtimenose,
none of whom is on either visible team — almost certainly an Overall segment spanning earlier
fights. A naive reader attributes 27.8M of damage to this round and is wrong by an unknown factor.

Generalise it: **the UI shows state from outside the current frame's scope.** Any detector that
reads a number off the screen and treats it as a fact about the current round will inherit this.
It is the single most valuable thing frame 1 produced.

### What could not be read, and should not be faked

**Class and spec were not identifiable.** The icons are roughly 20px and compressed past reliable
identification. A plausible comp could have been produced from the spell names and bar colours —
that is precisely the `[PRIOR]` failure, and it was declined. If a future run reports a comp from a
frame like this, check it hard.

Also unavailable: which player is the user, cooldowns remaining, positioning and line of sight (the
camera shows one corner; most players are off-screen), and anything about the preceding seconds.

### Not yet done

**None of this has been diffed against the combat log.** Until it is, frame 1 is an unscored
sample. The diff needs frame-time ↔ log-time alignment to about ±100ms; for one frame, align by
hand.

## What the structured data added, tested not assumed

The second half of the question: does the repo give a reader capability its training does not?
Two lookups against the live database, both from text read off the frame.

**1. The red error string `…that while horrified`.**

```
php artisan wow:abilities --while-cc=horror
  Pain Suppression    instant  180s  2 charges   Discipline Priest
  Roar of Sacrifice   instant  120s              Survival Hunter
  Sigil of Chains     instant   60s              Havoc Demon Hunter
```

Three abilities in the entire game are castable while horrified. That is a short, exact,
patch-current list attributed to the specs that can press it — and it turns a red error message
into a specific question about what was still available.

**2. The readable `Cyclone` cast.**

```
php artisan wow:abilities --class=druid --category="Crowd Control"
  Cyclone    Disorient  mech:Banish  cast
```

Two facts a single frame cannot give: Cyclone is on the **Disorient** DR category, so whether it
lands full duration depends on prior Disorients on that target; and it is a **cast**, not instant,
so the bar in the frame is interruptible. Both are actionable, and neither is visible.

> `mech:Banish` is a known data artifact, not a claim about the spell. Nothing models who a spell
> may be cast on, which is why Banish and Shackle Horror shipped as player CC (CLAUDE.md, machine
> guides). Do not read the mechanic field as gospel here.

**The finding:** the picture supplies *what and when*; the database supplies *what that means and
what else was possible*. Neither is much use alone. This is the strongest result so far and it
points at the overlay being a join between two sources rather than a video feature.

## Risks to carry forward

- **UI dependence is the big one.** Reading health bars, nameplates and cooldown icons means
  reading *one player's* interface — addons, Plater/ElvUI profile, nameplate scale, bar layout. The
  combat log is UI-independent; the picture is not. A detector tuned on one player's recordings may
  fail completely for the next. **The informative next frame is from a different player's UI, not
  another frame from the same one.**
- **A general multimodal read is an upper bound on quality and a poor guide to cost.** A real
  pipeline would use cheap specialised detectors for frequent things (health bars, nameplates) and
  reserve model calls for interesting moments.
- **n=1.** Nothing here is a rate.

## Next steps, in order

1. **Diff frame 1 against its combat log.** Score the `[READ]` claims. Needs the round id.
2. **Build the diff harness** — takes a round id and a list of claimed observations, scores
   precision, recall and timing error against the stored timeline. This is what makes every later
   run measurable instead of impressionistic. Not built yet, deliberately: not worth writing until
   there are enough samples to score.
3. **A frame from a different player's UI**, to size the dependence risk.
4. **Only then** consider recording integration — as an input mechanism, not the feature.

## Related

- `arena-structure.md` — the `[OBS]`/`[DER]`/`[HYP]` convention this borrows from, and why it is
  not a ladder.
- `match-review.md` — the combat-log pipeline that provides the ground truth to score against.
- `RoundAnalysisService::timeline()` — the 0.1s-resolution series (casts, health readings, deaths)
  a vision timeline would be diffed against. `CombatLogIngestService::timestampMs()` gives
  millisecond absolute time per log line, which is what makes alignment possible at all.

## Icon matching: the first spike, and why it failed (2026-10-20)

The idea: a screen icon is not something a vision model should *recognise*, it is something to
**look up** against a corpus this repo already owns. Deterministic, auditable, calibrated, and it
returns a `spell_id` that joins into everything else.

```
storage/app/public/spell-icons/   3,969 files, 56x56 native, 17 MB
data/spelldata/icon-manifest.json 9,414 spell_id -> filename mappings
config/wow_classes.php            Blizzard's exact RAID_CLASS_COLORS, 13 classes
```

**First corpus finding: 3,969 files are only 2,544 unique pictures.** 1,425 are content
duplicates — Blizzard ships icons under both a numeric FileDataID and a human name, and both were
fetched. Dedupe by pixel hash before building any index, or an icon competes with itself and every
confidence margin reads 0.000. That is exactly what v1 of the spike reported.

**The spike:** 12x12 RGB descriptor, mean-removed, L2-normalised, cosine similarity, two variants
per icon (full, and the central 80% for UIs that zoom the border away), searched over +/-5px of
offset and five sizes per cell. Ten action-bar cells from frame 1.

**Result: scores 0.608-0.829, margins 0.024-0.119, and the matches are mostly WRONG.** Judged by
rendering each screen crop above its matched template and looking. A grey icon reading "28" matched
dark red spikes; a purple icon reading "30" matched an orange starburst.

> **The finding that matters: the similarity score did not predict correctness.** An
> implementation that thresholded at 0.8 cosine would have emitted confident, wrong `spell_id`s
> with no signal that anything was wrong — the silent-failure mode this whole protocol exists to
> catch, reproduced in the first hour of trying.

Why it failed, worst first:

1. **Cooldown overlays destroy the descriptor.** The timer text ("2m", "28", "30") is burned over
   the art and the swipe darkens it. The match is being driven by text and dimming, not the spell.
2. **Guessed grid geometry.** Cell coordinates were estimated by eye; several crops straddle two
   icons or include borders. Detect the grid from edges; never hand-place cells.
3. **The descriptor is too weak.** 12x12 RGB cosine is close to average-colour layout, so unrelated
   icons that share a palette score 0.8.
4. **Stream compression** at roughly 38px per icon.

**What to try next, in order.** Detect the icon grid rather than guessing it; detect and skip cells
on cooldown, or mask the centre where the timer sits; move to a gradient/edge descriptor that is
insensitive to brightness; and run on a clean PNG before concluding anything about the method. This
spike used the worst available frame deliberately — a compressed Twitch still. The honest read is
**not** "icon matching does not work", it is "this implementation does not, and the score lied
about it".

Harness: `iconmatch2.py` in the session scratchpad (not committed; a throwaway).

## Correction to frame 1's provenance

Frame 1 is a still from a **Twitch stream**, not the user's own gameplay. Two consequences:

- **There is no combat log for it.** It cannot be scored against ground truth. "Diff frame 1
  against its log" is struck from the next steps; it needs a frame from a game in the archive.
- **It was already a different player's UI**, so the UI-dependence test has partly happened by
  accident. The reads that worked did so on a stranger's addon layout.

## Frame 2 (2026-10-20): a prediction that held, and a class-colour result

A cleaner still, same six players, different round and map (The Tiger's Peak).

**The frame-1 inference about the damage meter was confirmed.** Frame 1's meter listed Addarray,
Wutainx and Longtimenose — on neither team — and was called as an out-of-scope segment. Frame 2's
meter lists Soulrulerxc, Dzdv, Daveportnoy, Jocheolbong, Bluegator and Umoongzin-Tichon: exactly
the six players present. The trap is real and now demonstrated in both directions.

**Health bars are class-coloured, not health-coloured.** Umoongzin sits at 100% health with an
*orange* bar; a health gradient would be green. So bar colour is a 13-way lookup against
`config/wow_classes.php`, not a 2,544-way one against the icon corpus — far cheaper and far more
robust than icon matching, and it survives compression. Orange is `#FF7D0A`, Druid, which is
consistent with the Cyclone cast read in frame 1.

**This reorders the work.** Colour-to-class and OCR of names and numbers are cheap, robust and
nearly free. Icon matching is the expensive, fragile part and should not be the first thing built.

## Who else is doing this (2026-10-21)

### arenacoach.gg — the same product idea, built on the log

A shipped WoW PvP desktop app doing roughly what a MindCollector overlay would do. Everything
below is from their own marketing copy via search: the site sits behind Cloudflare and refuses
automated fetches, and nobody here has used the product. Treat the numbers as self-reported.

| Piece | What they do |
|---|---|
| Recording | Desktop app, **OBS-powered**, NVENC/AMD hardware encoding. Detects WoW, runs in background, records from queue to match end. |
| Analysis source | **The combat log, not vision.** "Rebuilds the full game state from your combat log." Logs uploaded as `.txt`, stored per account. |
| The overlay | Mistakes, cooldowns and kills **timestamped onto the recorded replay**, plus an events timeline — click a mistake, jump to it. Not a live in-game overlay. |
| Detection | A "scenario engine" over DR levels, CC usage, cooldown availability, health thresholds, kill conditions. Categories: interrupts, defensives, CC/DR, kill windows. Labelled BETA. |
| Headline mistake | "Deaths With Defensives Up" — claimed the most common mistake at every bracket. They say they check the player was not in CC. |
| Pricing | Free: 5 analyses/week, 2v2 and skirmishes always free. Full mistake analysis is Premium. |

Their own materials disagree on scale — "40+ mistake patterns" in one article whose own table
lists 17 types.

**What it means for this project.**

1. **It confirms the recording architecture.** OBS with hardware encoding, app-driven start/stop on
   queue — the approach recommended here before this product was known about. The app owning the
   recorder is also what makes sync free: it holds the wall-clock anchor. Do not build a recorder.
2. **Nobody is doing the vision half.** Their video is a playback surface; all analysis comes from
   the log. The frame-reading question is unexplored rather than table stakes, which argues for
   keeping it research and shipping the overlay on log data.
3. **The differentiator is goes, and it is sharper than it first sounds.** "Died with a defensive
   up" is a per-event check: one death, one availability lookup. A go is a structured *window* of
   coordinated cooldowns across players — a higher-order object their per-event engine does not
   appear to model. `wow:go-cooldowns` and `ArenaMomentService` already build these from the
   cooldowns themselves rather than a fixed slice of clock.
4. **Their headline feature is the thing CLAUDE.md warns against.** "Never call a count a fault,"
   and defensives are read through the three questions. Availability is not obligation: the player
   may be right to hold it, the damage may be unavoidable, or the real error may be thirty seconds
   earlier. They mitigate the CC case and apparently nothing else.
5. **Population norms are the moat.** A read of "behind other players of your spec" needs a corpus
   (1,297 games / 7,071 player-rounds / 1,864 players / 34 specs at last run). Corpora take time to
   accumulate and cannot be copied from a landing page.

Unverified and worth an evening on the free tier: whether their defensive check has a real "should
have pressed" model or is pure availability, and what Premium costs.

### Prior art worth stealing from

Nobody found doing *domain-model-over-screenshot* for WoW. But two mature fields solve the same
shape of problem, and between them they name every failure in the spike above.

**1. Detect, then reason — never let the model identify (OmniParser, Set-of-Mark).**
Microsoft's OmniParser splits screen understanding into a perception stage (a detector fine-tuned
on 67,000 screenshots finds interactable regions, plus OCR and a caption model) and a reasoning
stage, where the VLM is shown numbered boxes and asked to name an **ID**, never raw coordinates or
an identity. This is the tiered architecture proposed here, independently arrived at and
benchmarked: screenshot-only OmniParser beat GPT-4V baselines that were given extra non-screenshot
information.

**2. Synthetic training data from the game's own assets (LeagueAI, arXiv 1905.13546).**
The most directly applicable paper found. It builds training images by compositing the game's own
assets over captured screenshots, explicitly including *masked UI elements and cursors*, so the
detector learns how overlap affects objects and stops firing on UI. With domain randomization.

> This converts the spike's failure into training signal. Cooldown swipe wedges, burned-in timer
> text, desaturation, borders, scaling and stream compression are not obstacles to engineer around
> — they are augmentations to synthesise. The corpus is already owned: **2,544 unique icons** can
> generate effectively unlimited labelled examples with exactly the corruptions that broke the
> hand-designed descriptor. Train a small classifier instead of hand-tuning a distance metric.

**3. The generalisation warning (PandaScore, on DeepLeague).**
DeepLeague trained an SSD detector on 100,000 labelled League minimap images. PandaScore found it
worked on the held-out test set but **did not generalise to champions absent from the training
set**. The WoW analogue is exact and worse: a detector trained only on icons observed in played
games will fail on everything else, and every patch adds spells. The mitigation is the same as
above — synthesise from the *whole* corpus, not from what has been observed. Recent work
(GIST / AAAI 2026) does precisely this, combining synthetic data with a subset of real replay data
via transfer learning, aimed at small objects, fast movement and occlusion.

**4. Calibrate the geometry before recognising anything (sports broadcast tracking).**
SoccerNet game-state reconstruction and the commercial trackers do not guess where things are.
They fit a camera model to the *known pitch geometry* — projecting candidate line sets and
choosing the one with least L2 distance to the ideal model — then project detections into pitch
coordinates. Per-frame homography, with optical flow for camera motion between frames.

> The spike's second-worst failure was hand-placing cell coordinates by eye. The UI analogue of a
> homography is: detect a few anchor elements, solve the layout transform once, then read every
> other element at a known offset. Never slide a window and hope.

**5. Temporal consistency (all of the above).**
Sports pipelines use per-frame calibration plus re-identification across frames; the esports work
targets occlusion and fast movement the same way. Single-frame reads are noisy in a way sequences
are not: a health bar cannot jump, a spell cannot be cast twice inside a GCD, a player cannot
teleport. **The entire spike above was single-frame**, which is the weakest possible setting.

**Where the analogy breaks, and it matters.** A football pitch is always the same size; the League
minimap is always the same layout. **A WoW UI is user-configurable** — addons, profiles, scale,
bar arrangement. So calibration cannot be solved once globally the way homography is. The useful
decomposition: **icon identity is universal** (the assets are fixed and already owned), **icon
location is per-player** (calibrate per UI, or per stream). That split is what makes the problem
tractable at all, and it is the reason the colour-to-class route is worth building first — it
needs no layout knowledge whatsoever.

### Sources

- arenacoach.gg: site, `/coaching`, `/faq`, `/privacy`, `/guides/top-10-arena-mistakes-holding-you-back`
- OmniParser: arXiv 2408.00203
- LeagueAI: arXiv 1905.13546
- DeepLeague: Farza Majeed, and PandaScore's write-up on its generalisation limits
- SoccerNet game state reconstruction: arXiv 2504.06357
