<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;

/**
 * The full one-shot pipeline for one spec: search for its highest-rated recent real match
 * (any comp, win or loss — see ArenaLogService::pullHighestRatedMatchForSpec()'s docblock
 * for why win/loss doesn't matter here), fetch+store it, extract that spec's real cast-spell
 * list, merge it into data/arena-logs/spell-usage/{classSlug}/{specSlug}.txt, then run the
 * same diff wow:diff-arena-spells does.
 *
 * IMPORTANT — what this is and isn't for, stated directly by the user when this was built
 * (2026-08-14): this is NOT a completeness check. A single match (or even several) will
 * never contain every spell a spec can use — a real Unholy DK simply not casting Strangulate
 * or Chains of Ice in one match doesn't mean those spells shouldn't be tagged for Unholy, and
 * this tool must never be read as implying that. The only thing it's for is the other
 * direction: surfacing spells that WERE positively seen cast but aren't correctly tagged —
 * a promising true positive is strong evidence, an absence is not evidence of anything.
 * Same asymmetric-evidence framing wow:diff-arena-spells's own docblock already documents
 * for why it has no "not seen" direction at all.
 *
 * One spec per invocation by design (so it stays independently runnable/testable) — but see
 * wow:discover-all-specs for the explicit, user-requested loop across every spec, which calls
 * this command once per spec with --apply --no-reimport and runs one import at the very end
 * instead of once per spec (the "don't bulk-run against a live third-party site unattended"
 * caution this docblock used to raise was a caution to check in about, not a hard rule — the
 * user made that call directly on 2026-08-14).
 *
 * Usage: php artisan wow:discover-spec-spells deathknight unholy
 */
class DiscoverSpecSpells extends Command
{
    protected $signature = 'wow:discover-spec-spells
        {classSlug}
        {specSlug}
        {--bracket=3v3}
        {--pages=3 : How many pages of 50 to scan for the highest-rated match (offset-paged — see ArenaLogService for why count alone cannot go past 50)}
        {--apply : Pass through to wow:diff-arena-spells — write findings to baseline-spec-overrides.txt and re-import immediately}
        {--no-reimport : Pass through to wow:diff-arena-spells — write lines but skip the per-spec re-import (for wow:discover-all-specs)}';

    protected $description = "Search for a spec's highest-rated recent match, pull it, extract its cast spells, and diff against our spell_class_availability tagging";

    public function handle(ArenaLogService $service): int
    {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');

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

        $bracket = $this->option('bracket');
        $pages = (int) $this->option('pages');

        $this->info("Searching for {$class->name}/{$spec->name} (external_spec_id={$spec->external_spec_id}) — scanning up to ".($pages * 50)." recent {$bracket} matches for the highest rating...");

        $result = $service->pullHighestRatedMatchForSpec($spec->external_spec_id, $bracket, $pages);

        switch ($result['status']) {
            case 'no_match_found':
                $this->warn("No recent {$bracket} match found containing {$class->name}/{$spec->name} in the scanned window. Not necessarily a real absence — try a larger --pages.");

                return self::SUCCESS;

            case 'fetch_failed':
                $this->error("Found match {$result['matchId']} but failed to fetch/store it: ".($result['error'] ?? 'unknown error'));

                return self::FAILURE;

            case 'already_best':
            case 'stored_new':
                $matchId = $result['matchId'];
                $prev = ($result['previousRating'] ?? null) !== null ? " (previous best: {$result['previousRating']})" : '';
                $verb = $result['status'] === 'stored_new' ? 'Stored new best match' : 'Already have the best known match';
                $this->info("{$verb}: {$matchId} — {$result['rating']} rating{$prev}.");

                break;

            default:
                $this->error('Unexpected result: '.json_encode($result));

                return self::FAILURE;
        }

        $matchId = $result['matchId'];
        $players = $service->extractCastSpellsByPlayer($matchId);
        $target = collect($players)->firstWhere('specExternalId', $spec->external_spec_id);

        if ($target === null) {
            $this->error("Match {$matchId} was found via the spec search but no real player with that spec resolved during extraction — this shouldn't happen, worth investigating directly.");

            return self::FAILURE;
        }

        $service->mergeSpellUsage($classSlug, $specSlug, $matchId, $target['spells']);
        $this->info(count($target['spells'])." distinct spells cast by {$target['name']} merged into data/arena-logs/spell-usage/{$classSlug}/{$specSlug}.txt");
        $this->newLine();

        return $this->call('wow:diff-arena-spells', [
            'classSlug' => $classSlug,
            'specSlug' => $specSlug,
            '--apply' => $this->option('apply'),
            '--no-reimport' => $this->option('no-reimport'),
        ]);
    }
}
