<?php

namespace App\Support;

use App\Livewire\WowComps;

/**
 * The single definition of "this entry belongs on the Offensive / Defensive Cooldowns list".
 *
 * The rule itself is unchanged and still owned by WowComps, which holds the reviewed constants
 * (MIN_COOLDOWN_TAB_SECONDS and the two per-tab exception lists, each with its own docblock
 * explaining the specific abilities and why they clear the floor individually rather than via a
 * lower blanket number). What moved here is only the predicate, so a second caller — the user
 * guide builder, which offers offensive cooldowns in a "go" palette — asks the same question
 * rather than reimplementing it.
 *
 * This codebase has repeatedly paid for the alternative: the category badge map was copy-pasted
 * into six blades and the DR map into three before config/spell_display.php collapsed them, and
 * /spell-counters listed abilities no player can press because it filtered dr_category directly
 * instead of reusing SpellCounterIndexer's pressable definition. A duplicated predicate here would
 * drift the same way, and the symptom would be a guide palette offering a "cooldown" the rest of
 * the site does not consider one.
 *
 * Reads WowComps' constants rather than owning copies of them deliberately — those constants are
 * referenced by name throughout CLAUDE.md, and moving them would break every one of those
 * references for no benefit.
 */
class CooldownTabs
{
    /**
     * @param  mixed  $entry  a SpecKitComputer entry (SpellProfile, array-accessed)
     * @param  string  $direction  'offensive' | 'defensive'
     */
    public static function isEntry(mixed $entry, string $direction): bool
    {
        // isPriority is the arena-log-derived "this spec actually presses this" signal; without it
        // the list fills with abilities that are technically classified but never played.
        if (! ($entry['isPriority'] ?? false)) {
            return false;
        }

        if (! ($entry['offensiveDefensive'][$direction] ?? false)) {
            return false;
        }

        $exceptions = $direction === 'offensive'
            ? WowComps::OFFENSIVE_COOLDOWN_FLOOR_EXCEPTIONS
            : WowComps::DEFENSIVE_COOLDOWN_FLOOR_EXCEPTIONS;

        return ($entry['cooldown']['seconds'] ?? 0) >= WowComps::MIN_COOLDOWN_TAB_SECONDS
            || array_key_exists($entry['spell']->spell_id, $exceptions);
    }
}
