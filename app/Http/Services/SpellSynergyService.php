<?php

namespace App\Http\Services;

use App\Models\ModuleGameBuild;
use App\Models\Spell;
use Illuminate\Support\Collection;

/**
 * What changes an ability, and by how much — the data behind a guide's Synergy section.
 *
 * Nothing here is new knowledge. `spell_relationships` already records which talents modify which
 * ability and with what magnitude (imported from the dump's own "Affecting Spells" and
 * "Modified By" lines, never inferred), and a spell's Variables block names the talents its own
 * damage formula multiplies by. This service is the join: given one subject ability, it returns
 * everything that touches it, so a guide author picks from a list instead of remembering.
 *
 * TWO SOURCES, DELIBERATELY KEPT APART in the output:
 *
 *  - `modifiers` — ModuleSpellReferenceService::modifiersFor()'s own named/potential entries, each
 *    carrying the relationship type and, when the data holds one, a real magnitude ("-3 sec",
 *    "+1 charge"). These are structural: a talent that modifies this spell.
 *  - `formula` — the talents a spell's own damage formula multiplies by, read off the Variables
 *    block (`variablesModifiers()`). Penance's Power of the Dark Side is only visible here: it is
 *    a term in `$penancedamage`, not a `spell_relationships` row.
 *
 * A talent can appear in both, and that is not a duplicate to suppress — "modifies its cooldown"
 * and "is a term in its damage" are different facts about the same pair.
 *
 * ORDERED BY HOW MUCH IS KNOWN, not by importance: entries with a real magnitude first, then the
 * rest alphabetically. The service has no opinion about which interaction matters, and should not
 * pretend to — that judgement is exactly what the guide author is being asked for.
 */
class SpellSynergyService
{
    public function __construct(private readonly ModuleSpellReferenceService $spells) {}

    /**
     * Everything our data says changes $subject, for an author to choose from.
     *
     * @return array{modifiers: Collection<int, array<string, mixed>>, formula: Collection<int, Spell>}
     */
    public function candidatesFor(Spell $subject, ModuleGameBuild $build): array
    {
        $groups = $this->spells->modifiersFor($subject, $build);

        // 'baseline' is deliberately left out: those are class-wide auras every character of the
        // spec already has, so "this changes your Penance" is true of every Discipline Priest and
        // tells a reader nothing about a build. Named and potential are the ones a build decides.
        // Deduped by DISPLAY NAME, not by spell id. One visible ability is routinely several
        // internal spell_id copies (CLAUDE.md rule 3), and each copy can carry its own
        // relationship row: Penance's raw candidate list holds Atonement six times and Blaze of
        // Light three times, which is a useless thing to hand an author. The copy that carries a
        // real magnitude wins, so "-1.5 seconds" is never dropped in favour of a bare "modifies
        // it" from a sibling that happens to sort first.
        $modifiers = collect($groups['named'] ?? [])
            ->merge($groups['potential'] ?? [])
            ->filter(fn (array $e) => ($e['spell'] ?? null) instanceof Spell)
            ->sortByDesc(fn (array $e) => $e['modifier_value'] !== null ? 1 : 0)
            ->unique(fn (array $e) => $e['spell']->display_name.':'.($e['relationship_type'] ?? ''))
            ->sortBy([
                fn (array $a, array $b) => ($b['modifier_value'] !== null) <=> ($a['modifier_value'] !== null),
                fn (array $a, array $b) => strcmp($a['spell']->display_name, $b['spell']->display_name),
            ])
            ->values();

        return [
            'modifiers' => $modifiers,
            'formula' => $this->formulaTalents($subject),
        ];
    }

    /**
     * The talents a spell's own damage/healing formula multiplies by.
     *
     * variablesModifiers() resolves the $?a<id> conditions in a Variables block to real spells by
     * name. It is the only place Power of the Dark Side shows up as something that changes
     * Penance — there is no spell_relationships row for it, because the relationship lives in the
     * arithmetic rather than in an aura.
     *
     * @return Collection<int, Spell>
     */
    public function formulaTalents(Spell $subject): Collection
    {
        return collect($this->spells->variablesModifiers($subject))
            ->filter(fn ($s) => $s instanceof Spell)
            ->unique('id')
            ->sortBy('display_name')
            ->values();
    }

    /**
     * One modifier entry, flattened for display: the ability, what it does, and the magnitude when
     * there is one.
     *
     * The phrasing stays close to the relationship type rather than inventing prose, because the
     * type is what the data actually asserts. A missing magnitude prints nothing at all — never a
     * zero, and never a guess at the size of an effect the dump did not record.
     *
     * @param  array<string, mixed>  $entry
     * @return array{spell: Spell, effect: string, magnitude: ?string}
     */
    public function describe(array $entry): array
    {
        $type = (string) ($entry['relationship_type'] ?? 'modifies');
        $value = $entry['modifier_value'] ?? null;
        $unit = $entry['modifier_unit'] ?? null;

        $effect = match ($type) {
            'modifies_cooldown' => 'Changes its cooldown',
            'modifies_charges' => 'Changes its charges',
            'modifies_duration' => 'Changes its duration',
            default => 'Modifies it',
        };

        $magnitude = null;
        if ($value !== null) {
            $number = rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
            $magnitude = ((float) $value > 0 ? '+' : '').$number.($unit ? ' '.$unit : '');
        }

        return ['spell' => $entry['spell'], 'effect' => $effect, 'magnitude' => $magnitude];
    }
}
