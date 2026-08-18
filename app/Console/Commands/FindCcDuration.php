<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Scans every raw arena log already on file (data/arena-logs/raw/*.log.gz) for a given
 * spell's SPELL_AURA_APPLIED/REFRESH/REMOVED sequence and reports the observed duration of
 * every real instance — generalizes the manual timestamp-diffing done by hand for Cheap Shot
 * (2026-08-14, see "PVP duration.txt"/"CC Durations Off Arena logs.txt" in that same folder)
 * and Grapple Weapon into a reusable tool.
 *
 * METHODOLOGY — histogram/mode, not max (upgraded 2026-08-17; see below for why this command's
 * own code had drifted out of sync with the methodology already proven and applied to the live
 * DB on 2026-08-14). Every raw instance is rounded to the nearest 0.5s (WoW CC durations are
 * always clean half-second-or-whole-second values — a raw 2.97s or 3.02s reading is log-
 * timestamp jitter around a real 3.0s, not a genuinely different duration) and bucketed. The
 * bucket with the most support (the mode) is the recommended base duration — proven more
 * reliable than a plain max() by the 2026-08-14 clustering pass: Blind's max was misleadingly
 * high, but 37/63 real instances clustered at 5.0s with the next-largest bucket just scattered
 * noise; Kidney Shot was the same shape, 75/152 at 5.0s reversing an earlier "6s" recollection
 * that turned out to be wrong. A dominant, repeated cluster is much stronger evidence of the
 * true value than whichever single instance happened to run longest.
 *
 * OUTLIER HANDLING (new 2026-08-17, direct user instruction). Any rounded value STRICTLY ABOVE
 * the mode is not silently averaged in or silently discarded — each such instance's own match is
 * checked via ArenaLogService::matchRosterHasSpec() for a Preservation Evoker, since Preservation
 * has a real, verified ability (Oppressing Roar, spell_id 372048 — "increases the duration of
 * crowd controls that affect them" — already documented in CLAUDE.md's "PvP CC duration cap"
 * section as the one confirmed exception to the flat 6s PvP CC cap) that would genuinely explain
 * a longer-than-normal reading without it being a different true base duration. This directly
 * closes a real open question from the original 2026-08-14 work: Strangulate had one measured
 * instance at 5.2s against a 4s consensus, and the user's own note at the time was "definately 4
 * i think it might have been modiffied by dragon ability" — an unverified guess. Oppressing
 * Roar's own text is literally suffixed "(desc=Black)" (a Black Dragonflight ability) — this is
 * almost certainly the exact mechanic that was being recalled. An outlier instance whose match
 * roster DID include a Preservation Evoker is reported as "explained" and excluded from the base-
 * duration recommendation (the mode already excludes it structurally, since it's calculated from
 * the dominant cluster, not from outliers — this check exists to CONFIRM why the outlier
 * happened, not to change the recommendation). An outlier with no Preservation Evoker present is
 * reported as a genuine, unexplained anomaly — never silently resolved either way, matching this
 * project's standing "flag, don't guess" discipline.
 *
 * A REFRESH mid-window (the target got re-CC'd while still under the effect) resets the
 * measurement window to the refresh's own timestamp, not the original APPLIED — the reported
 * duration for that instance is REMOVED_time - last_(APPLIED_or_REFRESH)_time, matching how a
 * real refresh actually behaves in-game (remaining duration resets to full, not additive).
 *
 * This does NOT determine cooldown — only aura duration. Two casts of the same ability by the
 * same source in one match give a real minimum cooldown FLOOR (the ability was clearly ready
 * again by that point), never an exact confirmed value — printed separately, clearly labeled
 * as a floor, never presented as the real cooldown.
 *
 * This command only ever REPORTS — it never writes to the database. pvp_duration_seconds stays
 * a deliberately hand-applied field (see CLAUDE.md); the recommended value here is evidence to
 * review, the same "preview, then a human decides" posture as wow:diff-arena-spells' non---apply
 * output.
 *
 * Usage: php artisan wow:find-cc-duration 233759
 */
class FindCcDuration extends Command
{
    protected $signature = 'wow:find-cc-duration {spellId : Blizzard external spell_id}';

    protected $description = 'Scan every locally-stored arena log for a spell\'s real aura-duration instances (histogram/mode = recommended duration; outliers checked against Preservation Evoker\'s Oppressing Roar)';

    public function handle(ArenaLogService $arenaLogService): int
    {
        $spellId = (int) $this->argument('spellId');

        $patch = Patch::where('is_current', true)->first();
        $spell = $patch ? Spell::where('patch_id', $patch->id)->where('spell_id', $spellId)->first() : null;

        if ($spell) {
            $this->info("Spell: {$spell->name} (id={$spellId}) — stored duration_seconds={$spell->duration_seconds}, pvp_duration_seconds={$spell->pvp_duration_seconds}");
        } else {
            $this->warn("Spell id={$spellId} not found in the current patch's spells table — searching logs anyway.");
        }

        $files = glob(base_path('data/arena-logs/raw/*.log.gz'));

        if ($files === []) {
            $this->error('No arena logs on file — run wow:fetch-arena-log first.');

            return self::FAILURE;
        }

        $this->info('Scanning '.count($files)." stored match log(s) for spell_id {$spellId}...\n");

        // Each entry: ['duration' => float, 'matchId' => string]
        $instances = [];
        $castGapsPerMatch = [];
        $matchesWithHits = 0;

        foreach ($files as $file) {
            $matchId = basename($file, '.log.gz');
            $raw = gzdecode(File::get($file));

            if (!str_contains($raw, ",{$spellId},\"")) {
                continue;
            }

            $pattern = '/^([\d\/: .-]+)\s+(SPELL_AURA_APPLIED|SPELL_AURA_REFRESH|SPELL_AURA_REMOVED|SPELL_CAST_SUCCESS),([^,]+),"[^"]*",[^,]*,[^,]*,([^,]+),"([^"]*)",[^,]*,[^,]*,'.$spellId.',"[^"]*"/m';
            preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER);

            if ($matches === []) {
                continue;
            }

            $matchesWithHits++;
            $windows = []; // key: "source|dest" => ['start' => float|null]
            $castTimesBySource = [];
            $instanceNum = 0;

            foreach ($matches as $m) {
                $ts = $this->parseTimestamp($m[1]);
                $event = $m[2];
                $source = $m[3];
                $dest = $m[4];
                $key = "{$source}|{$dest}";

                if ($event === 'SPELL_CAST_SUCCESS') {
                    $castTimesBySource[$source][] = $ts;

                    continue;
                }

                if ($event === 'SPELL_AURA_APPLIED' || $event === 'SPELL_AURA_REFRESH') {
                    $windows[$key] = $ts;

                    continue;
                }

                if ($event === 'SPELL_AURA_REMOVED' && isset($windows[$key])) {
                    $duration = round($ts - $windows[$key], 3);
                    $instanceNum++;
                    $instances[] = ['duration' => $duration, 'matchId' => $matchId];
                    $this->line("  [{$matchId}] instance {$instanceNum}: {$duration}s");
                    unset($windows[$key]);
                }
            }

            foreach ($castTimesBySource as $source => $times) {
                sort($times);
                for ($i = 1; $i < count($times); $i++) {
                    $castGapsPerMatch[] = round($times[$i] - $times[$i - 1], 3);
                }
            }
        }

        if ($matchesWithHits === 0) {
            $this->warn('No matches on file contain this spell_id at all.');

            return self::SUCCESS;
        }

        $this->newLine();

        if ($instances === []) {
            $this->warn('Spell was cast but no AURA_APPLIED->REMOVED window was captured (may not be an aura-granting effect, or always got dispelled/never landed).');
        } else {
            $this->analyzeDurations($instances, $matchesWithHits, $arenaLogService);
        }

        if ($castGapsPerMatch !== []) {
            sort($castGapsPerMatch);
            $this->newLine();
            $this->info('Observed gaps between successive casts by the same source (a MINIMUM cooldown floor only — never the confirmed exact value):');
            $this->line('  '.implode('s, ', array_map(fn ($g) => (string) $g, $castGapsPerMatch)).'s');
        }

        return self::SUCCESS;
    }

    /**
     * Buckets instances by rounded (nearest 0.5s) duration, reports the histogram, recommends
     * the mode as the base duration, and checks every above-mode outlier against Preservation
     * Evoker's roster presence (Oppressing Roar) — see this class's own docblock for the full
     * reasoning behind each step.
     *
     * @param  array<int, array{duration: float, matchId: string}>  $instances
     */
    private function analyzeDurations(array $instances, int $matchesWithHits, ArenaLogService $arenaLogService): void
    {
        $this->info(count($instances)." real duration instance(s) found across {$matchesWithHits} match(es).");

        $buckets = []; // rounded value (string, e.g. "5.0") => list of instances
        foreach ($instances as $inst) {
            $rounded = round($inst['duration'] * 2) / 2;
            $key = number_format($rounded, 1);
            $buckets[$key][] = $inst;
        }

        // Sort buckets by value descending for a readable histogram (longest first).
        uksort($buckets, fn ($a, $b) => (float) $b <=> (float) $a);

        $this->newLine();
        $this->info('Histogram (rounded to nearest 0.5s):');
        foreach ($buckets as $value => $bucketInstances) {
            $this->line('  '.$value.'s: '.count($bucketInstances).' instance(s)');
        }

        $maxCount = max(array_map('count', $buckets));
        $modeValues = array_keys(array_filter($buckets, fn ($b) => count($b) === $maxCount));

        if (count($modeValues) > 1) {
            $this->newLine();
            $this->warn('AMBIGUOUS: multiple durations tie for the most instances ('.implode('s, ', $modeValues)."s) — not confident enough to recommend one. Needs more matches or a direct confirmation.");

            return;
        }

        $modeValue = (float) $modeValues[0];
        $this->newLine();
        $this->info("RECOMMENDED base duration (mode — {$maxCount}/".count($instances)." instances): {$modeValues[0]}s");

        // Preservation Evoker resolution — done once, not per outlier instance.
        $evoker = GameClass::where('slug', 'evoker')->first();
        $preservation = $evoker ? Specialization::where('class_id', $evoker->id)->where('slug', 'preservation')->first() : null;

        $outlierBuckets = array_filter($buckets, fn ($bucketInstances, $value) => (float) $value > $modeValue, ARRAY_FILTER_USE_BOTH);

        if ($outlierBuckets === []) {
            return;
        }

        $this->newLine();
        $this->info('Above-mode outliers — checked against Preservation Evoker roster presence (Oppressing Roar can genuinely extend CC duration):');

        foreach ($outlierBuckets as $value => $bucketInstances) {
            foreach ($bucketInstances as $inst) {
                $explained = $preservation && $arenaLogService->matchRosterHasSpec($inst['matchId'], $preservation->id);
                $label = $explained
                    ? 'EXPLAINED — Preservation Evoker present (likely Oppressing Roar)'
                    : 'UNEXPLAINED — no Preservation Evoker in this match, real anomaly';
                $this->line("  [{$inst['matchId']}] {$value}s ({$inst['duration']}s raw) — {$label}");
            }
        }
    }

    private function parseTimestamp(string $raw): float
    {
        // "8/11/2026 19:35:07.6661" — date portion is irrelevant for a same-match diff, only
        // time-of-day + fractional seconds matter, so this deliberately ignores date rollover
        // (no match in this project's data runs past midnight).
        if (!preg_match('/(\d{1,2}):(\d{2}):(\d{2})\.(\d+)/', trim($raw), $m)) {
            return 0.0;
        }

        return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3] + ((float) ('0.'.$m[4]));
    }
}
