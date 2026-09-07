<?php

namespace App\Console\Commands;

use App\Http\Services\CcTargetingAnalyzer;
use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Measures who each crowd-control ability is really used on — the enemy healer or a damage dealer
 * — by scanning every archived match, and writes the result to data/arena-logs/cc-targeting.json.
 *
 * See App\Http\Services\CcTargetingAnalyzer for the derivation. Read by BurstGuideBuilder, which
 * uses it in preference to the hand-curated `spells.chain_target` column.
 *
 * Reads raw logs from the arena archive (ARENA_LOG_ARCHIVE_PATH) and so is a build-time command,
 * like wow:build-burst-guides; its small committed output is what production serves. Run as part
 * of wow:refresh-match-derived, and safe to re-run at any time — it always fully overwrites.
 *
 * Prints any disagreement with the curated `chain_target` column rather than silently overriding
 * it. A disagreement is worth a look in both directions: it can mean the curated value is stale,
 * or that the measurement is picking up something real but different from intent (an AoE control
 * lands on whoever is in front of you, which is not the same question as who you would choose).
 */
class AnalyzeCcTargeting extends Command
{
    protected $signature = 'wow:analyze-cc-targeting {--show-all : List every ability, not just those disagreeing with the curated chain_target}';

    protected $description = 'Measure from real archived matches whether each CC ability is used on the enemy healer or their damage dealers.';

    public function handle(CcTargetingAnalyzer $analyzer): int
    {
        $this->info('Scanning archived matches for crowd-control targeting…');

        $bar = null;
        $result = $analyzer->analyze(function (int $done, int $total) use (&$bar) {
            if ($bar === null) {
                $bar = $this->output->createProgressBar($total);
            }
            $bar->setProgress($done);
        });
        $bar?->finish();
        $this->line('');

        if ($result['matches'] === 0) {
            $this->error('No matches scanned — is ARENA_LOG_ARCHIVE_PATH set to the wow-arena-archive checkout?');

            return self::FAILURE;
        }

        $usable = array_values(array_filter($result['spells'], fn (array $row) => $row['usable']));

        File::put(
            $analyzer->outputPath(),
            json_encode([
                'generatedAt' => now()->toIso8601String(),
                'matches' => $result['matches'],
                'landings' => $result['landings'],
                'minSample' => CcTargetingAnalyzer::MIN_SAMPLE,
                'spells' => $usable,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );

        $this->info(sprintf(
            'Scanned %d matches, %s control applications — %d abilities have a usable sample (of %d observed).',
            $result['matches'],
            number_format($result['landings']),
            count($usable),
            count($result['spells'])
        ));

        $this->reportAgainstCuration($usable);

        return self::SUCCESS;
    }

    /**
     * Surfaces where the measurement and the hand-curated column disagree. Deliberately a report,
     * not an error — neither source is automatically right, and the point is that a human can see
     * it rather than the override happening silently.
     */
    private function reportAgainstCuration(array $rows): void
    {
        $patchId = Patch::where('is_current', true)->value('id');
        $curated = Spell::where('patch_id', $patchId)->whereNotNull('chain_target')->get()
            ->mapWithKeys(fn (Spell $spell) => [$spell->display_name => $spell->chain_target]);

        $showAll = (bool) $this->option('show-all');
        $table = [];

        foreach ($rows as $row) {
            $measured = $this->bucket($row['ratio'], $row['drCategory']);
            $curatedValue = $curated[$row['name']] ?? null;
            $disagrees = $curatedValue !== null && $curatedValue !== $measured;

            if (! $showAll && ! $disagrees) {
                continue;
            }

            $table[] = [
                $row['name'],
                $row['drCategory'] ?? '-',
                $row['observations'],
                round($row['healerShare'] * 100).'%',
                $row['ratio'],
                $measured,
                $curatedValue ?? '—',
                $disagrees ? 'differs' : '',
            ];
        }

        if ($table === []) {
            $this->info('  No disagreement with any curated chain_target.');

            return;
        }

        $this->line('');
        $this->table(['ability', 'dr', 'n', 'healer', 'vs random', 'measured', 'curated', ''], $table);
        $this->line('  "vs random" is the healer share divided by what an evenly-spread ability would hit —');
        $this->line('  1.00 means no preference, 2.00 means twice as often as chance.');
    }

    /** Mirrors BurstGuideBuilder::bucketObservedTarget() so the report shows what the page will show. */
    private function bucket(float $ratio, ?string $drCategory): string
    {
        return app(\App\Http\Services\BurstGuideBuilder::class)->bucketObservedTarget($ratio, $drCategory);
    }
}
