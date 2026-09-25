<?php

namespace App\Console\Commands;

use App\Http\Services\LobbyReviewService;
use Illuminate\Console\Command;

/**
 * Turns an archived game into the committed review artifact that `/wow/game-review` reads.
 *
 * A Solo Shuffle lobby is one review covering its six rounds; every other bracket is one review
 * per match. The grouping key is `metadata.lobbyId`, written at ingest.
 *
 * WHY A COMMAND AND NOT A LIVE PAGE QUERY. `data/arena-logs/metadata/*` and `raw/*` are
 * gitignored (CLAUDE.md rule 14), so a page that read them would work perfectly here and be
 * empty for every real visitor. This writes `data/arena-logs/lobby-reviews/{id}.json`, which is
 * committed, and the page reads only that.
 *
 *   php artisan wow:review-lobby                 # every game not yet reviewed
 *   php artisan wow:review-lobby --all           # rewrite every review
 *   php artisan wow:review-lobby --latest        # just the most recent game
 *   php artisan wow:review-lobby <id>            # one lobby or match id
 *   php artisan wow:review-lobby <id> --print    # also print the mirror comparison
 */
class ReviewLobby extends Command
{
    protected $signature = 'wow:review-lobby
        {id? : A lobby id, or a match id for a non-shuffle bracket}
        {--all : Rebuild every review, not just the missing ones}
        {--latest : Only the most recently played game}
        {--print : Print the mirror comparison as well as writing the artifact}';

    protected $description = 'Write data/arena-logs/lobby-reviews/{id}.json for an archived game';

    public function handle(LobbyReviewService $reviews): int
    {
        $targets = $reviews->reviewable();

        if ($targets === []) {
            $this->warn('No games in the archive. Run wow:ingest-combatlog first.');

            return self::FAILURE;
        }

        if ($id = $this->argument('id')) {
            $targets = array_values(array_filter($targets, fn ($t) => $t['id'] === $id));

            if ($targets === []) {
                $this->error("No archived game with id {$id}.");

                return self::FAILURE;
            }
        } elseif ($this->option('latest')) {
            $targets = [$targets[0]];
        } elseif (! $this->option('all')) {
            $targets = array_values(array_filter(
                $targets,
                fn ($t) => ! file_exists($reviews->artifactPath($t['id']))
            ));

            if ($targets === []) {
                $this->info('Every game already has a review. --all rewrites them.');

                return self::SUCCESS;
            }
        }

        $written = 0;

        foreach ($targets as $t) {
            $review = $reviews->build($t['id']);

            if ($review === null) {
                $this->warn("  {$t['id']} — could not be built, skipped");

                continue;
            }

            $reviews->write($t['id']);
            $written++;

            $record = $review['record'];
            $this->line(sprintf(
                '  <fg=green>%s</>  %-18s %d round(s)  %d-%d  %d mirror(s)',
                substr($t['id'], 0, 12),
                $review['bracket'],
                count($review['rounds']),
                $record['won'],
                $record['lost'],
                count($review['mirrors'])
            ));

            foreach ($review['rounds'] as $r) {
                if ($r['unparsedEvents'] > 0) {
                    $this->warn("    round {$r['sequence']}: {$r['unparsedEvents']} event(s) whose amounts did not parse — a client format change?");
                }
            }

            if ($this->option('print')) {
                $this->printMirrors($review);
            }
        }

        $this->newLine();
        $this->info("Wrote {$written} review(s) to ".LobbyReviewService::ARTIFACT_DIR.'/.');
        $this->line('  <fg=gray>Commit them — the page reads the artifact, not the archive.</>');

        return self::SUCCESS;
    }

    private function printMirrors(array $review): void
    {
        foreach ($review['mirrors'] as $m) {
            // Marked per side, not on the line — "vs Someone (you)" reads as if the second player
            // is the one logging, which is the opposite of how mirrors are ordered.
            $nameA = $m['a']['name'].($m['a']['isYou'] ? ' (you)' : '');
            $nameB = $m['b']['name'].($m['b']['isYou'] ? ' (you)' : '');

            $this->newLine();
            $this->line("    <options=bold>{$m['specLabel']} mirror</> — {$nameA} vs {$nameB}");
            $this->line("      opposed in {$m['roundsOpposed']} round(s), same team in {$m['roundsTogether']}");

            $rows = [];
            foreach (['healingEffective' => 'Effective healing', 'absorbDone' => 'Absorbed',
                'healingOverheal' => 'Overheal', 'damageDone' => 'Damage done',
                'damageTaken' => 'Damage taken'] as $k => $label) {
                $rows[] = [$label, number_format($m['a']['totals'][$k]), number_format($m['b']['totals'][$k])];
            }

            $this->table(['', $nameA, $nameB], $rows);
            $this->line("      {$m['primaryMetricLabel']}: {$m['primaryDeltaPercent']}% difference");

            if (! ($m['statDiff']['unavailable'] ?? true)) {
                $statRows = array_map(
                    fn ($r) => [$r['stat'], $r['a'], $r['b']],
                    $m['statDiff']['rows']
                );
                $this->table(['stat (rating)', $nameA, $nameB], $statRows);
            }

            if (! ($m['gearDiff']['unavailable'] ?? true)) {
                $g = $m['gearDiff'];
                $this->line("      gear median: {$g['a']['median']} vs {$g['b']['median']} (max {$g['a']['max']} vs {$g['b']['max']})");
            }

            $d = $m['talentDiff'];

            if ($d['unavailable'] ?? true) {
                $this->line('      talents: could not be resolved for one side');

                continue;
            }

            $this->line('      only '.$nameA.': '.(collect($d['onlyA'])->pluck('name')->implode(', ') ?: '—'));
            $this->line('      only '.$nameB.': '.(collect($d['onlyB'])->pluck('name')->implode(', ') ?: '—'));

            $p = $m['pvpTalentDiff'];
            $this->line('      pvp — shared: '.(implode(', ', $p['shared']) ?: '—')
                .' | only '.$nameA.': '.(implode(', ', $p['onlyA']) ?: '—')
                .' | only '.$nameB.': '.(implode(', ', $p['onlyB']) ?: '—'));
        }
    }
}
