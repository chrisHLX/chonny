<?php

namespace App\Console\Commands;

use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Spell;
use App\Models\Specialization;
use App\Models\TalentNodeEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Reproduces every admin-default TalentBuild from the committed
 * data/spelldata/default-talent-builds.txt — the counterpart to wow:export-default-talents.
 * Zero Blizzard API calls, zero murlok.io calls: this is the "one command on a fresh machine"
 * fix for admin-default talent picks, the same role wow:apply-icon-manifest already plays for
 * spell/class/spec icons.
 *
 * Deliberately NOT wired into import:spelldata automatically — this command REPLACES a build's
 * entire pick set (matching syncPvpChoices()'s existing "replace not append" precedent), and an
 * admin actively re-curating a live build via /admin/talent-builds should never have that work
 * silently reverted by the next routine spell-data re-import. Run this explicitly, the same way
 * wow:apply-icon-manifest is its own explicit step in the deploy runbook.
 *
 * Matched by spell_id + rank against the CURRENT patch's own talent_node_entries — never by the
 * raw talent_node_id/chosen_entry_id that were current when the file was exported, since those
 * are internal auto-increment PKs with no guaranteed stability across a re-import (confirmed
 * directly this session: two environments' node IDs for the identical talent tree differed).
 * A line whose spell/entry can't be resolved for the current patch is skipped and warned about,
 * never guessed.
 */
class ApplyDefaultTalents extends Command
{
    protected $signature = 'wow:apply-default-talents {--path= : Override the input path (used by tests)}';

    protected $description = 'Reproduces admin-default TalentBuilds from the committed default-talent-builds.txt — no Blizzard/murlok.io calls.';

    public function handle(TalentSelectionService $talentService): int
    {
        $path = $this->option('path') ?: base_path('data/spelldata/default-talent-builds.txt');

        if (!File::exists($path)) {
            $this->error("File not found at {$path}. Run wow:export-default-talents on an environment with the builds you want first, then commit the generated file.");

            return self::FAILURE;
        }

        // class_slug:spec_slug => ['pve' => [[spell_id, rank], ...], 'pvp' => [spell_id, ...]]
        $bySpec = [];

        foreach (File::lines($path) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            if (count($parts) < 4 || !in_array($parts[2], ['pve', 'pvp'], true) || !ctype_digit($parts[3])) {
                $this->warn("  Skipping malformed default-talent-builds.txt line: {$line}");

                continue;
            }

            [$classSlug, $specSlug, $kind, $spellId] = $parts;
            $rank = $parts[4] ?? '';

            $key = "{$classSlug}:{$specSlug}";
            $bySpec[$key] ??= ['class' => $classSlug, 'spec' => $specSlug, 'pve' => [], 'pvp' => []];

            if ($kind === 'pve') {
                $bySpec[$key]['pve'][] = [(int) $spellId, $rank === '' ? null : (int) $rank];
            } else {
                $bySpec[$key]['pvp'][] = (int) $spellId;
            }
        }

        $buildsWritten = 0;
        $choicesApplied = 0;
        $choicesSkipped = 0;

        foreach ($bySpec as ['class' => $classSlug, 'spec' => $specSlug, 'pve' => $pveEntries, 'pvp' => $pvpSpellIds]) {
            $class = GameClass::where('slug', $classSlug)->first();
            $spec = $class ? Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first() : null;

            if (!$class || !$spec) {
                $this->warn("  Skipping {$classSlug}/{$specSlug}: class or spec not found.");

                continue;
            }

            $patchId = Patch::where('game_id', $class->game_id)->where('is_current', true)->value('id');

            if (!$patchId) {
                $this->warn("  Skipping {$classSlug}/{$specSlug}: no current patch found.");

                continue;
            }

            $build = $talentService->getOrCreateDefaultBuild($spec->id, $patchId);
            $build->choices()->delete();
            $build->pvpChoices()->delete();

            foreach ($pveEntries as [$spellId, $rank]) {
                $spell = Spell::where('patch_id', $patchId)->where('spell_id', $spellId)->first();

                if (!$spell) {
                    $this->warn("  Skipping {$classSlug}/{$specSlug} pve spell_id={$spellId}: not found for the current patch.");
                    $choicesSkipped++;

                    continue;
                }

                $entryQuery = TalentNodeEntry::where('spell_id', $spell->id);
                $entryQuery = $rank === null ? $entryQuery : $entryQuery->where('rank', $rank);
                $entry = $entryQuery->orderBy('id')->first();

                if (!$entry) {
                    $this->warn("  Skipping {$classSlug}/{$specSlug} pve spell_id={$spellId} rank={$rank}: no matching talent_node_entry for the current patch.");
                    $choicesSkipped++;

                    continue;
                }

                $talentService->saveChoice($build, $entry->talentNode, $entry);
                $choicesApplied++;
            }

            $resolvedPvpIds = [];

            foreach ($pvpSpellIds as $spellId) {
                $spell = Spell::where('patch_id', $patchId)->where('spell_id', $spellId)->first();
                $pvpTalent = $spell ? PvpTalent::where('spec_id', $spec->id)->where('patch_id', $patchId)->where('spell_id', $spell->id)->first() : null;

                if (!$pvpTalent) {
                    $this->warn("  Skipping {$classSlug}/{$specSlug} pvp spell_id={$spellId}: no matching pvp_talent for the current patch.");
                    $choicesSkipped++;

                    continue;
                }

                $resolvedPvpIds[] = $pvpTalent->id;
                $choicesApplied++;
            }

            if (!empty($resolvedPvpIds)) {
                $talentService->syncPvpChoices($build, $resolvedPvpIds);
            }

            $buildsWritten++;
        }

        $talentService->bumpSpellCacheVersion();

        $this->info("Applied {$buildsWritten} default build(s), {$choicesApplied} picks applied, {$choicesSkipped} skipped (see warnings above).");
        $this->info('No Blizzard API or murlok.io calls were made.');

        return self::SUCCESS;
    }
}
