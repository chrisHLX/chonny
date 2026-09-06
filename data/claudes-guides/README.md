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

## Burst Guides (added 2026-09-04)

A second, separate page/subfolder, sharing this parent folder but otherwise independent of the
eleven hand-written guides above — `/burst-guides`, `App\Livewire\BurstGuides`, reading
`data/claudes-guides/burst-guides/{class}/{spec}.json`. Direct design brief: "a Definite series
or combinations of keys (GCDs) to press," one compact block per spec (all 38 with real rotation
data, not just the 11 with a hand-written guide), computed rather than authored.

**How it's built:** `php artisan wow:build-burst-guides` → `ArenaLogService::
buildBurstGuideSequence()` — reads each spec's longest available real archived burst window
(30s, preferred specifically because it gives the truncation step below enough data to actually
find a repeat), filters out any step classified purely-defensive (via the same Offensive/
Defensive classification WoW Comps' Cooldowns tabs use) unless it carries a `dr_category`
(Crowd Control always survives the filter — "if it's part of the burst on the kill target, keep
it"), then truncates the sequence the moment a 2+-step block repeats back-to-back (matching the
design brief's own worked example: "mutilate mutilate envenom, mutilate mutilate envenom" —
confirmed to occur verbatim in Assassination Rogue's own real 30s window once this was built).
See that method's own docblock for the full design, including one flagged, unsolved limitation:
"CC only if it's on the kill target" can't currently be verified per step, since the raw combat-
log extraction upstream (wow-arena-archive's `offensive-rotations.php`) doesn't capture each
individual cast's own destination — a CC step is presumed, not proven, relevant to the tracked
target.

**What's stored vs. resolved live:** each JSON file holds ONLY an ordered `spellIds` list (plus
computation metadata — source window length, raw/filtered counts, whether it truncated) — never
a frozen name/cooldown/duration, same discipline as every other file in this folder.

**Page shape — a thin parent + 13 lazy-loaded per-class children, not one all-at-once render**
(redesigned 2026-09-04, same day as the initial build, after direct user pushback on an
inflated/misleading memory-cost report — see CLAUDE.md's own dated follow-up for the full trace).
`App\Livewire\BurstGuides` (the route's component) only lists which class slugs have data on
disk — no spell resolution at all — and mounts one `<livewire:burst-guide-class-block lazy/>`
per class (Livewire 3's `#[Lazy]` component loading). `App\Livewire\BurstGuideClassBlock` carries
the real per-class resolution logic, scoped to just that one class's 2-4 specs, with its own
Redis cache key (`spellCacheVersion()`/`deployedCodeFingerprint()`-keyed, same invalidation as
WoW Comps' `wow_spell_references:*`) so viewing one class never invalidates/recomputes another.
It deliberately does NOT reuse `SpecKitComputer::resolveEntriesForSpellIds()` (the same
live-resolution path `ClaudesGuides` uses for one spec at a time) — that method's relation-
hydrating rehydration was measured at ~430MB peak memory when called once per spec across all 38
specs synchronously, a real production-500 risk. Instead `BurstGuideClassBlock::
resolveBurstGuideSteps()` reads each spec's precomputed kit file directly as raw JSON (no
Eloquent rehydration) for the same already-talent-computed cooldown/charges/category numbers.
End-to-end verified result: an initial page paint of ~2MB/18KB (placeholders only, no real spec
content yet), followed by 13 small independent background requests (~4MB/~100KB each, varies by
class) instead of one blocking ~14MB/1.4MB render of all 508 step-cards at once.

**Kept fresh automatically:** `php artisan wow:refresh-match-derived` (see that command's own
docblock) runs `wow:build-burst-guides` as its final step, right after promoting fresh rotation
data — a new match pull can never leave this page's data silently stale the way earlier gaps in
that same orchestrator's history did for CC chains/rotations before it existed.
