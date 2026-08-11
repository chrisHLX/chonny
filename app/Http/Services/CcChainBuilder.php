<?php

namespace App\Http\Services;

use App\Models\Spell;
use Illuminate\Support\Collection;

/**
 * Deterministic CC-chain sequencer for the Synergies tab — see CLAUDE.md's "Synergies tab" and
 * "Chain Construction Algorithm" plan sections. Pure computation over curated `spells.dr_category`/
 * `cast_type`/`chain_target` data, no AI involvement at runtime.
 *
 * Rules, in priority order:
 * 1. Open with an instant CC, prioritizing Stun over every other category — corrected 2026-08-11
 *    after the first version (shortest-cooldown-wins, applied across all categories) produced
 *    the wrong Hunter order: Freezing Trap (cd 30s) opened instead of Intimidation (cd 60s).
 *    The domain expert's correction: the opener is chosen by CATEGORY first (Stun beats
 *    everything else when a Stun is available and instant), cooldown only breaks a tie *within*
 *    that category. See CLAUDE.md for the full trace — this was a real, confirmed mistake in
 *    the first implementation, not a hypothetical.
 * 2. At every later position, prefer a remaining spell whose dr_category differs from the
 *    immediately-preceding entry — same cooldown-based tiebreak as the opener among eligible
 *    candidates.
 * 3. When no different-category option remains, place a same-category spell anyway and mark it
 *    DR'd — diminished, not dropped (a half-duration Fear is still real information).
 * 4. "DR'd" is computed against ANY earlier entry in the chain sharing the same dr_category, not
 *    just the immediately-preceding one — real DR triggers on any repeat within the burst
 *    window, not only strict list-adjacency; rule 2's insertion is a sequencing preference, not
 *    a guarantee that non-adjacent repeats escape DR.
 *
 * DR percentage math (2026-08-11, corrected same day) — 1st application in a category = 100%,
 * 2nd = 50%, 3rd+ = fully immune. NOT a 3-step 100/50/25 falloff — the domain expert corrected
 * this directly after the first version shipped: current-patch (12.1) PvP DR is two diminished
 * steps then immunity, not three. This is computed here as `dr_percentage`/`dr_immune` on every
 * entry, and is trustworthy on its own — it only depends on *occurrence count within the chain*,
 * not on any per-spell duration data (which is confirmed unreliable elsewhere in this codebase,
 * see `spells.duration_seconds`'s known-bad values). Deliberately NOT computing an actual seconds
 * value here yet, even though the domain expert also confirmed a real mechanic (see
 * PVP_CC_DURATION_CAP_SECONDS below) — applying a flat 6s to every entry would overstate
 * genuinely-short effects (many Stuns are naturally under 6s even before any cap), and there's no
 * trustworthy per-spell base duration yet to take the real MIN(base, 6s) of. `dr_percentage`
 * alone is real, confirmed, and safe to show now.
 */
class CcChainBuilder
{
    /**
     * STANDING RULE for `chain_target=kill_target`/`both` curation (domain expert, 2026-08-11):
     * kill-target CC must NOT break on damage, since the entire point is to combo it with the
     * damage that's killing the target. Only Stun and Silence dr_category values qualify —
     * Incapacitate/Disorient (Freezing Trap, Fear, Polymorph, Dragon's Breath, Cyclone) all break
     * on damage and can only ever be `healer` (or left unclassified), never `kill_target`. Two
     * real spells (Freezing Trap, Fear) were mistakenly tagged kill_target before this rule was
     * articulated and had to be corrected — see CLAUDE.md's "chain_target rule articulated"
     * section. Check this before adding any new kill_target/both classification.
     */


    /**
     * Retail's flat PvP CC duration ceiling — sourced from Icy Veins, confirmed by the domain
     * expert as a "solid, patch-note-confirmed mechanic": every CC effect is clamped to this many
     * seconds the instant it lands on a player, independent of its PvE tooltip duration, *before*
     * any DR reduction is applied on top. Not a formula or a ratio of PvE duration — a hard
     * engine-level clamp. The one documented exception is Evoker's Oppressing Roar, which raises
     * the ceiling for buffed allies — not modeled as a spell-level override here, since nothing
     * currently consumes this constant numerically (see the class docblock for why it isn't yet
     * applied as a computed per-entry duration). A class constant, not a `spells` column — this
     * is a system-wide engine rule, not per-spell data.
     */
    public const PVP_CC_DURATION_CAP_SECONDS = 6;

    /**
     * @param  Collection<int, Spell>  $spells  must all share the same chain_target already —
     *                                           this method does not group by chain_target itself.
     * @return array<int, array{spell: Spell, dr_applied: bool, dr_reason: ?string, dr_percentage: int, dr_immune: bool}>
     */
    public function buildChain(Collection $spells): array
    {
        $remaining = $spells->values();
        $chain = [];
        $seenCategories = []; // dr_category => ['name' => first-occurrence spell name, 'count' => occurrences so far]

        while ($remaining->isNotEmpty()) {
            $previousCategory = $chain === [] ? null : $chain[count($chain) - 1]['spell']->dr_category;

            $candidates = $previousCategory === null
                ? $remaining
                : $remaining->filter(fn (Spell $s) => $s->dr_category !== $previousCategory);

            // Rule 3 fallback — nothing avoids the collision, so pick from every remaining spell.
            if ($candidates->isEmpty()) {
                $candidates = $remaining;
            }

            $next = $chain === []
                ? $this->pickOpener($candidates)
                : $this->pickNext($candidates);

            $seen = $seenCategories[$next->dr_category] ?? null;
            $drApplied = $seen !== null && $seen['name'] !== $next->name;
            $drReason = $drApplied
                ? "shares {$next->dr_category} category with {$seen['name']}"
                : null;

            $occurrence = ($seen['count'] ?? 0) + 1;
            $drPercentage = match (min($occurrence, 3)) {
                1 => 100,
                2 => 50,
                default => 0,
            };

            $chain[] = [
                'spell' => $next,
                'dr_applied' => $drApplied,
                'dr_reason' => $drReason,
                'dr_percentage' => $drPercentage,
                'dr_immune' => $drPercentage === 0,
            ];
            $seenCategories[$next->dr_category] = ['name' => $seen['name'] ?? $next->name, 'count' => $occurrence];

            $remaining = $remaining->reject(fn (Spell $s) => $s->id === $next->id)->values();
        }

        return $chain;
    }

    /**
     * Rule 1's opener pick: restrict to instant CC; if any are Stun, restrict further to just
     * those (Stun beats every other category as an opener). Cooldown only breaks a tie within
     * whatever category-filtered set remains.
     *
     * KNOWN UNRESOLVED TIE: when two+ instant Stuns are both available (e.g. a Hunter comp with
     * both Intimidation and Binding Shot), neither dr_category nor cooldown explains why the
     * domain expert's worked example prefers Intimidation (cd 60s) over Binding Shot (cd 45s,
     * shorter). Falls back to input order for now — arbitrary, not derived from any stated rule.
     * Flagged rather than guessed at; do not treat this tiebreak as settled.
     */
    private function pickOpener(Collection $candidates): Spell
    {
        $instants = $candidates->filter(fn (Spell $s) => $s->cast_type === 'instant');
        $pool = $instants->isNotEmpty() ? $instants : $candidates;

        $stuns = $pool->filter(fn (Spell $s) => $s->dr_category === 'Stun');
        $pool = $stuns->isNotEmpty() ? $stuns : $pool;

        // No cooldown-based sort here deliberately — cooldown was already tried and disproven
        // as the opener tiebreak once (see class docblock). Whatever's first in the given
        // collection order wins; callers control that order until a real rule is confirmed.
        return $pool->first();
    }

    /**
     * Later-position pick (rule 2's tiebreak among same-eligibility candidates): prefer instant
     * over cast, then shortest real cooldown, treating a null cooldown as "not
     * resource-constrained" (deprioritized, not disqualified). Unchanged from the first version
     * — this part was never wrong, only the opener rule was.
     */
    private function pickNext(Collection $candidates): Spell
    {
        return $candidates
            ->sortBy([
                fn (Spell $a, Spell $b) => ($a->cast_type === 'instant' ? 0 : 1) <=> ($b->cast_type === 'instant' ? 0 : 1),
                fn (Spell $a, Spell $b) => ($a->cooldown_seconds ?? PHP_FLOAT_MAX) <=> ($b->cooldown_seconds ?? PHP_FLOAT_MAX),
            ])
            ->first();
    }
}
