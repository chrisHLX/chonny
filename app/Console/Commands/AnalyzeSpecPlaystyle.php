<?php

namespace App\Console\Commands;

use App\Http\Services\PlayerMatchAnalysisService;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Runs PlayerMatchAnalysisService over the N highest-rated archived matches that contain a
 * given spec — analysing that spec's own player in each — and writes the combined result to
 *   data/arena-logs/playstyle/{classSlug}/{specSlug}.json
 * (a small, in-repo, view-readable file, same home as the promoted `rotations/` copies —
 * NOT the bulky raw archive).
 *
 * The file holds each match's full per-player analysis plus a `talentSummary` roll-up:
 * for every talent seen across the sample, how many of the N players took it and how many
 * actually got measurable value from it in their match. That "took it / used it" split is
 * the signal for "is this talent real, or a common mispick" — the reason to sample the
 * highest-rated players specifically.
 *
 * Match "rating" is the metadata's playerTeamRating (the recording team's CR) — a proxy for
 * match quality, consistent with every other "highest rated" puller in this codebase; in
 * rated 3v3 the two teams are MMR-matched so it tracks the analysed player's own rating
 * closely enough for ranking a sample.
 *
 * Usage:
 *   php artisan wow:analyze-spec-playstyle shaman enhancement
 *   php artisan wow:analyze-spec-playstyle shaman enhancement --top=15
 */
class AnalyzeSpecPlaystyle extends Command
{
    protected $signature = 'wow:analyze-spec-playstyle
        {classSlug}
        {specSlug}
        {--top=10 : How many of the highest-rated matches to analyse}
        {--min-duration=45 : Skip matches shorter than this (a blowout has no usable rotation)}
        {--one-per-player : Keep only each player\'s single highest-rated match}';

    protected $description = 'Analyse the top-rated archived matches for a spec and write data/arena-logs/playstyle/{class}/{spec}.json';

    public function handle(PlayerMatchAnalysisService $service): int
    {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');
        $top = max(1, (int) $this->option('top'));
        $minDuration = (int) $this->option('min-duration');

        $spec = Specialization::with('gameClass')
            ->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug))
            ->where('slug', $specSlug)->first();

        if (! $spec || ! $spec->external_spec_id) {
            $this->error("Unknown spec {$classSlug}/{$specSlug} (or it has no external_spec_id).");

            return self::FAILURE;
        }

        $archive = config('arena_logs.archive_path');
        $metaDirs = array_filter([$archive.'/metadata', $archive.'/season-current/metadata'], 'is_dir');

        $candidates = [];
        foreach ($metaDirs as $dir) {
            foreach (glob($dir.'/*.json') as $path) {
                $meta = json_decode(File::get($path), true);
                if (! is_array($meta)) {
                    continue;
                }

                if ((int) ($meta['durationInSeconds'] ?? 0) < $minDuration) {
                    continue;
                }

                $units = collect($meta['units'] ?? [])
                    ->filter(fn ($u) => str_starts_with((string) ($u['id'] ?? ''), 'Player-')
                        && (int) ($u['spec'] ?? 0) === $spec->external_spec_id);

                if ($units->isEmpty()) {
                    continue;
                }

                $matchId = $meta['id'] ?? basename($path, '.json');
                $candidates[$matchId] = [
                    'matchId' => $matchId,
                    'rating' => (int) ($meta['playerTeamRating'] ?? 0),
                    'durationSec' => (int) ($meta['durationInSeconds'] ?? 0),
                    'guid' => $units->first()['id'],
                    'player' => $units->first()['name'],
                    'mirror' => $units->count() > 1,
                ];
            }
        }

        if ($candidates === []) {
            $this->error("No archived matches contain {$classSlug}/{$specSlug} (spec id {$spec->external_spec_id}).");

            return self::FAILURE;
        }

        $ranked = collect($candidates)->sortByDesc('rating');

        if ($this->option('one-per-player')) {
            $ranked = $ranked->groupBy('player')->map->first()->sortByDesc('rating');
        }

        $picked = $ranked->take($top)->values();
        $this->info("Analysing {$picked->count()} of ".count($candidates)." eligible matches for {$classSlug}/{$specSlug} (rating {$picked->max('rating')} → {$picked->min('rating')}, ≥{$minDuration}s)...");

        $matches = [];
        foreach ($picked as $c) {
            $a = $service->analyze($c['matchId'], $c['guid']);
            if (isset($a['error'])) {
                $this->warn("  skip {$c['matchId']}: {$a['error']}");

                continue;
            }
            $a['rating'] = $c['rating'];
            $a['mirror'] = $c['mirror'];
            $matches[] = $a;
            $flagged = collect($a['talentAnalysis'])->filter(fn ($r) => str_starts_with($r['verdict'], 'UNUSED') || str_starts_with($r['verdict'], 'DEAD') || str_starts_with($r['verdict'], 'NO PROC'))->count();
            $totalCasts = collect($a['usage']['casts'])->sum('count');
            $this->line(sprintf('  %-24s r%d  %ds  ·  %d casts  ·  %d flagged talents', $this->trunc($c['player'], 24), $c['rating'], $c['durationSec'], $totalCasts, $flagged));
        }

        if ($matches === []) {
            $this->error('Every candidate match failed to analyse.');

            return self::FAILURE;
        }

        $out = [
            'generatedAt' => now()->toIso8601String(),
            'class' => $classSlug,
            'spec' => $specSlug,
            'externalSpecId' => $spec->external_spec_id,
            'sampleSize' => count($matches),
            'ratingRange' => [collect($matches)->max('rating'), collect($matches)->min('rating')],
            'talentSummary' => $this->summariseTalents($matches),
            'matches' => $matches,
        ];

        $dir = base_path("data/arena-logs/playstyle/{$classSlug}");
        File::ensureDirectoryExists($dir);
        $file = "{$dir}/{$specSlug}.json";
        File::put($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->newLine();
        $this->line('  <fg=yellow>TALENT CONVERGENCE</> (took / used across the sample)');
        foreach (array_slice($out['talentSummary'], 0, 24) as $t) {
            $bar = str_repeat('█', $t['used']).str_repeat('·', $t['flagged']).str_repeat('░', $t['passive']);
            $this->line(sprintf('    %-24s  %2d/%-2d  %s', $this->trunc($t['talent'], 24), $t['used'] + $t['passive'], $t['took'], $bar));
        }
        $mostFlagged = collect($out['talentSummary'])->filter(fn ($t) => $t['flagged'] >= 2)->sortByDesc('flagged')->take(8);
        if ($mostFlagged->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=red>OFTEN TAKEN, RARELY USED</> (candidate mispicks / very situational)');
            foreach ($mostFlagged as $t) {
                $this->line(sprintf('    %-24s  flagged %d/%d', $this->trunc($t['talent'], 24), $t['flagged'], $t['took']));
            }
        }

        $this->newLine();
        $this->info("Wrote {$file}");
        $this->line("  {$out['sampleSize']} matches · ".count($out['talentSummary']).' distinct talents');

        return self::SUCCESS;
    }

    private function trunc(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
    }

    /**
     * Roll every match's talentAnalysis up into one per-talent row:
     *   took   = how many of the N sampled players selected it
     *   used   = how many got a non-flagged, non-passive verdict from it
     *   flagged, passive = the other buckets
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function summariseTalents(array $matches): array
    {
        $agg = [];

        foreach ($matches as $m) {
            foreach ($m['talentAnalysis'] as $r) {
                $key = $r['talent'];
                $agg[$key] ??= ['talent' => $key, 'took' => 0, 'used' => 0, 'flagged' => 0, 'passive' => 0, 'verdicts' => []];
                $agg[$key]['took']++;

                $isFlag = str_starts_with($r['verdict'], 'UNUSED') || str_starts_with($r['verdict'], 'DEAD') || str_starts_with($r['verdict'], 'NO PROC');
                $isPassive = in_array($r['linkType'], ['passive', 'unknown'], true);

                if ($isFlag) {
                    $agg[$key]['flagged']++;
                } elseif ($isPassive) {
                    $agg[$key]['passive']++;
                } else {
                    $agg[$key]['used']++;
                }

                $agg[$key]['verdicts'][$r['verdict']] = ($agg[$key]['verdicts'][$r['verdict']] ?? 0) + 1;
            }
        }

        return collect($agg)
            ->sortByDesc(fn ($r) => [$r['took'], $r['used']])
            ->values()->all();
    }
}
