<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use Illuminate\Console\Command;

/**
 * Targeted match-pulling for CC-duration discovery, built 2026-08-14 on direct user
 * instruction: finds every spells row with dr_category set but pvp_duration_seconds still
 * null, resolves each to its explicit-spec_id spell_class_availability rows to build a list
 * of specs worth targeting, then pulls the top --top highest-rated matches (respecting
 * ArenaLogService::MIN_MATCH_DURATION_SECONDS — the "not just high MMR, also decent
 * duration" filter added the same session after the 18-second-match incident) for each spec
 * via ArenaLogService::pullTopMatchesForSpec().
 *
 * Every NEWLY fetched match (already-on-disk ones are skipped, no re-fetch, no re-extract)
 * has ALL its real players extracted and merged into spell-usage files, not just the one
 * targeted spec — a 3v3 match has 6 real players, so pulling for e.g. Warrior/Fury's missing
 * spells also captures whatever the other 5 players in that match cast, for free.
 *
 * Spells with no explicit spec_id at all (the ambiguous ~39-spell tail — mostly pet/hidden
 * duplicate-copy noise, per CLAUDE.md's extensive documentation of this exact ambiguity) are
 * listed but not directly targetable — they may still get incidental coverage from whichever
 * matches get pulled for other reasons.
 *
 * This does NOT run the duration scan or apply anything — it only pulls+extracts. Follow with
 * a fresh batch duration scan (same methodology as the 2026-08-14 "CC duration batch" work)
 * to actually compute and apply durations from the expanded log set.
 *
 * Usage: php artisan wow:pull-missing-cc-matches --top=3 --pages=3
 */
class PullMissingCcMatches extends Command
{
    protected $signature = 'wow:pull-missing-cc-matches
        {--bracket=3v3}
        {--top=3 : How many highest-rated matches to pull per targeted spec}
        {--pages=3 : Search depth per spec (offset-paged, 50 per page)}';

    protected $description = 'Pull top-rated matches for every spec that can cast a CC spell still missing pvp_duration_seconds, extracting all real players from each new match';

    public function handle(ArenaLogService $service): int
    {
        $patch = Patch::where('is_current', true)->first();

        $missing = Spell::where('patch_id', $patch->id)
            ->whereNotNull('dr_category')
            ->whereNull('pvp_duration_seconds')
            ->get(['id', 'spell_id', 'name']);

        $this->info("{$missing->count()} CC spells still missing pvp_duration_seconds.");

        $specTargets = []; // externalSpecId => ['classSlug','specSlug','name','spells'=>[]]

        foreach ($missing as $spell) {
            $rows = SpellClassAvailability::where('spell_id', $spell->id)->whereNotNull('spec_id')->get();

            foreach ($rows as $row) {
                $spec = Specialization::find($row->spec_id);
                if (!$spec) {
                    continue;
                }
                $class = GameClass::find($spec->class_id);
                $key = $spec->external_spec_id;
                $specTargets[$key]['classSlug'] = $class->slug;
                $specTargets[$key]['specSlug'] = $spec->slug;
                $specTargets[$key]['name'] = "{$class->name}/{$spec->name}";
                $specTargets[$key]['spells'][$spell->spell_id] = true;
            }
        }

        $this->info(count($specTargets).' spec(s) targeted (have at least one explicitly-tagged missing spell).');
        $this->newLine();

        $bracket = $this->option('bracket');
        $topN = (int) $this->option('top');
        $pages = (int) $this->option('pages');

        $newMatchIds = [];
        $summary = ['stored' => 0, 'already_on_disk' => 0, 'fetch_failed' => 0];

        foreach ($specTargets as $externalSpecId => $data) {
            $count = count($data['spells']);
            $this->info("=== {$data['name']} ({$count} missing spell(s)) ===");

            $results = $service->pullTopMatchesForSpec((int) $externalSpecId, $bracket, $pages, $topN);

            if ($results === []) {
                $this->warn('  No matches found in the scanned window.');

                continue;
            }

            foreach ($results as $r) {
                $this->line("  {$r['matchId']} ({$r['rating']} rating) — {$r['status']}");
                $summary[$r['status']]++;

                if ($r['status'] === 'stored') {
                    $newMatchIds[$r['matchId']] = true;
                }
            }
        }

        $this->newLine();
        $this->info("Fetch summary: {$summary['stored']} newly stored, {$summary['already_on_disk']} already on disk, {$summary['fetch_failed']} failed.");

        if ($newMatchIds === []) {
            $this->info('No new matches to extract from.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Extracting all real players from '.count($newMatchIds).' new match(es)...');

        foreach (array_keys($newMatchIds) as $matchId) {
            $players = $service->extractCastSpellsByPlayer($matchId);

            foreach ($players as $player) {
                if ($player['specId'] === null) {
                    continue;
                }

                $class = GameClass::find($player['classId']);
                $spec = Specialization::find($player['specId']);
                $service->mergeSpellUsage($class->slug, $spec->slug, $matchId, $player['spells']);
            }

            $this->line("  {$matchId}: ".count($players).' real player(s) extracted.');
        }

        return self::SUCCESS;
    }
}
