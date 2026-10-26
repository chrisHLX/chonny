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
                ...$this->falls($analysis),
                ...$this->abilities($analysis),
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
     * Arena frames that follow the video (2026-10-26): every player's buttons with their cooldowns,
     * when each was pressed, when the player was locked out and when they died. The page works out
     * at each moment which are ready and which are coming back (and in how long).
     *
     * The buttons are the player's own, from their talents, when the round stored them
     * (RoundAnalysisService::abilities(), `players[].kit`); otherwise their spec's usual build, and
     * the frame says so. A press only shows what was pressed: a button never pressed reads ready, and
     * cooldown reduction from spending is not in the data, so "back in" is the latest it can be.
     *
     * @return array{frames: array<int, array<string, mixed>>, castIcons: array<string, string>}
     */
    private function abilities(array $analysis): array
    {
        $ledger = app(CooldownLedgerService::class);
        $colors = config('wow_classes.colors', []);
        $casts = $analysis['timeline']['casts'] ?? [];
        $lockout = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];
        $died = [];
        foreach ($analysis['deaths'] ?? [] as $d) {
            if (isset($d['who'], $d['t'])) {
                $died[$d['who']] ??= (float) $d['t'];
            }
        }

        $names = [];
        foreach ($analysis['players'] ?? [] as $p) {
            if (isset($p['guid'])) {
                $names[$p['guid']] = explode('-', $p['name'] ?? '?')[0];
            }
        }
        // Every spell anyone cast, for the casts line under each frame.
        $castNames = [];
        foreach ($casts as $list) {
            foreach ($list as $c) {
                $castNames[$c[1]] = true;
            }
        }
        $castIcons = [];
        foreach (app(SpellIconIndex::class)->for(array_keys($castNames)) as $spell => $model) {
            if ($model?->icon_name) {
                $castIcons[$spell] = \App\Support\DesktopAsset::icon($model->icon_name);
            }
        }

        $out = [];
        foreach ($analysis['players'] ?? [] as $p) {
            if (! isset($p['guid'], $p['side'])) {
                continue;
            }
            $profile = $ledger->profile($p['classSlug'] ?? null, $this->specSlug($p));
            $icons = [];
            $defaults = [];
            foreach (['offensive', 'answers', 'control', 'interrupts'] as $kind) {
                foreach ($profile[$kind] ?? [] as $a) {
                    $icons[$a['name']] ??= $a['icon'] ?? null;
                    if (($a['cooldown'] ?? 0) > 0 && ! isset($defaults[$a['name']])) {
                        $defaults[$a['name']] = ['name' => $a['name'], 'kind' => $kind, 'cd' => (float) $a['cooldown'], 'charges' => (int) ($a['charges'] ?? 1) ?: 1];
                    }
                }
            }
            $own = is_array($p['kit'] ?? null);
            $kit = $own ? $p['kit'] : array_values($defaults);
            // One press logged twice (on two targets, or by clones) within a moment is one press.
            $pressed = [];
            foreach ($casts[$p['guid']] ?? [] as [$t, $spell]) {
                $last = $pressed[$spell][count($pressed[$spell] ?? []) - 1] ?? null;
                if ($last === null || (float) $t - $last > self::SAME_PRESS) {
                    $pressed[$spell][] = (float) $t;
                }
            }
            // Only real cooldowns: one the data gives a cooldown but this player pressed again and
            // again before it could be back is not one for them (Pyroblast "30s", pressed 36 times
            // in five minutes on 19:11), and showing it coming back would be wrong.
            $kit = array_values(array_filter($kit, fn ($a) => $a['cd'] >= self::MIN_COOLDOWN
                && $this->earlyPresses($pressed[$a['name']] ?? [], (float) $a['cd'], (int) $a['charges']) <= max(1, 0.25 * count($pressed[$a['name']] ?? []))));
            $locked = [];
            foreach ($analysis['timeline']['control'] ?? [] as [$on, , $spell, $dr, $from, $to]) {
                if ($on === $p['guid'] && in_array($dr, $lockout, true)) {
                    $locked[] = [(float) $from, (float) $to, $spell];
                }
            }
            // The usual build guesses at talents: a talent never pressed in this game may not have
            // been taken (Crawlordx was shown Typhoon on 19:11 and had not taken it), so it is left
            // out. Pressed, it was taken. Chriso, 26 Oct: no marking, only what is right.
            $spec = $own ? null : $this->spec($p);
            if ($spec !== null) {
                $kit = array_values(array_filter($kit, fn ($a) => ! empty($pressed[$a['name']]) || ! $ledger->isTalent($a['name'], $spec)));
            }

            $out[] = [
                'name' => explode('-', $p['name'])[0],
                'side' => $p['side'],
                'spec' => $p['spec'],
                'color' => $colors[$p['classSlug'] ?? ''] ?? '#B3B3B3',
                'usual' => ! $own,
                'died' => $died[$p['guid']] ?? null,
                'locked' => $locked,
                // Every successful cast, in order: time, spell, its icon, whom it went on.
                'casts' => array_map(fn ($c) => [(float) $c[0], $c[1], isset($c[2]) ? ($names[$c[2]] ?? null) : null], $casts[$p['guid']] ?? []),
                'abilities' => array_map(fn ($a) => $a + [
                    'icon' => isset($icons[$a['name']]) ? \App\Support\DesktopAsset::icon($icons[$a['name']]) : null,
                    'presses' => $pressed[$a['name']] ?? [],
                ], $kit),
            ];
        }

        return ['frames' => $out, 'castIcons' => $castIcons];
    }

    /** Presses closer than this are one press logged twice. */
    private const SAME_PRESS = 1.5;

    /** Shorter cooldowns are rotation, not a button worth tracking in the frames. */
    private const MIN_COOLDOWN = 15.0;

    /** Presses made while every charge should still have been cooling. */
    private function earlyPresses(array $presses, float $cd, int $charges): int
    {
        $max = max(1, $charges);
        $ready = $max;
        $back = null;
        $early = 0;
        foreach ($presses as $t) {
            while ($ready < $max && $back !== null && $back <= $t) {
                $ready++;
                $back = $ready < $max ? $back + $cd : null;
            }
            if ($ready < 1) {
                $early++;
                $ready = 1;
                $back = $ready < $max ? $back : null;
            }
            $ready--;
            $back ??= $t + $cd;
        }

        return $early;
    }

    /** The spec's slug (its profile's file name), from the spec id the round stored. */
    private function specSlug(array $player): ?string
    {
        return $this->spec($player)?->slug;
    }

    /** The round's player's spec, from the spec id it stored. */
    private function spec(array $player): ?\App\Models\Specialization
    {
        static $specs = [];
        $id = (string) ($player['specExternalId'] ?? '');

        return $id === '' ? null : ($specs[$id] ??= app(ArenaLogService::class)->specForExternalId($id));
    }

    /**
     * The round's falls (FallsService, 2026-10-26): goes found from pressure first, by name, for the
     * timeline and the event list; and the offensive cooldowns that moved nobody.
     *
     * @return array{falls: array<int, array<string, mixed>>, unmoved: array<int, array<string, mixed>>}
     */
    private function falls(array $analysis): array
    {
        $names = $this->names($analysis);
        $name = fn (?string $g) => $g === null ? '?' : ($names[$g] ?? '?');
        $read = app(FallsService::class)->read($analysis);

        $falls = array_map(function ($f) use ($name) {
            $offs = array_values(array_filter($f['links'], fn ($l) => $l['cat'] === 'offensive'));
            $ccs = array_values(array_filter($f['links'], fn ($l) => $l['cat'] === 'control'));

            return [
                'side' => $f['side'],
                'on' => $name($f['on']),
                'from' => $f['from'],
                'to' => $f['to'],
                'high' => $f['high'],
                'low' => $f['low'],
                'died' => $f['died'],
                'kind' => $f['kind'],
                'intensity' => $f['intensity'],
                'sync' => $f['sync'] ? sprintf('%s on %s, %s on the healer, %ss apart', $f['sync']['targetBy'], $name($f['on']), $f['sync']['healerBy'], $f['sync']['gap']) : null,
                'cooldowns' => array_map(fn ($l) => $l['spell'].' ('.$name($l['by']).')', $offs),
                'control' => array_map(fn ($l) => $l['spell'].' on '.$name($l['on']), $ccs),
                'answers' => array_map(fn ($a) => $a['spell'].' ('.$name($a['who']).')', $f['answers']),
            ];
        }, $read['falls']);

        $unmoved = array_map(fn ($u) => ['t' => $u['t'], 'side' => $u['side'], 'spell' => $u['spell'], 'by' => $name($u['by']), 'alone' => $u['alone']], $read['unmoved']);

        return ['falls' => $falls, 'unmoved' => $unmoved];
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
