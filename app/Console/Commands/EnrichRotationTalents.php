<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Embeds a real resolved talent build (PvE talents + PvP talents) into each length bracket of a
 * committed rotation export (`data/arena-logs/rotations/{class}/{spec}.json`), as a complementary
 * field alongside the burst window it came from — 2026-08-27 direct request ("pull the talent
 * builds for the burst windows that we are serving to users... store them in the same file").
 *
 * Reads the `matchId`/`guid` fields offensive-rotations.php (wow-arena-archive) now carries
 * through into each window (previously computed internally but stripped before export — see
 * that script's own comment at the point this was added), pulls the real archived match's
 * COMBATANT_INFO line for that exact player via ArenaLogService::extractCombatantInfo(), and
 * resolves it to readable talent names via ArenaLogService::resolveCombatantTalents() — see
 * that method's own docblock for the two real Blizzard-side ID-space mismatches this had to
 * work around (talent-entry ids and PvP-talent ids each turned out to be a different identifier
 * space than what this project's own DB import captured) and how each is handled.
 *
 * Deliberately a separate, chonny-side pass rather than done inside offensive-rotations.php
 * itself — that script has no Eloquent/DB access (see its own docblock: it only ever reads raw
 * logs + metadata off disk), so talent resolution against `talent_nodes`/`spells` can only
 * happen on this side, after promotion. Idempotent and additive-only: re-running just
 * recomputes `talentBuild` on each window, never touches any other field.
 *
 * Usage:
 *   php artisan wow:enrich-rotation-talents                 # every promoted rotation file
 *   php artisan wow:enrich-rotation-talents rogue subtlety   # one spec only
 */
class EnrichRotationTalents extends Command
{
    protected $signature = 'wow:enrich-rotation-talents {classSlug?} {specSlug?}';

    protected $description = 'Embed a resolved real talent build into each committed rotation export\'s burst windows';

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
                if (!is_array($window) || empty($window['matchId']) || empty($window['guid'])) {
                    continue;
                }

                $raw = $service->extractCombatantInfo($window['matchId'], $window['guid']);
                if ($raw === null) {
                    $this->line("  {$class->name}/{$spec->name} [{$length}s]: match/guid not resolvable, left as-is.");
                    continue;
                }

                $resolved = $service->resolveCombatantTalents($raw, $spec->id);

                $data['topDpsWindowsByLength'][$length]['talentBuild'] = [
                    'matchId' => $window['matchId'],
                    'talents' => $resolved['talents'],
                    'pvpTalents' => $resolved['pvpTalents'],
                    'resolvedAt' => now()->toAtomString(),
                ];
                $touched = true;
            }

            // topDpsWindow is a plain JSON copy of topDpsWindowsByLength[12] taken at export
            // time (not a live reference once round-tripped through JSON) — keep them in sync
            // rather than only enriching one of the two.
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
