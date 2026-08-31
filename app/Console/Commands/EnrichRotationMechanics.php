<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Embeds ArenaLogService::enrichBurstWindow()'s real, single-window facts (champion buffs/
 * debuffs, target buffs/debuffs, target identity) into each length bracket of a committed
 * rotation export (`data/arena-logs/rotations/{class}/{spec}.json`) — same shape and same
 * precedent as wow:enrich-rotation-talents, built the same day this replaced an earlier,
 * rejected aggregate-across-many-windows approach (see enrichBurstWindow()'s own docblock).
 *
 * Reads the `matchId`/`guid`/`start`/`targetGuid` fields offensive-rotations.php now carries
 * through into each window (previously computed internally but stripped before export, same
 * story as matchId/guid before wow:enrich-rotation-talents needed them).
 *
 * Idempotent and additive-only: re-running just recomputes `mechanics` on each window, never
 * touches any other field (including `talentBuild`, written by the sibling command).
 *
 * Usage:
 *   php artisan wow:enrich-rotation-mechanics                 # every promoted rotation file
 *   php artisan wow:enrich-rotation-mechanics rogue subtlety   # one spec only
 */
class EnrichRotationMechanics extends Command
{
    protected $signature = 'wow:enrich-rotation-mechanics {classSlug?} {specSlug?}';

    protected $description = 'Embed real champion/target buff+debuff facts and target identity into each committed rotation export\'s burst windows';

    public function handle(ArenaLogService $service): int
    {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');

        $query = Specialization::with('gameClass')->orderBy('name');

        if ($classSlug) {
            $query->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug));
        }
        if ($specSlug) {
            $query->where('slug', $specSlug);
        }

        $specs = $query->get();
        $enriched = 0;
        $skipped = 0;

        foreach ($specs as $spec) {
            $class = $spec->gameClass;
            $path = base_path("data/arena-logs/rotations/{$class->slug}/{$spec->slug}.json");

            if (!File::exists($path)) {
                continue;
            }

            $data = json_decode(File::get($path), true);
            if (!is_array($data) || !isset($data['topDpsWindowsByLength'])) {
                $this->warn("  {$class->name}/{$spec->name}: not on disk in the expected shape, skipped.");
                $skipped++;
                continue;
            }

            $touched = false;

            foreach ($data['topDpsWindowsByLength'] as $length => $window) {
                if (!is_array($window) || empty($window['matchId']) || empty($window['guid']) || !isset($window['start'])) {
                    continue;
                }

                $mechanics = $service->enrichBurstWindow(
                    $window['matchId'],
                    $window['guid'],
                    $window['targetGuid'] ?? null,
                    (float) $window['start']
                );

                if ($mechanics === null) {
                    $this->line("  {$class->name}/{$spec->name} [{$length}s]: match not resolvable, left as-is.");
                    continue;
                }

                // Real target class/spec resolved from Blizzard's numeric spec id, same pattern
                // as every other spec-external-id resolution in this codebase — done here
                // (Eloquent, DB-aware) rather than in the service method, kept a pure single
                // responsibility per method.
                $targetSpec = $mechanics['targetSpecExternalId'] !== null
                    ? Specialization::with('gameClass')->where('external_spec_id', $mechanics['targetSpecExternalId'])->first()
                    : null;

                $mechanics['targetClassName'] = $targetSpec?->gameClass?->name;
                $mechanics['targetSpecName'] = $targetSpec?->name;
                unset($mechanics['targetSpecExternalId']);

                $data['topDpsWindowsByLength'][$length]['mechanics'] = $mechanics + ['resolvedAt' => now()->toAtomString()];
                $touched = true;
            }

            if (isset($data['topDpsWindowsByLength'][12])) {
                $data['topDpsWindow'] = $data['topDpsWindowsByLength'][12];
            }

            if ($touched) {
                File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $this->info("  {$class->name}/{$spec->name}: enriched.");
                $enriched++;
            } else {
                $skipped++;
            }
        }

        $this->info("Done. Enriched {$enriched}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
