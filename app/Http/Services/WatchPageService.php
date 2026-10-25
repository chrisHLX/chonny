<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One game's Watch page model (resources/views/desktop/watch.blade.php): the player's recording with
 * what RoundAnalysisService measured drawn underneath, as goes (windows), deaths, and every defensive
 * either side pressed. Used by `wow:watch-page` (local files) and by the desktop app's Videos tab
 * through CoachPagesController::watch (2026-10-26), which plays the video from the player's own disk.
 *
 * Per LOBBY, not per round: one recording covers a whole Solo Shuffle. Round n's place in the video
 * is the real time since round 1 began, so the prep between rounds is included for free. A recording
 * the app made carries each round's ARENA_MATCH_START to the millisecond (its timing file), which
 * replaces played_at's whole seconds wherever the two agree.
 */
class WatchPageService
{
    /**
     * @param  Collection<int, ArenaRound>  $rounds  one lobby
     * @param  array{name: string, url: string, started: float, rounds?: array<int, float>}|null  $recording
     * @return array<string, mixed>|null
     */
    public function page(Collection $rounds, ?array $recording = null, ?string $suggest = null): ?array
    {
        $rounds = $rounds->sortBy(fn (ArenaRound $r) => (string) $r->played_at)->values();
        $first = $rounds->first();
        if ($first === null) {
            return null;
        }
        $zero = $first->played_at ? Carbon::parse($first->played_at)->getTimestamp() : null;

        $precise = function ($playedAt) use ($recording): ?float {
            if (! $playedAt) {
                return null;
            }
            $at = (float) Carbon::parse($playedAt)->getTimestamp();
            foreach ($recording['rounds'] ?? [] as $r) {
                // played_at is the same ARENA_MATCH_START, cut to the second.
                if ($r >= $at - 0.5 && $r < $at + 1.5) {
                    return $r;
                }
            }

            return $at;
        };
        $zeroPrecise = $precise($first->played_at);

        $model = [];
        foreach ($rounds as $i => $round) {
            $p = is_string($round->payload) ? json_decode($round->payload, true) : (array) $round->payload;
            $analysis = $p['analysis'] ?? $p;
            if (! is_array($analysis) || $analysis === []) {
                continue;
            }

            $at = $round->played_at ? Carbon::parse($round->played_at)->getTimestamp() : null;
            $last = 0.0;
            foreach ($analysis['goes'] ?? [] as $g) {
                $last = max($last, (float) ($g['to'] ?? 0));
            }
            foreach ($analysis['deaths'] ?? [] as $d) {
                $last = max($last, (float) ($d['t'] ?? 0));
            }

            $startOffset = $zero !== null && $at !== null ? max(0, $at - $zero) : 0;
            if ($recording !== null && $zeroPrecise !== null && ($pr = $precise($round->played_at)) !== null) {
                $startOffset = round(max(0, $pr - $zeroPrecise), 2);
            }

            $model[] = [
                'label' => $rounds->count() > 1 ? 'Round '.($i + 1) : 'The game',
                'result' => isset($analysis['won']) ? ($analysis['won'] ? 'won' : 'lost') : null,
                'duration' => (int) ceil(max((float) ($round->duration_seconds ?? 0), $last + 8)),
                'startOffset' => $startOffset,
                'goes' => $this->goes($analysis),
                'deaths' => array_values($analysis['deaths'] ?? []),
                'defensives' => $this->defensives($analysis),
            ];
        }

        if ($model === []) {
            return null;
        }

        $playedAt = $first->played_at ? Carbon::parse($first->played_at) : null;

        return [
            'title' => trim(($first->bracket ?? 'Game').' · '.($playedAt?->format('j M Y, H:i') ?? '')),
            'playedAt' => $playedAt?->format('j M Y, H:i'),
            'suggest' => $recording === null ? $suggest : null,
            'video' => $recording !== null && $zeroPrecise !== null ? [
                'name' => $recording['name'],
                'url' => $recording['url'],
                'offset' => round($zeroPrecise - $recording['started'], 2),
            ] : null,
            'rounds' => $model,
        ];
    }

    /**
     * The goes, with their target by name: the analysis names it by GUID ("Go on Player-11-0E64C085").
     *
     * @return array<int, array<string, mixed>>
     */
    private function goes(array $analysis): array
    {
        $names = $this->names($analysis);

        return array_map(function ($g) use ($names) {
            if (isset($g['target']) && isset($names[$g['target']])) {
                $g['target'] = $names[$g['target']];
            }

            return $g;
        }, array_values($analysis['goes'] ?? []));
    }

    /** @return array<string, string> GUID => the name without its realm */
    private function names(array $analysis): array
    {
        return collect($analysis['players'] ?? [])
            ->mapWithKeys(fn ($p) => [$p['guid'] ?? '' => Str::before($p['name'] ?? '?', '-')])
            ->all();
    }

    /**
     * Every defensive either side pressed, by name: who, on whom when it was someone else, and when.
     *
     * @return array<int, array{t: float, side: string, spell: string, who: string, on: ?string}>
     */
    private function defensives(array $analysis): array
    {
        $names = $this->names($analysis);

        $out = [];
        foreach (['us', 'them'] as $side) {
            foreach ($analysis['defensives'][$side]['rows'] ?? [] as $row) {
                if (! isset($row['t'], $row['spell'])) {
                    continue;
                }
                $who = $names[$row['who'] ?? ''] ?? '?';
                $on = isset($row['on']) && $row['on'] !== ($row['who'] ?? null) ? ($names[$row['on']] ?? null) : null;
                $out[] = ['t' => (float) $row['t'], 'side' => $side, 'spell' => $row['spell'], 'who' => $who, 'on' => $on];
            }
        }
        usort($out, fn ($a, $b) => $a['t'] <=> $b['t']);

        return $out;
    }
}
