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
 * Aggregates ArenaLogService::findPreKillWindow() across every stored match where a real
 * player of the target spec was on the WINNING side — turns the one-match "here's a real
 * closing sequence" example (wow:kill-sequence) into a frequency-ranked list: which abilities
 * actually show up in the moments before a kill most often, across many independent games.
 *
 * Built 2026-08-14 per direct user instruction — pairs with wow:key-offensive-abilities'
 * "Offensive cooldowns" reference list: that answers "what CDs does this spec have," this
 * answers "which ones actually get used to close out kills in practice, and how often." Both
 * together are meant to teach a new player what to watch for without needing either a fragile
 * spell-effect classifier or a hand-designed combo-detection algorithm — just real frequency
 * counts over real match endings.
 *
 * Ranked primarily by DISTINCT-match frequency (what % of pre-kill windows this spec appeared
 * in at all), not raw cast count — a spammy filler ability appearing 5x in one match shouldn't
 * outrank something that reliably shows up once in every match. Total cast count is shown as a
 * secondary stat.
 *
 * Command-line only, no database writes, no UI.
 *
 * Usage: php artisan wow:common-prekill-spells rogue subtlety --window=20
 */
class CommonPreKillSpells extends Command
{
    protected $signature = 'wow:common-prekill-spells {classSlug} {specSlug} {--window=20 : Seconds before the kill to look back}';

    protected $description = "Rank a spec's most common abilities in the seconds before a kill, across every stored match";

    public function handle(ArenaLogService $service): int
    {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');
        $window = (int) $this->option('window');

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

        $patch = Patch::where('is_current', true)->first();

        $matchIds = [];
        foreach (glob(base_path('data/arena-logs/metadata/*.json')) as $metaFile) {
            $meta = json_decode(File::get($metaFile), true);
            $matchId = basename($metaFile, '.json');

            foreach ($meta['units'] ?? [] as $u) {
                if (str_starts_with($u['id'], 'Player-') && (int) $u['spec'] === $spec->external_spec_id) {
                    $matchIds[] = $matchId;
                    break;
                }
            }
        }

        if ($matchIds === []) {
            $this->warn("No matches on file contain a real {$class->name}/{$spec->name} player.");

            return self::SUCCESS;
        }

        $this->info("Scanning ".count($matchIds)." match(es)...");

        $windowsFound = 0;
        $matchCountBySpell = []; // spellId => distinct windows it appeared in
        $totalCastsBySpell = [];
        $nameBySpell = []; // spellId => our DB's canonical name, when known (checked once per id)
        $rawNameBySpell = []; // spellId => best raw-log fallback name seen so far, ASCII preferred

        foreach ($matchIds as $matchId) {
            $result = $service->findPreKillWindow($matchId, $window);
            if ($result === null) {
                continue;
            }

            foreach ($result['players'] as $player) {
                if ($player['spec'] !== $spec->external_spec_id) {
                    continue;
                }

                $windowsFound++;
                $seenThisWindow = [];

                foreach ($player['casts'] as $cast) {
                    $id = $cast['spellId'];
                    $totalCastsBySpell[$id] = ($totalCastsBySpell[$id] ?? 0) + 1;

                    if (!array_key_exists($id, $nameBySpell)) {
                        $spellRow = $patch ? Spell::where('patch_id', $patch->id)->where('spell_id', $id)->first() : null;
                        $nameBySpell[$id] = $spellRow?->name; // null if not in our DB yet
                    }

                    // Only relevant when the spell isn't in our DB at all: different matches can
                    // be recorded by different-locale clients, so the raw log's own fallback name
                    // for the same spell_id can vary. Prefer whichever variant is ASCII across
                    // every match seen so far, not whichever match happened to be processed first
                    // — same guard as WowComps::killSequenceDataFor(), confirmed necessary
                    // 2026-08-14 (Snowdrift showing as Chinese characters in the ranked list).
                    if ($nameBySpell[$id] === null) {
                        $existing = $rawNameBySpell[$id] ?? null;
                        if ($existing === null || preg_match('/[^\x00-\x7F]/', $existing)) {
                            $rawNameBySpell[$id] = $cast['name'] ?? "spell_id {$id}";
                        }
                    }

                    if (!isset($seenThisWindow[$id])) {
                        $seenThisWindow[$id] = true;
                        $matchCountBySpell[$id] = ($matchCountBySpell[$id] ?? 0) + 1;
                    }
                }
            }
        }

        if ($windowsFound === 0) {
            $this->warn("No {$class->name}/{$spec->name} player was found on the winning side of any pre-kill window.");

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($matchCountBySpell as $spellId => $matchCount) {
            $rows[] = [
                'name' => $nameBySpell[$spellId] ?? $rawNameBySpell[$spellId] ?? "spell_id {$spellId}",
                'matchCount' => $matchCount,
                'totalCasts' => $totalCastsBySpell[$spellId] ?? 0,
            ];
        }

        usort($rows, fn ($a, $b) => $b['matchCount'] <=> $a['matchCount'] ?: $b['totalCasts'] <=> $a['totalCasts']);

        $this->newLine();
        $this->info("=== Most common abilities in the {$window}s before a kill ({$windowsFound} real pre-kill window(s)) ===");
        foreach (array_slice($rows, 0, 15) as $r) {
            $pct = round($r['matchCount'] / $windowsFound * 100);
            $this->line(sprintf('  %-28s %3d%% of windows (%d/%d)  %4d total casts', $r['name'], $pct, $r['matchCount'], $windowsFound, $r['totalCasts']));
        }

        return self::SUCCESS;
    }
}
