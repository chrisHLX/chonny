<?php

namespace App\Quiz\Wow;

use App\Http\Services\ArenaLogService;
use App\Http\Services\SpellCounterIndexer;
use App\Http\Services\TalentSelectionService;
use App\Http\Services\UserGuideChainService;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Support\CooldownTabs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the abilities a WoW quiz can ask about, from the same sources the rest of the site trusts.
 *
 * Only facts that are verified somewhere else get used, because a quiz that marks a right answer
 * wrong is worse than no quiz:
 *  - crowd control: the hand-curated DR category, on abilities a player actually presses;
 *  - offensive and defensive cooldowns: the arena-log classification behind WoW Comps' cooldown tabs;
 *  - interrupts: the curated is_interrupt flag.
 * categorize()'s effect-based guess is deliberately not used.
 *
 * Everything is cached against the spell cache version, so a data import changes the next quiz.
 */
class WowAbilityFacts
{
    /** Every class has these (Gladiator's Medallion), so they say nothing about a spec. */
    public const UNIVERSAL_SPELL_IDS = [336126];

    /** DR categories that are real crowd control. Slow, Knockback and Disarm are left out. */
    public const CC_CATEGORIES = ['Stun', 'Incapacitate', 'Disorient', 'Silence', 'Root'];

    public function __construct(
        private readonly UserGuideChainService $guides,
        private readonly SpellCounterIndexer $counters,
        private readonly TalentSelectionService $talents,
        private readonly ArenaLogService $arenaLogs,
    ) {}

    /**
     * The spec's own abilities that have at least one verified role.
     *
     * @return array<int, WowAbility>
     */
    public function specAbilities(Specialization $spec): array
    {
        $key = "quiz:wow:spec:{$spec->id}:v{$this->talents->spellCacheVersion()}";

        return $this->hydrate(Cache::remember($key, now()->addDay(), function () use ($spec) {
            $cc = $this->pressableCcIds();
            $className = $spec->gameClass?->name;

            return $this->guides->specKit($spec)
                ->filter(fn ($e) => ! $e['spell']->is_passive && ! $e['spell']->not_in_spellbook
                    && ! in_array($e['spell']->spell_id, self::UNIVERSAL_SPELL_IDS, true))
                ->map(function ($e) use ($cc, $className) {
                    $dr = $cc->contains($e['spell']->id) ? $e->drCategory() : null;
                    $cooldown = $e['cooldown']['seconds'] ?? null;

                    return new WowAbility(
                        spellId: $e['spell']->id,
                        name: $e->displayName(),
                        icon: $e['spell']->icon_name,
                        drCategory: in_array($dr, self::CC_CATEGORIES, true) ? $dr : null,
                        cooldown: $cooldown !== null ? (float) $cooldown : null,
                        offensive: CooldownTabs::isEntry($e, 'offensive'),
                        defensive: CooldownTabs::isEntry($e, 'defensive'),
                        interrupt: (bool) $e['spell']->is_interrupt && $cooldown !== null,
                        className: $className,
                    );
                })
                ->filter(fn (WowAbility $a) => $a->drCategory || $a->offensive || $a->defensive || $a->interrupt)
                // One entry per name: the kit can hold several copies of one ability. The copy with
                // the real cooldown wins.
                ->sortByDesc(fn (WowAbility $a) => $a->cooldown ?? 0)
                ->unique(fn (WowAbility $a) => $a->name)
                ->map(fn (WowAbility $a) => $a->toArray())
                ->values()
                ->all();
        }));
    }

    /**
     * Abilities from OTHER classes, for "which of these is yours" wrong answers. Never an ability
     * that any spec of this class has under the same name.
     *
     * @return array<int, WowAbility>
     */
    public function otherClassAbilities(Specialization $spec): array
    {
        $key = "quiz:wow:others:{$spec->class_id}:v{$this->talents->spellCacheVersion()}";

        return $this->hydrate(Cache::remember($key, now()->addDay(), function () use ($spec) {
            $patch = $this->patch();
            if (! $patch) {
                return [];
            }

            $classification = $this->arenaLogs->offensiveDefensiveClassification()['bySpellId'];

            $ownNames = Spell::where('patch_id', $patch->id)
                ->whereHas('classAvailability', fn ($q) => $q->where('class_id', $spec->class_id))
                ->get(['id', 'name'])
                ->map(fn (Spell $s) => $s->display_name)
                ->flip();

            $ids = $this->pressableCcIds()->merge(
                Spell::where('patch_id', $patch->id)->whereIn('spell_id', array_keys($classification))->pluck('id')
            )->unique();

            return Spell::whereIn('id', $ids)
                ->where('is_passive', false)
                ->where('not_in_spellbook', false)
                ->whereNotNull('icon_name')
                ->whereNotIn('spell_id', self::UNIVERSAL_SPELL_IDS)
                ->whereDoesntHave('classAvailability', fn ($q) => $q->where('class_id', $spec->class_id))
                ->with('classAvailability.gameClass')
                ->get()
                ->reject(fn (Spell $s) => $ownNames->has($s->display_name))
                ->map(fn (Spell $s) => (new WowAbility(
                    spellId: $s->id,
                    name: $s->display_name,
                    icon: $s->icon_name,
                    drCategory: in_array($s->dr_category, self::CC_CATEGORIES, true) ? $s->dr_category : null,
                    offensive: $classification[$s->spell_id]['offensive'] ?? false,
                    defensive: $classification[$s->spell_id]['defensive'] ?? false,
                    className: $s->classAvailability->first()?->gameClass?->name,
                ))->toArray())
                ->unique('name')
                ->values()
                ->all();
        }));
    }

    /**
     * Every pressable crowd control ability in the game, for "which shares DR with this" questions.
     * Talent-conditional ones (Holy Word: Chastise) are left out, since their group depends on a build.
     *
     * @return array<int, WowAbility>
     */
    public function ccPool(): array
    {
        $key = "quiz:wow:ccpool:v{$this->talents->spellCacheVersion()}";

        return $this->hydrate(Cache::remember($key, now()->addDay(), function () {
            $patch = $this->patch();
            if (! $patch) {
                return [];
            }

            return $this->counters->pressableCcSpells($patch)
                ->filter(fn (Spell $s) => in_array($s->dr_category, self::CC_CATEGORIES, true)
                    && $s->conditional_dr_gating_spell_id === null
                    && $s->icon_name !== null
                    && ! $s->is_passive && ! $s->not_in_spellbook)
                ->map(fn (Spell $s) => (new WowAbility(
                    spellId: $s->id,
                    name: $s->display_name,
                    icon: $s->icon_name,
                    drCategory: $s->dr_category,
                ))->toArray())
                ->unique('name')
                ->values()
                ->all();
        }));
    }

    /** @return array<int, WowAbility> */
    private function hydrate(array $rows): array
    {
        return array_map(fn (array $a) => WowAbility::fromArray($a), $rows);
    }

    private function pressableCcIds(): Collection
    {
        $patch = $this->patch();

        return $patch ? $this->counters->pressableCcSpells($patch)->pluck('id') : collect();
    }

    private ?Patch $patchMemo = null;

    private function patch(): ?Patch
    {
        return $this->patchMemo ??= Patch::where('is_current', true)
            ->whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->first();
    }
}
