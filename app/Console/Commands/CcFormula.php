<?php

namespace App\Console\Commands;

use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Hypothetical CC-chain builder for a 3-spec comp — a standalone CLI tool for testing the
 * "simplest version" formula worked out 2026-08-23 before it goes anywhere near the live
 * WoW Comps Crowd Control page, per direct instruction ("start building as a tool that we
 * can use on hypotheticals before we bring it into the current crowd control page").
 *
 * Built entirely from patterns confirmed against the real 420-match archive the same day
 * (see wow:find-cc-chains and this session's several analysis passes over its output), NOT
 * theorized from first principles:
 * - Openers are overwhelmingly instant (81% of real openers vs 73% of later steps) — this tool
 *   treats "instant" as a soft preference, not a hard filter, when picking which candidate
 *   leads a category.
 * - Healers are meaningfully less likely to open than to follow up (29% of real openers vs 43%
 *   of later steps) — same treatment, a soft preference away from a healer-cast candidate when
 *   an equally-good non-healer one exists.
 * - Category diversity is what actually buys duration (1 distinct category ≈9.5s avg real
 *   lockdown, 2≈11.3s, 3≈13.3s, 4≈14.7s) — so the sequence never repeats a category while a
 *   fresh one is still available, same principle CcChainBuilder already encoded once, rewritten
 *   here rather than reused because CcChainBuilder's own opener rule (a hardcoded "Stun always
 *   wins if instant" priority, from the original RMP/Hunter worked examples) is now known to be
 *   too rigid — real data shows Stun/Incapacitate/Disorient open chains at roughly equal rates,
 *   not Stun-first. CcChainBuilder itself is unused/dormant elsewhere in this codebase (see
 *   WowComps' own docblock history) and is left untouched.
 *
 * DELIBERATELY OUT OF SCOPE for this pass (per the same conversation that specified it): AOE
 * vs single-target, "has a gap closer" as a positional/execution factor, and cross-comp CC
 * denial (locking down players who would otherwise interrupt/peel the go) — all real and
 * useful for a *future* version, but not tractable to reconstruct from raw spell data alone
 * without new curated fields (spell range, gap-closer lists) that don't exist yet. This tool
 * only uses data that's already real and populated: dr_category, cast_type, chain_target,
 * pvp_duration_seconds, and each spec's real role (healer vs not, via
 * ArenaLogService::isHealerSpec()-equivalent classification already used elsewhere).
 *
 * KILL-TARGET ALLOCATION: per the Hammer-of-Justice-vs-Hunter-stun example this tool was built
 * to formalize — if the comp has a non-breaking (Stun/Silence) CC available from a non-healer
 * spec beyond what's needed for the healer-lock sequence, one is reserved and reported
 * separately as "available to lock the kill target" rather than folded into the healer chain,
 * since chain_target=kill_target/both is only ever valid for Stun/Silence (the categories that
 * don't break on damage) — matches CcChainBuilder's own already-documented rule.
 *
 * Reads a spec's FULL possible kit (every real talent-tree entry + PvP talent + verified/
 * explicit-cooldown baseline ability), not whatever one specific TalentBuild happens to have
 * selected — deliberate, since this is for exploring hypotheticals ("what could this comp do"),
 * not describing one particular already-built loadout.
 *
 * Usage: php artisan wow:cc-formula priest discipline druid restoration rogue subtlety
 */
class CcFormula extends Command
{
    private const HARD_CC_CATEGORIES = ['Stun', 'Silence', 'Incapacitate', 'Disorient'];

    protected $signature = 'wow:cc-formula
        {class1} {spec1}
        {class2} {spec2}
        {class3} {spec3}';

    protected $description = 'Build a hypothetical CC chain for a 3-spec comp from real, data-confirmed patterns — standalone tool, not yet wired into the live WoW Comps page';

    public function handle(TalentSelectionService $talentService): int
    {
        $specs = [];

        foreach ([1, 2, 3] as $i) {
            $classSlug = $this->argument("class{$i}");
            $specSlug = $this->argument("spec{$i}");

            $class = GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->where('slug', $classSlug)->first();

            if (!$class) {
                $this->error("Unknown class slug '{$classSlug}'.");

                return self::FAILURE;
            }

            $spec = Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first();

            if (!$spec) {
                $this->error("Unknown spec slug '{$specSlug}' for class '{$classSlug}'.");

                return self::FAILURE;
            }

            $specs[] = ['class' => $class, 'spec' => $spec];
        }

        $healerSlugs = [
            ['druid', 'restoration'], ['shaman', 'restoration'], ['priest', 'holy'], ['priest', 'discipline'],
            ['paladin', 'holy'], ['monk', 'mistweaver'], ['evoker', 'preservation'],
        ];
        $isHealer = fn (string $classSlug, string $specSlug) => in_array([$classSlug, $specSlug], $healerSlugs, true);

        // Pool every hard-CC candidate across all 3 specs' FULL possible kit (not one build's
        // selections — see this class's own docblock for why).
        $pool = collect();

        foreach ($specs as $entry) {
            $class = $entry['class'];
            $spec = $entry['spec'];
            $healer = $isHealer($class->slug, $spec->slug);

            $ids = $talentService->allTalentSpellIds($spec->id)
                ->merge($talentService->allPvpTalentSpellIds($spec->id))
                ->merge($talentService->verifiedBaselineAbilityIds($spec->id))
                ->merge($talentService->explicitBaselineCooldownAbilityIds($class->id, $spec->id))
                ->unique();

            $spells = Spell::whereIn('id', $ids)
                ->whereIn('dr_category', self::HARD_CC_CATEGORIES)
                ->get();

            foreach ($spells as $spell) {
                $pool->push([
                    'spell' => $spell,
                    'label' => "{$spec->name} {$class->name}",
                    'isHealer' => $healer,
                ]);
            }
        }

        // Dedup by spell->id — but when the SAME ability is reachable from more than one spec
        // in the comp (e.g. Hammer of Justice, baseline on all 3 Paladin specs), prefer a
        // non-healer attribution over a healer one, rather than whichever spec happened to be
        // pushed into the pool first. Fixed 2026-08-23 after a real, confirmed bug: specs are
        // always iterated Healer-first, so a plain ->unique() silently kept the HEALER's copy of
        // any shared baseline ability every time — permanently mis-scoring it as healer-cast
        // (worse priority, see $score below) even when a DPS spec in the same comp could equally
        // have pressed it. Confirmed via Holy/Ret/Arms: Hammer of Justice was being attributed to
        // Holy Paladin instead of Retribution, which is why it lost to Shockwave for the
        // kill-target slot despite having the longer curated duration.
        $pool = $pool->groupBy(fn ($e) => $e['spell']->id)
            ->map(fn ($group) => $group->firstWhere('isHealer', false) ?? $group->first())
            ->values();

        if ($pool->isEmpty()) {
            $this->warn('No hard-CC (Stun/Silence/Incapacitate/Disorient) abilities found for this comp at all.');

            return self::SUCCESS;
        }

        // --- Setup-CC pool, added 2026-08-23 for pairs_with_category (e.g. Solar Beam/Ring of
        // Frost — stationary ground-zone effects that need the target ROOTED in place to
        // actually land, confirmed via their own description text + a real arena-log
        // co-occurrence check; see cc-synergies-overrides.txt's header for the full reasoning).
        // Pulled from the same FULL-kit source as the hard-CC pool above but NOT restricted to
        // HARD_CC_CATEGORIES — Root isn't one of the four hard-CC categories itself (it doesn't
        // diminish Stun/Silence/Incapacitate/Disorient), it only matters here as a prerequisite
        // for a specific other step, never as an independent chain link of its own.
        $setupPool = collect();

        foreach ($specs as $entry) {
            $class = $entry['class'];
            $spec = $entry['spec'];
            $healer = $isHealer($class->slug, $spec->slug);

            $ids = $talentService->allTalentSpellIds($spec->id)
                ->merge($talentService->allPvpTalentSpellIds($spec->id))
                ->merge($talentService->verifiedBaselineAbilityIds($spec->id))
                ->merge($talentService->explicitBaselineCooldownAbilityIds($class->id, $spec->id))
                ->unique();

            $spells = Spell::whereIn('id', $ids)->whereNotNull('dr_category')->get();

            foreach ($spells as $spell) {
                $setupPool->push([
                    'spell' => $spell,
                    'label' => "{$spec->name} {$class->name}",
                    'isHealer' => $healer,
                ]);
            }
        }

        $setupPool = $setupPool->groupBy(fn ($e) => $e['spell']->id)
            ->map(fn ($group) => $group->firstWhere('isHealer', false) ?? $group->first())
            ->values();

        $this->info('=== Comp: '.$specs[0]['spec']->name.' '.$specs[0]['class']->name.' / '.$specs[1]['spec']->name.' '.$specs[1]['class']->name.' / '.$specs[2]['spec']->name.' '.$specs[2]['class']->name.' ===');
        $this->newLine();

        // Priority score for picking "the best" candidate among ties — lower is better, compared
        // element-by-element (Collection::sortBy accepts an array key, sorted lexicographically).
        // Instant beats cast beats unknown (81%/73% real-opener/later split); non-healer beats
        // healer (29%/43% real split) — both soft preferences, not hard filters, matching how
        // the real data actually looked (neither factor was anywhere near a 100/0 split). Third
        // tie-break, added 2026-08-23 (direct instruction, a simple rule for resolving ties
        // between two otherwise-equal instant CC options): prefer the LONGER real
        // pvp_duration_seconds — negated so a bigger real duration sorts first. A missing
        // curated duration is treated as 0 for this comparison only (sorts last among tied
        // candidates that do have a real number, never given an unfair advantage from being
        // unknown).
        $score = function (array $entry): array {
            $ct = $entry['spell']->cast_type;
            $ctScore = match ($ct) {
                'instant' => 0,
                'cast' => 1,
                default => 2,
            };
            $healerScore = $entry['isHealer'] ? 1 : 0;
            $durationScore = -(float) ($entry['spell']->pvp_duration_seconds ?? 0);

            return [$ctScore * 10 + $healerScore, $durationScore];
        };

        // --- Kill-target reservation: a non-breaking (Stun/Silence) candidate from a non-healer
        // spec, held back from the healer-lock sequence. Eligibility comes from dr_category
        // alone (Stun/Silence are structurally the only categories that can ever go on a kill
        // target — CcChainBuilder's own already-established rule), NOT from the separately-
        // curated chain_target field, which is only populated on ~16 of the ~90 tagged hard-CC
        // spells — requiring it explicitly made this check silently under-trigger for the large
        // majority of real Stun/Silence abilities that are eligible by the rule but have never
        // had chain_target set. An explicit chain_target='healer' (a deliberate "not for this"
        // annotation, distinct from merely unset) is still respected as an exclusion.
        $killTargetCandidates = $pool->filter(fn ($e) => !$e['isHealer']
            && in_array($e['spell']->dr_category, ['Stun', 'Silence'], true)
            && $e['spell']->chain_target !== 'healer');

        $reserved = $killTargetCandidates->sortBy($score)->first();

        if ($reserved) {
            $this->info('Available to lock the KILL TARGET (does not break on damage, so damage can land while it holds):');
            $setup = $this->findSetupStep($reserved, $setupPool);
            if ($setup) {
                $this->line('  requires setup: '.$setup['spell']->display_name.' ('.$setup['spell']->dr_category.') — '.$setup['label'].', to hold the target in place first');
            } elseif ($reserved['spell']->pairs_with_category !== null) {
                $this->line('  ⚠ '.$reserved['spell']->display_name.' needs a '.$reserved['spell']->pairs_with_category.' to actually land (it\'s a ground-zone effect a mobile target can just walk out of) — this comp has none available.');
            }
            $this->line('  '.$reserved['spell']->display_name.' ('.$reserved['spell']->dr_category.', '.($reserved['spell']->cast_type ?? 'cast_type unknown').') — '.$reserved['label']);
            $this->newLine();
            $pool = $pool->reject(fn ($e) => $e['spell']->id === $reserved['spell']->id);
        } else {
            $this->line('No non-breaking (Stun/Silence) CC available from a non-healer spec to spare for the kill target — every hard-CC option here is needed for (or better suited to) the healer lock.');
            $this->newLine();
        }

        // --- Healer-lock sequence: greedy, never repeat a category while a fresh one exists. ---
        $byCategory = $pool->groupBy(fn ($e) => $e['spell']->dr_category);
        $usedCategories = [];
        $sequence = [];

        while (true) {
            $available = $byCategory->reject(fn ($group, $cat) => in_array($cat, $usedCategories, true));

            if ($available->isEmpty()) {
                break;
            }

            // Among still-fresh categories, pick whichever category's BEST candidate scores
            // best overall — i.e. an instant, non-healer option in category A beats a cast-time,
            // healer-only option in category B, even though both categories are equally "fresh".
            $bestPerCategory = $available->map(fn ($group, $cat) => $group->sortBy($score)->first());
            $chosenCategory = $bestPerCategory->sortBy($score)->keys()->first();
            $chosen = $bestPerCategory[$chosenCategory];

            // Same-caster ranged-follow-up preference, added 2026-08-23 (direct instruction): if
            // this caster already has an EARLIER step in the sequence and their best pick here
            // requires melee range, prefer a same-category, same-caster alternate that doesn't —
            // a melee character who already committed to one CC has likely repositioned toward
            // the kill target to deal damage, so a second melee-range CC from them costs a real
            // trip back to the healer that a ranged option doesn't. Only substitutes within the
            // SAME caster (a different caster's first melee use has no such repositioning cost)
            // and only when a confirmed-ranged alternate actually exists.
            //
            // Deliberately uses isMeleeRange()'s parsed range_yards, NOT spells.spell_type
            // directly — confirmed live that spell_type reflects damage-SCALING family (melee
            // weapon damage vs. spell damage), not actual cast range: Storm Bolt is tagged
            // spell_type='Melee' despite being a genuinely thrown, 30-yard ability. range_yards
            // is the mechanically honest field for "does the caster need to be physically close."
            $alreadyUsedLabels = array_column($sequence, 'label');
            if (in_array($chosen['label'], $alreadyUsedLabels, true) && $this->isMeleeRange($chosen['spell']) === true) {
                $rangedAlternate = $byCategory[$chosenCategory]
                    ->filter(fn ($e) => $e['label'] === $chosen['label']
                        && $e['spell']->id !== $chosen['spell']->id
                        && $this->isMeleeRange($e['spell']) === false)
                    ->sortBy($score)
                    ->first();

                if ($rangedAlternate) {
                    $chosen = $rangedAlternate;
                }
            }

            $sequence[] = $chosen;
            $usedCategories[] = $chosenCategory;
        }

        $this->info('Suggested healer-lock sequence (never repeats a category while a fresh one is available):');
        $totalDuration = 0.0;
        $missingDuration = false;

        foreach ($sequence as $i => $entry) {
            $spell = $entry['spell'];
            $dur = $spell->pvp_duration_seconds;
            $durLabel = $dur !== null ? "{$dur}s" : 'no curated duration';
            if ($dur !== null) {
                $totalDuration += (float) $dur;
            } else {
                $missingDuration = true;
            }
            $ct = $spell->cast_type ?? 'unknown';
            $range = match ($this->isMeleeRange($spell)) {
                true => 'melee-range',
                false => 'ranged',
                null => 'range unknown',
            };
            $healerFlag = $entry['isHealer'] ? ' [healer-cast]' : '';

            $setup = $this->findSetupStep($entry, $setupPool);
            if ($setup) {
                $this->line('  '.($i + 1).'. [setup] '.$setup['spell']->display_name.' ('.$setup['spell']->dr_category.') — '.$setup['label'].', to hold the target in place first');
            } elseif ($spell->pairs_with_category !== null) {
                $this->line('  '.($i + 1).'. ⚠ '.$spell->display_name.' needs a '.$spell->pairs_with_category.' to actually land (it\'s a ground-zone effect a mobile target can just walk out of) — this comp has none available.');
            }

            $this->line('  '.($i + 1).". {$spell->display_name} ({$spell->dr_category}, {$ct}, {$range}, {$durLabel}) — {$entry['label']}{$healerFlag}");

            // Show alternates in this same category, in case the "best" pick isn't available
            // in a real build (e.g. not talented) — informational only, not part of the chain.
            $alternates = $byCategory[$spell->dr_category]->reject(fn ($e) => $e['spell']->id === $spell->id);
            foreach ($alternates as $alt) {
                $this->line('       (alt: '.$alt['spell']->display_name.' — '.$alt['label'].')');
            }
        }

        $this->newLine();
        $this->info('Estimated total healer lockdown: '.round($totalDuration, 1).'s'.($missingDuration ? ' (at least one step has no curated pvp_duration_seconds — real total is somewhat higher than this)' : ''));

        return self::SUCCESS;
    }

    /**
     * Whether $spell requires the caster to be within melee-ish range, parsed from the raw
     * `range_yards` string (e.g. "5 yards", "30 yards", "6 - 25 yards" — the lower bound is used
     * for a min-max range). Threshold is 10 yards: covers pure melee (Cheap Shot, 5 yards) and
     * Sap's slightly more forgiving 10-yard stealth-approach range, while correctly excluding
     * genuinely ranged/thrown abilities (Storm Bolt, 30 yards; Blind, 15 yards) — verified
     * against real spell_id values before picking this cutoff, not guessed. Returns null when
     * range_yards is missing or unparseable — an unknown range never counts as confirmed-melee
     * OR confirmed-ranged, so it can neither trigger nor satisfy the substitution rule above.
     */
    private function isMeleeRange(Spell $spell): ?bool
    {
        if ($spell->range_yards === null || !preg_match('/(\d+)/', $spell->range_yards, $m)) {
            return null;
        }

        return (int) $m[1] <= 10;
    }

    /**
     * If $entry's spell has a pairs_with_category requirement (e.g. Solar Beam needs a Root —
     * see cc-synergies-overrides.txt), finds the best available setup-CC candidate for it from
     * the comp's full kit. Prefers the SAME caster's own matching ability first (e.g. Balance
     * Druid's own Entangling Roots naturally pairs with their own Solar Beam, no cross-teammate
     * timing coordination needed) and falls back to any teammate's matching ability. Returns
     * null when the spell has no requirement at all, OR when it has one but nothing in the
     * comp's kit satisfies it (the caller is responsible for distinguishing those two cases via
     * $entry['spell']->pairs_with_category — this method alone can't tell "no requirement" from
     * "unsatisfied requirement").
     *
     * @return array{spell: Spell, label: string, isHealer: bool}|null
     */
    private function findSetupStep(array $entry, Collection $setupPool): ?array
    {
        $neededCategory = $entry['spell']->pairs_with_category;

        if ($neededCategory === null) {
            return null;
        }

        $candidates = $setupPool->filter(fn ($e) => $e['spell']->dr_category === $neededCategory);

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->firstWhere('label', $entry['label']) ?? $candidates->first();
    }
}
