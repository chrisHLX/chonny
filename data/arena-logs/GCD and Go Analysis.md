# GCD and Go Analysis — methodology, reference implementation, findings

Catalogues an analysis technique built during a 2026-08-17 session: reconstructing what a
player actually presses during a "go" (a coordinated burst window) from raw arena combat
logs, correcting for the fact that raw `SPELL_CAST_SUCCESS` counts are inflated by off-GCD
abilities stacking on top of real presses. This is exploratory analysis tooling, not
application code — nothing here is wired into the live site. Lives alongside
`CC Durations Off Arena logs.txt` as a second standing reference doc for this data pipeline;
see `spell-acquisition-model.md` (repo root) for where arena-log analysis sits in the wider
spell-data-acquisition picture.

## Why raw cast counts are misleading

A burst window looked like "12 GCDs in 8 seconds" for a Subtlety Rogue purely from eyeballing
timestamps rounded to 2 decimal places. Two things were wrong with that read:
1. **Display rounding hid real spacing.** Printing timestamps at 2-decimal precision made two
   casts 0.4–0.6s apart look identical to two casts that were genuinely simultaneous.
2. **Off-GCD abilities were never separated from GCD-consuming ones.** Tricks of the Trade,
   Kidney Shot's activation instant, a cooldown's own activation cast (Shadow Dance, Shadow
   Blades), Secret Technique, Feint, Kick — several of these can land on the exact same GCD
   as another "real" press, inflating the raw count without representing extra tempo.

The fix is to derive, empirically, which casts consumed a real global cooldown and which
didn't — without hardcoding which specific spells are off-GCD (that's exactly the kind of
game-knowledge assumption this codebase has been burned by before, see CLAUDE.md's "Baseline
ability display" saga). The method reads it back out of the actual server-timestamped data.

## Method 1 — empirical GCD floor

For a given player, gather every `SPELL_CAST_SUCCESS` timestamp across the **whole match**
(not just the window under study — more samples, cleaner signal), sort them, and compute the
gaps between consecutive casts. Exclude near-zero gaps (`<= 0.05s`, almost certainly
simultaneous log entries, not two sequential presses) and anything `>= 2.0s` (decision/latency
time, not GCD-bound). Bucket the remaining gaps into 0.05s-wide buckets and take the smallest
bucket among the most-common (modal) bucket(s) — the floor is a lower bound, so among ties the
tightest recurring cadence wins.

**Real bug hit while building this, worth remembering for any future PHP histogram code:**
PHP silently truncates **float array keys to integers**. Using a raw float gap value (e.g.
`round($g/0.05)*0.05`) as an array key collided `0.05` and `1.05` into the same key (`0`/`1`),
corrupting the histogram — this produced a nonsensical "0.00s GCD floor" for one player before
being caught. Fix: bucket by an **integer index** (`(int) round($g / 0.05)`), and only convert
back to a real seconds value at the very end.

```php
function empiricalGcdFloor(array $timestamps): ?float {
    sort($timestamps);
    $gaps = [];
    for ($i = 1; $i < count($timestamps); $i++) {
        $gap = $timestamps[$i] - $timestamps[$i - 1];
        if ($gap > 0.05 && $gap < 2.0) $gaps[] = $gap;
    }
    if (count($gaps) < 5) return null; // not enough samples to trust
    $buckets = [];
    foreach ($gaps as $g) {
        $idx = (int) round($g / 0.05); // integer bucket index — NEVER a raw float key
        $buckets[$idx] = ($buckets[$idx] ?? 0) + 1;
    }
    $maxCount = max($buckets);
    $topIndices = array_keys($buckets, $maxCount);
    sort($topIndices);
    return $topIndices[0] * 0.05; // tightest bucket among ties
}
```

Sanity-checked against real data: Subtlety Rogue floor came out as a clean 1.00s (29 recurring
samples in one match), Frost Mage ~1.15s, Preservation Evoker ~1.05s, Discipline Priest
~1.25s — all physically plausible hasted-GCD values, no artificial 0.5s-or-lower floor found
anywhere despite the original visual impression.

## Method 2 — greedy GCD gating

Once a floor is known, walk a player's casts within a window in chronological order. A cast
only "consumes" a fresh global if it lands at or after `(last real press + floor - tolerance)`
— `tolerance` (0.15s) absorbs latency/log jitter. Anything earlier physically could not have
used a separate global; it's reported as off-GCD, never silently dropped.

```php
function gateByGcd(array $casts, float $gcdFloor, float $tolerance = 0.15): array {
    $onGcd = []; $offGcd = []; $nextAvailable = -INF;
    foreach ($casts as $c) {
        if ($c['t'] >= $nextAvailable - $tolerance) { $onGcd[] = $c; $nextAvailable = $c['t'] + $gcdFloor; }
        else { $offGcd[] = $c; }
    }
    return [$onGcd, $offGcd];
}
```

**GCD utilization** for a window = `count($onGcd) / (span / floor + 1)` — real presses divided
by the theoretical maximum possible in that span. This isolates pacing/tempo from raw activity
count, and is comparable across players with different GCD floors (different haste/gear).

## Method 3 — locale-independent name resolution

One comparison match turned out to be on a **French client** — raw log ability names were in
French (`Éclat lunaire`, `Griffure`, etc.), not directly matchable by string. **Always resolve
by `spell_id` against our own `spells` table (patch-scoped), never trust the raw log's name
field for anything beyond a fallback.** This is the same discipline `ModuleSpellReferenceService`
already applies everywhere else in this codebase — worth remembering as standard practice for
any future arena-log tooling, not just this one match.

```php
function resolveSpellName(int $spellId, int $patchId, array &$cache): string {
    if (isset($cache[$spellId])) return $cache[$spellId];
    $spell = Spell::where('patch_id', $patchId)->where('spell_id', $spellId)->first();
    return $cache[$spellId] = $spell ? $spell->display_name : "unknown({$spellId})";
}
```

## Method 4 — bounding a "go" window without eyeballing it

Don't pick a window's end time by eye (e.g. "looks like it winds down around here") — that
introduces exactly the kind of inconsistent, comparison-biasing judgment call this whole
technique exists to avoid. Instead, bound the window using the **real duration of the
cooldown/buff that opened it**, read from our own `spells.duration_seconds` (checked directly,
not assumed): e.g. Incarnation: Avatar of Ashamane's own DB record was checked before choosing
a 25s post-activation window for both Feral comparisons, applied identically to both players
rather than tuned per-player.

## Method 5 — causal context check: was the player actually free to act?

**GCD utilization on its own cannot distinguish "played worse" from "got shut down."** Before
treating a low-utilization window as a rotation/skill finding, cross-check what the *enemy*
was doing to the player during that window — specifically, `SPELL_AURA_APPLIED ... DEBUFF`
events targeting them (harmful auras only; buffs are the player's own stuff and just noise
here). A real forced-interruption (PvP trinket use mid-gap, a wall of enemy CC debuffs landing
right after the player's cooldown activation) explains a utilization drop that has nothing to
do with the player's own pacing.

```php
// DEBUFFs only (harmful auras applied BY an enemy), not the player's own buffs
preg_match_all('/^([\d\/: .-]+)\s+SPELL_AURA_APPLIED,[^,]*,"[^"]*",[^,]*,[^,]*,'
    .$playerGuidPattern.',"[^"]*",[^,]*,[^,]*,\d+,"([^"]*)",[^,]*,DEBUFF/m', $raw, $m, PREG_SET_ORDER);
```

## Findings so far

### Subtlety Rogue, RMP-shaped comp, high-rated (3096) vs low-rated (1946)
- Both share the **same empirical GCD floor** (~1.00s) — gear/haste wasn't the differentiator.
- High-rated Rogue: 92–93% utilization in both of two go windows — essentially GCD-capped,
  every global used.
- Low-rated Rogue: 60% (Go #1) and 81% (Go #2) — inconsistent, not uniformly worse.
- **Concrete, checkable rotation differences** (not just pacing):
  - High-rated stacks Tricks of the Trade + Kidney Shot + Shadow Dance + Blood Fury into a
    0.53s window every time. Low-rated's Shadow Dance and Blood Fury land ~3s apart.
  - Low-rated **never casts Tricks of the Trade at all**, anywhere in the match — missing
    step, not a timing slip.
  - Low-rated interleaves defensive/reactive presses (Feint, Kick) **during** their own
    offensive window; high-rated only uses Feint **after** the burst concludes, as a clean
    transition out.
  - A real 3.39s dead gap in low-rated's Go #1 is at least partly explained by a Gladiator's
    Medallion (CC-break trinket) use right in the middle of it — the Rogue got crowd
    controlled mid-burst and had to trinket out, not pure wasted tempo.
  - Finisher timing looks rushed in low-rated's Go #2 (Eviscerate within ~1s of Shadow Blades,
    before a single Shadowstrike lands) vs. high-rated building up properly first — flagged as
    an inference from timing pattern, not confirmed via combo-point telemetry (not captured
    in these logs).

### Feral Druid, high-rated (3020) vs low-rated (1954), go = Incarnation: Avatar of Ashamane
- **The pattern from the Rogues did not replicate — and the reason is causal, not statistical
  noise.** High-rated Feral: 49% utilization. Low-rated Feral: 82%.
- Checked debuffs landing on each player during their window: high-rated Feral took **36
  debuff instances** (Blinding Sleet ×2, Absolute Zero, Binding Shot ×2, Remorseless Winter
  ×4, Freezing Trap, Chains of Ice — a real multi-enemy CC chain landing within ~0.3s of their
  cooldown activation) vs. low-rated Feral's **8**, clustered in one opener burst with nothing
  resembling sustained follow-up CC.
- **Conclusion: the high-rated Feral's low utilization is explained by getting successfully
  countered/peeled by the enemy team, not by worse personal execution.** This is exactly the
  kind of "successful counter-go" the state-identifier idea below is meant to distinguish
  automatically, rather than requiring a manual debuff-timeline check every time.

## Reproducing this (current state: ad hoc scratch scripts, not a permanent command)

Every run so far has been a one-off PHP script in the session scratchpad directory, bootstrapped
the same way as any other one-off Laravel script in this codebase:
```php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
```
then loading `data/arena-logs/metadata/{matchId}.json` (roster — **group teams by `reaction`**,
`2` = friendly-to-logger / `1` = hostile-to-logger; `affiliation` is a Blizzard
MINE/PARTY/RAID/OUTSIDER bitflag relative to the logging player, not a reliable team-A/team-B
split — a real bug hit while building the RMP-comp finder) and
`data/arena-logs/raw/{matchId}.log.gz` (gunzip to get the raw combat log text), then applying
Methods 1–5 above. None of this is committed as reusable code yet — see the open design
question below before that happens.

## Open design question: a per-window "player state" classifier

Raised directly by the Feral finding above: **GCD utilization needs a state label attached to
be interpretable at all.** "49% utilization" reads as bad play in isolation; "49% utilization
because the player was locked down by 4 stacked CC effects" is a completely different, more
useful fact — the pattern in this codebase's own words is "a Rogue in an RMP go is clearly in
an offensive go — ToT, stun, then a rotation. The Ferals were both Go's (indicated by
Incarnation) but one was successfully countered — that distinction needs to be visible without
a manual debuff-timeline check every time."

**Proposed states**, not yet confirmed:
- **Offensive Go** — player is casting into the enemy team (offensive/CC-category abilities,
  by target), landing real damage/CC on an enemy, not receiving significant enemy CC.
- **Defensive / Peeled** — player's own casts shift to defensive-category self-casts
  (Barkskin, Incapacitating Roar, Ice Block, etc.) and/or they're the target of a burst of
  enemy CC-category debuffs.
- **Neutral / Setup** — normal-cadence rotation, no clear offensive commitment or defensive
  crisis (the "in between" state most of a match is actually in).
- Possibly a fourth, **Countered Go** — specifically an Offensive Go that gets interrupted by a
  Defensive/Peeled transition within N seconds of starting (this is exactly what happened to
  the high-rated Feral, and is probably the single most interesting state transition to be
  able to detect and query for across the whole match dataset).

**The key design decision: reuse the categorization data this codebase already curated,
rather than inventing a new taxonomy.** `ModuleSpellReferenceService::categorize()` already
buckets every spell into Offensive / Defensive / Crowd Control / Utility / Other, and
`spells.dr_category` / `is_peel` / `is_interrupt` are already hand-verified for a large,
growing set of PvP-relevant abilities (see CLAUDE.md's "Synergies tab" and "categorize()
accuracy pass" sections). A state classifier can be built almost entirely as a rolling-window
aggregation over data that already exists and is already trusted — not a new AI-guessed
classification layer.

## Prototype v1 — built and validated against three known examples

Built as a scratch prototype (not yet a permanent command) and tested against three windows
whose real outcome was already independently established by manual inspection above — a
correctness check before generalizing to the full 318-match dataset.

**Signal stream per player, per window:**
- `OWN_CAST` — the player's own `SPELL_CAST_SUCCESS`, classified via
  `ModuleSpellReferenceService::categorize()` (Offensive / Defensive / Crowd Control / Utility
  / Other).
- `INCOMING` — enemy-applied `SPELL_AURA_APPLIED ... DEBUFF` events landing on the player.

**Two real bugs found and fixed during the first test run, both worth remembering for any
future signal built the same way:**

1. **`categorize()` is not a reliable signal for `INCOMING` events.** It answers "what does
   this ability do in its owner's kit," not "is this landing on me a threat." Confirmed wrong
   twice in testing: Fire Breath (an offensive AoE) and Chaos Brand (a damage-taken debuff)
   both bucketed as `Defensive` via `categorize()`. **Fix: use `spells.dr_category` for
   `INCOMING` events instead** — that field is already hand-verified specifically to mean "is
   this genuine CC," which is exactly the question being asked here. `categorize()` stays
   correct and useful for `OWN_CAST` (that's the context it was actually built for).
2. **A defensive self-cast only signals "being peeled" if it's reactive.** The low-rated Feral
   opens with Barkskin *before* Incarnation, with zero incoming CC preceding it — that's
   proactive setup ("armor up before committing"), not evidence of being countered. The
   high-rated Feral's Barkskin, by contrast, came *after* a real CC chain landed — genuinely
   reactive. **Fix: a defensive `OWN_CAST` only counts toward `DEFENSIVE/PEELED` if a
   `dr_category`-tagged `INCOMING` event preceded it within the lookback window.**

```php
function classifyStates(array $events, float $lookback = 6.0, int $debuffThreshold = 2): array {
    $labeled = [];
    foreach ($events as $e) {
        $windowStart = $e['t'] - $lookback;
        $recent = array_filter($events, fn($x) => $x['t'] >= $windowStart && $x['t'] <= $e['t']);

        $incomingCc = array_filter($recent, fn($x) => $x['kind'] === 'INCOMING' && $x['drCategory'] !== null);

        $reactiveDefensive = array_filter($recent, function ($x) use ($recent) {
            if ($x['kind'] !== 'OWN_CAST' || $x['bucket'] !== 'Defensive' || $x['isPassive']) return false;
            foreach ($recent as $prior) {
                if ($prior['kind'] === 'INCOMING' && $prior['drCategory'] !== null && $prior['t'] <= $x['t']) return true;
            }
            return false;
        });

        $ownOffensiveOrCc = array_filter($recent, fn($x) => $x['kind'] === 'OWN_CAST' && in_array($x['bucket'], ['Offensive', 'Crowd Control']) && !$x['isPassive']);

        if (count($reactiveDefensive) > 0 || count($incomingCc) >= $debuffThreshold) {
            $state = 'DEFENSIVE/PEELED';
        } elseif (count($ownOffensiveOrCc) >= 1) {
            $state = 'OFFENSIVE GO';
        } else {
            $state = 'NEUTRAL';
        }
        $e['state'] = $state;
        $labeled[] = $e;
    }
    return $labeled;
}
```

**Results after both fixes — 3 for 3 against known outcomes:**
| Window | Expected | Result |
|---|---|---|
| High-rated Rogue, clean go | sustained Offensive | ✅ sustained `OFFENSIVE GO`, 12.98s→20.61s, no false Defensive trigger |
| High-rated Feral, countered go | Offensive → Defensive → recovers | ✅ `OFFENSIVE GO` 15.85s→20.01s → `DEFENSIVE/PEELED` 21.63s→26.84s (Absolute Zero crosses the CC threshold) → recovers to `OFFENSIVE GO` at 28.51s once the CC chain ages out of the lookback |
| Low-rated Feral, uncontested go | sustained Offensive | ✅ sustained `OFFENSIVE GO`, 94.80s→113.85s (the incidental single Chaos Nova hit at 104.00s correctly does NOT flip state — one CC hit alone is below the threshold, matching that it didn't visibly interrupt the rotation) |

**Known limitations, not yet fixed:**
- Several incoming/outgoing spell_ids resolve to `unknown(...)` — not present in the `spells`
  table for the current patch (pet abilities, older-content leftovers, or genuinely missing
  data — not investigated further here).
- The state-transition trigger for the Feral countered-go landed on Absolute Zero (21.63s)
  rather than the earlier Blinding Sleet/Binding Shot pair (17.74s/20.01s) — suggesting one of
  those two doesn't have `dr_category` set on the exact spell_id copy that actually landed
  (the well-established "same ability, multiple spell_id copies, only one hand-curated"
  pattern documented throughout CLAUDE.md). The overall transition is still correctly detected
  within the right general window — this is a data-completeness gap, not a logic bug.
  `dr_category` coverage is still growing and incomplete for many non-PvP-relevant classes.
- `debuffThreshold = 2` and `lookback = 6.0s` are untuned constants that happened to work for
  these three examples — not validated against a larger sample yet.

## Stress test — 8 more windows across 4 classes, 2 rating tiers each (2026-08-17)

Go triggers identified the same way as the Rogue/Feral examples (scan a player's full-match
cast list for their signature burst cooldown), windows bounded by that cooldown's own real
`duration_seconds` + a fixed buffer, applied identically across the low/high pair of each
class: Havoc DH (Metamorphosis), Shadow Priest (Voidform), Frost DK (Pillar of Frost), and
Enhancement Shaman (Ascendance).

| Window | OFF | DEF | NEU | Read |
|---|---|---|---|---|
| Havoc DH LOW | 2 | 57 | 0 | ❌ over-extended, see below |
| Havoc DH HIGH | 16 | 47 | 2 | plausible, not deep-checked |
| Shadow Priest LOW | 39 | 0 | 3 | clean, uncontested |
| Shadow Priest HIGH | 30 | 17 | 1 | plausible, not deep-checked |
| Frost DK LOW | 26 | 22 | 3 | plausible, not deep-checked |
| Frost DK HIGH | 15 | 34 | 0 | ❌ over-extended, see below |
| Enh Shaman LOW | 28 | 3 | 7 | plausible, not deep-checked |
| Enh Shaman HIGH | 7 | 31 | 2 | ⚠️ defensible but blunt, see below |

**Havoc DH LOW and Frost DK HIGH were investigated in full and confirmed genuinely wrong for
part of their window, not just noisy.** Both share the same real root cause, and it's not a
fluke — worth fixing before this becomes a permanent tool.

### Root cause 1 — `categorize()` has real blind spots outside the context it was built for

`ModuleSpellReferenceService::categorize()` answers "what does this ability do in its owner's
own kit" — that's the question it was designed and tuned against (see CLAUDE.md's
"`categorize()` accuracy pass" section). Applying it to judge `INCOMING` enemy debuffs (a
different question — "is this landing on me a threat") already needed the `dr_category`
substitution documented above. **But it also has real mismaps even for `OWN_CAST` events,
which the earlier three-example validation never surfaced:**
- **Eye Beam** (Havoc DH's signature offensive nuke) bucketed `Defensive`.
- **Colossus Smash** (a Warrior offensive armor-reduction debuff, seen as an `INCOMING` event
  on the Frost DK) also bucketed `Defensive`.

Neither is a `dr_category` problem (both have `dr_category = null`, correctly — they're not
CC) — this is `categorize()`'s own Offensive/Defensive/Crowd Control/Utility heuristic
misfiring. Not fixed here — flagged as a real, separate finding for whoever next touches
`categorize()`, following the standing "flag, don't shotgun-patch mid-investigation"
discipline.

### Root cause 2 — the state model is too binary: one reactive-defensive signal overrides everything

Havoc DH LOW's early lockdown (Cheap Shot Stun → Garrote Silence → Kidney Shot Stun) was
**real and correctly detected** — confirmed independently by a genuine 6.6-second gap in the
DH's own cast log (12.56s→19.18s, nothing cast at all — consistent with being properly
CC-chained, not a classifier artifact). But from 20.80s onward the DH is producing dense,
continuous, real offense (Throw Glaive/Death Sweep/Chaos Strike spam) while `count($reactiveDefensive) > 0`
— which only needs **one** qualifying event anywhere in the 6s trailing lookback — kept
overriding it to `DEFENSIVE/PEELED` for almost the entire rest of the window regardless of how
much concurrent offense was happening. Frost DK HIGH shows the identical pattern: a genuinely
reactive Lichborne (correctly triggered by a real incoming Disarm) locks the state to
`DEFENSIVE/PEELED` through a dense Pillar of Frost / Breath of Sindragosa / Empower Rune
Weapon burst rotation that's clearly still offensively committed. **The binary either/or
model is wrong — real matches show players taking real pressure while still pushing offense,
and the current logic can't represent "pressured but still committed," only "fully one or the
other."**

### Root cause 3 — not every `dr_category` value represents the same severity of "shut down"

Enhancement Shaman HIGH's transition was driven by two `Blinding Sleet` debuff instances
landing almost simultaneously (12.13s/12.86s) — crossing the `debuffThreshold=2` — while the
Shaman kept casting Windstrike/Lightning Bolt/Doom Winds continuously throughout. This is more
defensible than the two cases above (Disorient genuinely does scramble a player's actions in
real WoW, unlike a hard Stun/Silence lockout) but still gets treated identically by the
current logic, which checks `dr_category !== null` uniformly. **Stun/Silence/Incapacitate
block all action; Root/Slow/Disarm/Disorient are partial restrictions** — a future version
should weight these differently rather than treating any tagged CC as an equally strong
"peeled" signal. Bonus finding from the same debug pass: the two `Blinding Sleet` debuff
instances carried **inconsistent `dr_category` values** (`Disorient` vs `Slow`) — almost
certainly the well-documented "same ability name, multiple spell_id copies, inconsistently
curated" pattern rather than a real distinction, one more reason not to trust a bare
`dr_category !== null` check too heavily yet.

## Not yet promoted to a permanent `app/Console/Commands/*` tool

Given the three root causes above, promoting this now would ship a tool that's confidently
wrong about roughly a quarter of the windows tested — not ready. Before promotion:
1. Report the two `categorize()` mismaps (Eye Beam, Colossus Smash) as a standalone finding —
   don't silently patch `categorize()` mid-investigation here.
2. Replace the binary override with something that can represent "pressured but still
   offensively committed" — e.g. a weighted/proportional read of recent Offensive vs Defensive
   signal density, rather than "any defensive signal wins outright."
3. Weight `dr_category` by real severity (hard lockout vs partial restriction) rather than
   treating every tagged CC identically.
4. Add the `COUNTERED GO` label (an `OFFENSIVE GO` segment that transitions to a *sustained*
   `DEFENSIVE/PEELED` segment within N seconds of the go-trigger cast) as an explicit,
   queryable output — still not built.
