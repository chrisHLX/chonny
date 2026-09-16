<?php

namespace App\Http\Services;

use App\Models\Game;
use App\Models\Patch;

/**
 * Decides which patches ROW an import writes into, and what LABEL that row should carry.
 *
 * WHY THIS EXISTS. Every patch-scoped table (spells, talent_trees, pvp_talents, talent_builds,
 * spell_counters, spell_data_updates, …) points at patches.id, and so does everything curated on
 * top of them: admin-default, personal, module- and guide-linked talent builds, and
 * module_spell_references. Until 2026-09-16 an unseen build_version CREATED a new row, so importing
 * a real new game build forked the whole dataset away from all of that. The workaround was to keep
 * re-importing under a frozen string (12.0.7.68453 on production, 12.0.7.68887 locally), so the
 * site could never say which patch its data was actually on.
 *
 * The row is the dataset's identity; build_version is only a label. So:
 *
 *   - no argument (the normal case): the current row, relabelled to the build the SimC dump
 *     headers report. Not on an --only run — a partial import doesn't put the whole dataset on
 *     that build.
 *   - an argument equal to the current label: same as no argument, so commands that pass
 *     $patch->build_version back in (DiffArenaSpells, DiscoverCcSpells, …) behave identically.
 *   - an argument matching another existing row: that row, not relabelled (warns if not current).
 *   - an argument matching no row: the CURRENT row, relabelled to it. Not a fork.
 *   - an argument matching no row plus --new-patch, or no current row at all: a new row.
 *
 * Pure decision logic with no output of its own, so ImportSpellData prints the messages and the
 * rules can be tested without running a full import.
 */
class PatchResolver
{
    /**
     * @return array{
     *     patch: ?Patch,
     *     create: ?string,
     *     relabel: ?string,
     *     error: ?string,
     *     messages: list<array{0: 'info'|'comment'|'warn', 1: string}>
     * }
     *   patch   — existing row to import into (null when one must be created or on error)
     *   create  — build_version of a row the caller must create (the caller owns row creation so
     *             its own bookkeeping counts it)
     *   relabel — label to apply to `patch` AFTER the import succeeds
     */
    public function resolve(Game $game, ?string $argument, ?string $gameBuild, bool $partial, bool $newPatch): array
    {
        $result = ['patch' => null, 'create' => null, 'relabel' => null, 'error' => null, 'messages' => []];
        $argument = $argument !== null && trim($argument) !== '' ? trim($argument) : null;
        $current = Patch::where('game_id', $game->id)->where('is_current', true)->first();

        if ($newPatch && $argument === null) {
            return ['error' => '--new-patch needs an explicit build version to create.'] + $result;
        }

        if ($argument === null || ($current !== null && $argument === $current->build_version)) {
            if ($current === null) {
                $example = $gameBuild ?? '12.1.0.69814';

                return ['error' => "No current patch exists for '{$game->slug}' — pass a build version to create the first one, e.g. import:spelldata {$game->slug} {$example} --current"] + $result;
            }

            $result['patch'] = $current;
            $result['messages'][] = ['comment', "Importing into the current patch (row {$current->id}, labelled {$current->build_version})."];

            if ($gameBuild !== null && $gameBuild !== $current->build_version) {
                if ($partial) {
                    $result['messages'][] = ['comment', "  --only run: leaving the label alone (SimC headers say {$gameBuild}, but a partial import doesn't put the whole dataset on that build)."];
                } else {
                    $result['relabel'] = $gameBuild;
                }
            }

            return $result;
        }

        $existing = Patch::where('game_id', $game->id)->where('build_version', $argument)->first();
        if ($existing !== null) {
            if (! $existing->is_current) {
                $result['messages'][] = ['warn', "  Patch {$argument} exists but is NOT the current patch (row {$existing->id}). This import writes into it; the live site only shows it if you also pass --current."];
            }

            return ['patch' => $existing] + $result;
        }

        if ($current === null || $newPatch) {
            if ($current !== null) {
                $result['messages'][] = ['warn', "  --new-patch: creating a SEPARATE patch row for {$argument}. Talent builds, module references and guides on row {$current->id} will not follow it."];
            }

            return ['create' => $argument] + $result;
        }

        if ($gameBuild !== null && $gameBuild !== $argument) {
            $result['messages'][] = ['warn', "  The SimC dump headers say {$gameBuild}, but you passed {$argument}. Using {$argument} as the label, as asked."];
        }

        return ['patch' => $current, 'relabel' => $argument] + $result;
    }

    /**
     * Renames the patch row in place. Nothing is re-keyed: every foreign key points at the id,
     * which does not change. Refuses when another row already carries that label (patches is
     * unique on game_id + build_version) — that is a stray fork to inspect with wow:prune-patch,
     * not something to overwrite.
     *
     * @return array{applied: bool, message: ?string}
     */
    public function relabel(Patch $patch, ?string $label): array
    {
        if ($label === null || $label === $patch->build_version) {
            return ['applied' => false, 'message' => null];
        }

        $clash = Patch::where('game_id', $patch->game_id)
            ->where('build_version', $label)
            ->where('id', '!=', $patch->id)
            ->first();

        if ($clash !== null) {
            return ['applied' => false, 'message' => "  Not relabelling patch row {$patch->id} to {$label}: row {$clash->id} already carries that label. Inspect it with `wow:prune-patch {$clash->id}` (a dry run) before deciding."];
        }

        $old = $patch->build_version;
        $patch->update(['build_version' => $label]);

        return ['applied' => true, 'message' => "Patch relabelled: {$old} → {$label} (same row {$patch->id}; every relationship kept)."];
    }
}
