<?php

namespace App\Console\Commands;

use App\Http\Services\TalentSelectionService;
use App\Models\TalentBuild;
use App\Models\TalentBuildChoice;
use Illuminate\Console\Command;

/**
 * Manual/on-demand wrapper around TalentSelectionService::cleanupSamePositionCollisions() —
 * see that method's docblock. As of 2026-08-10 this same cleanup also runs automatically at
 * the end of every `import:spelldata` run, so this command is no longer required for routine
 * use; it exists for the --dry-run preview and for running the cleanup on its own without a
 * full spell-data re-import.
 */
class FixTalentCollisions extends Command
{
    protected $signature = 'wow:fix-talent-collisions {--dry-run : List what would change without deleting anything}';

    protected $description = 'Removes same-position ACTIVE-node talent collisions from every existing TalentBuild (see CLAUDE.md). Also runs automatically as part of import:spelldata.';

    public function handle(TalentSelectionService $talentService): int
    {
        if (!$this->option('dry-run')) {
            $report = $talentService->cleanupSamePositionCollisions();

            foreach ($report as $row) {
                $label = $row['is_default'] ? "default build #{$row['build_id']}" : "build #{$row['build_id']}";
                $this->line("  [{$label}] dropping '{$row['dropped_spell']}' (node {$row['dropped_node_id']}), keeping '{$row['kept_spell']}' (node {$row['kept_node_id']})");
            }

            $this->newLine();
            $this->info('Deleted '.count($report).' collision row(s).');

            return self::SUCCESS;
        }

        // --dry-run: mirrors the service method's own grouping/tiebreak logic read-only, so
        // nothing gets deleted just to produce a preview.
        $totalWouldDelete = 0;

        foreach (TalentBuild::all() as $build) {
            $choices = TalentBuildChoice::where('talent_build_id', $build->id)
                ->with(['talentNode', 'chosenEntry.spell'])
                ->get();

            $byPosition = $choices
                ->filter(fn ($c) => $c->talentNode->type === 'ACTIVE')
                ->groupBy(fn ($c) => $c->talentNode->talent_tree_id.':'.$c->talentNode->pos_x.':'.$c->talentNode->pos_y);

            foreach ($byPosition as $group) {
                if ($group->count() <= 1) {
                    continue;
                }

                $sorted = $group->sortByDesc(fn ($c) => [$c->updated_at, $c->id])->values();
                $keep = $sorted->first();
                $label = $build->is_default ? "default build #{$build->id}" : "build #{$build->id}";

                foreach ($sorted->slice(1) as $drop) {
                    $this->line("  [{$label}] would drop '{$drop->chosenEntry->spell->name}' (node {$drop->talent_node_id}), keeping '{$keep->chosenEntry->spell->name}' (node {$keep->talent_node_id})");
                    $totalWouldDelete++;
                }
            }
        }

        $this->newLine();
        $this->info("Would delete {$totalWouldDelete} collision row(s).");

        return self::SUCCESS;
    }
}
