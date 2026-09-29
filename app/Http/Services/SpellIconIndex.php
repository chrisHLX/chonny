<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\Spell;

/**
 * Ability name -> the current patch's Spell row to draw its icon from, for pages that only know an
 * ability by the name a combat log recorded (a match analysis). One query for a whole page.
 *
 * One visible ability is often several internal copies (CLAUDE.md rule 3): prefer the copy that
 * has an icon, then a pressable one. A name with no copy at all maps to null, and <x-spell-icon>
 * draws a plain placeholder rather than a broken image.
 */
class SpellIconIndex
{
    /** @var array<string, ?Spell> */
    private array $cache = [];

    /**
     * @param  array<int, string>  $names
     * @return array<string, ?Spell>
     */
    public function for(array $names): array
    {
        $missing = array_values(array_diff(array_unique(array_filter($names)), array_keys($this->cache)));

        if ($missing !== []) {
            $rows = Spell::query()
                ->where('patch_id', Patch::where('is_current', true)->value('id'))
                ->whereIn('name', $missing)
                ->orderByRaw('icon_name is null')
                ->orderBy('is_passive')
                ->orderBy('not_in_spellbook')
                ->get(['id', 'spell_id', 'name', 'icon_name', 'is_passive', 'not_in_spellbook']);

            foreach ($missing as $name) {
                $this->cache[$name] = $rows->firstWhere('name', $name);
            }

            // A log's names are not always spell names: "Melee" is the auto attack (the data's "Attack", 88163), and a variant
            // carries a suffix ("Dread Plague (Erupt)"). Retry those under the name the data uses.
            $alias = [];
            foreach ($missing as $name) {
                if ($this->cache[$name] === null) {
                    $base = $name === 'Melee' ? 'Attack' : trim(preg_replace('/\s*\([^)]*\)$/', '', $name));
                    if ($base !== $name) {
                        $alias[$name] = $base;
                    }
                }
            }
            if ($alias !== []) {
                $again = Spell::query()
                    ->where('patch_id', Patch::where('is_current', true)->value('id'))
                    ->whereIn('name', array_unique(array_values($alias)))
                    ->orderByRaw('icon_name is null')->orderBy('is_passive')
                    ->get(['id', 'spell_id', 'name', 'icon_name', 'is_passive', 'not_in_spellbook']);
                foreach ($alias as $name => $base) {
                    $this->cache[$name] = $again->firstWhere('name', $base);
                }
            }
        }

        return array_intersect_key($this->cache, array_flip($names));
    }
}
