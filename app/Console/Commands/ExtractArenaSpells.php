<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;

/**
 * Extracts what each real player in an already-fetched arena match actually cast
 * (SPELL_CAST_SUCCESS events, via ArenaLogService::extractCastSpellsByPlayer()) and merges
 * it into one cumulative, deduped, plain-text file per (class, spec) under
 * data/arena-logs/spell-usage/{classSlug}/{specSlug}.txt (via
 * ArenaLogService::mergeSpellUsage(), shared with wow:discover-spec-spells) — growing every
 * time a new match touching that spec gets processed, same append-and-accumulate spirit as
 * spellbook_snapshots (each new export/match only ever adds coverage, never replaces prior
 * evidence).
 *
 * File format, one spell per line, sorted by spell_id — deliberately the same
 * `spell_id | name` shape (minus the class/spec columns, since the whole file is already
 * scoped to one) as the rest of data/spelldata's hand-curated override files:
 *   1833 | Cheap Shot
 *
 * A leading `# seen in matches: {id}, {id}, ...` comment line tracks provenance (which
 * matches actually contributed) so this stays auditable, not just a black-box accumulated
 * set — matches this project's standing preference for hand-inspectable text over opaque
 * derived data.
 *
 * Usage:
 *   php artisan wow:extract-arena-spells {matchId}
 *   php artisan wow:extract-arena-spells --all
 *
 * `--all` added 2026-08-27 (same shape as wow:record-kill-sequences --all) — loops every match
 * currently on file (both the archive's flat top-level and season-current/, matching
 * ArenaLogService::rawLogPath()/metadataPath()'s own season-current fallback) in a single PHP
 * process rather than shelling out per match. mergeSpellUsage() is idempotent/accumulating —
 * a match already reflected in a spec's spell-usage.txt is a safe no-op to re-process.
 *
 * See wow:diff-arena-spells for what this feeds into, and its docblock for the important
 * caveat about how partial a single match's (or even several matches') cast list is
 * compared to a full spellbook export.
 */
class ExtractArenaSpells extends Command
{
    protected $signature = 'wow:extract-arena-spells
        {matchId? : A match already pulled via wow:fetch-arena-log — required unless --all}
        {--all : Process every match currently on file}';

    protected $description = 'Extract per-class/spec cast-spell lists from an already-fetched match and merge into data/arena-logs/spell-usage/';

    public function handle(ArenaLogService $service): int
    {
        if ($this->option('all')) {
            return $this->handleAll($service);
        }

        $matchId = $this->argument('matchId');

        if (!$matchId) {
            $this->error('Pass {matchId}, or --all.');

            return self::FAILURE;
        }

        return $this->processOne($service, $matchId, verbose: true) ? self::SUCCESS : self::FAILURE;
    }

    private function handleAll(ArenaLogService $service): int
    {
        $matchIds = [];

        foreach ([config('arena_logs.archive_path').'/metadata', config('arena_logs.archive_path').'/season-current/metadata'] as $metaDir) {
            foreach (glob("{$metaDir}/*.json") as $metaFile) {
                $matchIds[] = basename($metaFile, '.json');
            }
        }

        $matchIds = array_unique($matchIds);

        $this->info('Processing '.count($matchIds).' match(es)...');

        $processed = 0;

        foreach ($matchIds as $matchId) {
            if ($this->processOne($service, $matchId, verbose: false)) {
                $processed++;
            }
        }

        $this->newLine();
        $this->info("Done: {$processed}/".count($matchIds).' match(es) contributed at least one player.');

        return self::SUCCESS;
    }

    private function processOne(ArenaLogService $service, string $matchId, bool $verbose): bool
    {
        try {
            $players = $service->extractCastSpellsByPlayer($matchId);
        } catch (\RuntimeException $e) {
            if ($verbose) {
                $this->error($e->getMessage());
            }

            return false;
        }

        if ($players === []) {
            if ($verbose) {
                $this->warn('No real players found in this match\'s metadata.');
            }

            return false;
        }

        $any = false;

        foreach ($players as $player) {
            if ($player['specId'] === null) {
                if ($verbose) {
                    $this->warn("Skipping {$player['name']}: spec external_id={$player['specExternalId']} not found in our specializations table (patch mismatch?).");
                }

                continue;
            }

            $class = GameClass::find($player['classId']);
            $spec = Specialization::find($player['specId']);

            $service->mergeSpellUsage($class->slug, $spec->slug, $matchId, $player['spells']);
            $any = true;

            if ($verbose) {
                $this->info("{$player['name']} ({$class->name} / {$spec->name}): ".count($player['spells'])." distinct spells cast -> data/arena-logs/spell-usage/{$class->slug}/{$spec->slug}.txt");
            }
        }

        return $any;
    }
}
