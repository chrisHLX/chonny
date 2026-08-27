<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Aggregates ArenaLogService::findMechanicsInWindow() across every stored match where a real
 * player of the target spec was on the winning side — same match-finding and distinct-match
 * frequency-ranking methodology as wow:common-prekill-spells (that command answers "which
 * abilities get pressed to close out a kill"; this one answers "which buffs/debuffs are
 * actually up when it happens" — the non-obvious mechanics a viewer wouldn't spot from the
 * cast sequence alone, e.g. Colossus Smash amplifying damage or Ancient Arts refunding combo
 * points). Ported from the same three-signal methodology already verified in wow-arena-
 * archive's compare-player-windows.php (see ArenaLogService::findMechanicsInWindow()'s
 * docblock for the full chain).
 *
 * Unlike wow:common-prekill-spells (command-line only, no persistence), this WRITES its ranked
 * result — {archive_path}/mechanics/{classSlug}/{specSlug}.txt, same staging/promotion contract
 * as spell-usage/ and kill-sequences/ (see config/arena_logs.php's docblock): lands in the
 * configured archive first, gets manually reviewed and copied into this project's own
 * data/arena-logs/mechanics/ once trusted — never auto-promoted, since these are empirically
 * discovered candidates for a human to confirm, not verified facts.
 *
 * `--all` loops every specialization in the database, one at a time (same per-spec loop
 * structure as wow:discover-all-specs) — a spec with zero pre-kill windows on file is skipped,
 * not an error, so this is safe to run right after a fresh batch of matches even if some specs
 * have little/no coverage yet.
 *
 * Usage:
 *   php artisan wow:record-important-mechanics rogue subtlety --window=20
 *   php artisan wow:record-important-mechanics --all
 */
class RecordImportantMechanics extends Command
{
    protected $signature = 'wow:record-important-mechanics
        {classSlug? : Required unless --all}
        {specSlug? : Required unless --all}
        {--all : Run for every spec in the database}
        {--window=20 : Seconds before the kill to look back}';

    protected $description = 'Rank a spec\'s most common self-buffs/target-debuffs in the seconds before a kill, across every stored match, and write mechanics.txt';

    public function handle(ArenaLogService $service): int
    {
        $window = (int) $this->option('window');

        if ($this->option('all')) {
            $specs = Specialization::with('gameClass')->get()
                ->filter(fn ($s) => $s->gameClass !== null)
                ->sortBy([['gameClass.name', 'asc'], ['name', 'asc']])
                ->values();

            $this->info("Running for {$specs->count()} spec(s) (window={$window}s)...");
            $this->newLine();

            foreach ($specs as $spec) {
                $this->info("=== {$spec->gameClass->name}/{$spec->name} ===");
                $this->recordForSpec($service, $spec->gameClass, $spec, $window);
                $this->newLine();
            }

            return self::SUCCESS;
        }

        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');

        if (!$classSlug || !$specSlug) {
            $this->error('Pass {classSlug} {specSlug}, or --all.');

            return self::FAILURE;
        }

        $class = GameClass::where('slug', $classSlug)->first();

        if (!$class) {
            $this->error("No class found for slug '{$classSlug}'.");

            return self::FAILURE;
        }

        $spec = Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first();

        if (!$spec) {
            $this->error("No spec found for slug '{$specSlug}' under {$class->name}.");

            return self::FAILURE;
        }

        return $this->recordForSpec($service, $class, $spec, $window) ? self::SUCCESS : self::SUCCESS;
    }

    private function recordForSpec(ArenaLogService $service, GameClass $class, Specialization $spec, int $window): bool
    {
        $patch = Patch::where('is_current', true)->first();

        // Checks BOTH the archive's top-level metadata/ AND its season-current/metadata/ —
        // unlike every other existing wow:* command in this codebase (wow:common-prekill-
        // spells, wow:discover-all-specs, etc.), which only ever glob the flat top-level path
        // and are therefore blind to matches sitting in season-current/. Confirmed 2026-08-27:
        // nothing else in this codebase references "season-current" at all. Scoped narrowly to
        // this command rather than fixed everywhere, since that's a bigger call than this task
        // warrants unilaterally — flagged, not silently fixed elsewhere.
        $matchIds = [];

        foreach ([config('arena_logs.archive_path').'/metadata', config('arena_logs.archive_path').'/season-current/metadata'] as $metaDir) {
            foreach (glob("{$metaDir}/*.json") as $metaFile) {
                $meta = json_decode(File::get($metaFile), true);
                $matchId = basename($metaFile, '.json');

                foreach ($meta['units'] ?? [] as $u) {
                    if (str_starts_with($u['id'], 'Player-') && (int) $u['spec'] === $spec->external_spec_id) {
                        $matchIds[] = $matchId;

                        break;
                    }
                }
            }
        }

        $matchIds = array_unique($matchIds);

        if ($matchIds === []) {
            $this->warn('  No matches on file contain a real player of this spec.');

            return false;
        }

        $this->line('  Scanning '.count($matchIds).' match(es)...');

        $windowsFound = 0;
        $matchCountBySpell = []; // "buff:spellId" or "debuff:spellId" => distinct windows it appeared in
        $totalOccurBySpell = [];
        $nameBySpell = [];
        $rawNameBySpell = [];

        foreach ($matchIds as $matchId) {
            $result = $service->findMechanicsInWindow($matchId, $window);

            if ($result === null) {
                continue;
            }

            foreach ($result['players'] as $player) {
                if ($player['spec'] !== $spec->external_spec_id) {
                    continue;
                }

                if ($player['selfBuffs'] === [] && $player['targetDebuffs'] === []) {
                    continue;
                }

                $windowsFound++;
                $seenThisWindow = [];

                foreach ([['self-buff', $player['selfBuffs']], ['target-debuff', $player['targetDebuffs']]] as [$type, $events]) {
                    foreach ($events as $event) {
                        $id = $event['spellId'];
                        $key = "{$type}:{$id}";
                        $totalOccurBySpell[$key] = ($totalOccurBySpell[$key] ?? 0) + 1;

                        if (!array_key_exists($id, $nameBySpell)) {
                            $spellRow = $patch ? Spell::where('patch_id', $patch->id)->where('spell_id', $id)->first() : null;
                            $nameBySpell[$id] = $spellRow?->name;
                        }

                        // Different matches can be recorded by different-locale clients — prefer
                        // whichever raw-log fallback name variant is ASCII across every match
                        // seen so far, same guard wow:common-prekill-spells already uses.
                        if ($nameBySpell[$id] === null) {
                            $existing = $rawNameBySpell[$id] ?? null;
                            if ($existing === null || preg_match('/[^\x00-\x7F]/', $existing)) {
                                $rawNameBySpell[$id] = $event['name'] ?? "spell_id {$id}";
                            }
                        }

                        if (!isset($seenThisWindow[$key])) {
                            $seenThisWindow[$key] = true;
                            $matchCountBySpell[$key] = ($matchCountBySpell[$key] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        if ($windowsFound === 0) {
            $this->warn('  No pre-kill window with a self-buff/target-debuff found for this spec.');

            return false;
        }

        $rows = [];

        foreach ($matchCountBySpell as $key => $matchCount) {
            [$type, $spellIdStr] = explode(':', $key, 2);
            $spellId = (int) $spellIdStr;

            $rows[] = [
                'type' => $type,
                'spellId' => $spellId,
                'name' => $nameBySpell[$spellId] ?? $rawNameBySpell[$spellId] ?? "spell_id {$spellId}",
                'matchCount' => $matchCount,
                'totalOccur' => $totalOccurBySpell[$key] ?? 0,
            ];
        }

        usort($rows, fn ($a, $b) => $b['matchCount'] <=> $a['matchCount'] ?: $b['totalOccur'] <=> $a['totalOccur']);

        $this->writeMechanicsFile($class->slug, $spec->slug, $rows, $windowsFound, count($matchIds), $window);

        $this->info("  {$windowsFound} pre-kill window(s), ".count($rows)." distinct mechanic(s) -> {archive}/mechanics/{$class->slug}/{$spec->slug}.txt");

        return true;
    }

    /**
     * @param  array<int, array{type: string, spellId: int, name: string, matchCount: int, totalOccur: int}>  $rows
     */
    private function writeMechanicsFile(string $classSlug, string $specSlug, array $rows, int $windowsFound, int $matchesScanned, int $window): void
    {
        $dir = config('arena_logs.archive_path')."/mechanics/{$classSlug}";
        File::ensureDirectoryExists($dir);
        $path = "{$dir}/{$specSlug}.txt";

        $lines = [
            '# generated '.now()->toDateString()." — {$windowsFound} pre-kill window(s) (--window={$window}s) across {$matchesScanned} scanned match(es)",
            '# type is self-buff (player applied it to themselves) or target-debuff (player applied it directly to the kill target — e.g. Colossus Smash-style damage amplifiers)',
            '# ranked by % of windows the mechanic appeared in, not raw occurrence count — a spammy filler effect firing 5x in one window should not outrank something that reliably shows up once per window',
            '# format: spell_id | type | pct | matchCount/totalWindows | name',
            '',
        ];

        foreach ($rows as $row) {
            $pct = round($row['matchCount'] / $windowsFound * 100);
            $lines[] = "{$row['spellId']} | {$row['type']} | {$pct}% | {$row['matchCount']}/{$windowsFound} | {$row['name']}";
        }

        File::put($path, implode("\n", $lines)."\n");
    }
}
