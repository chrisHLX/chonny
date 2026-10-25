<?php

namespace App\Console\Commands;

use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * One game's Watch page: the player's own recording with the goes and deaths this project measured
 * drawn underneath it (resources/views/desktop/watch.blade.php).
 *
 * Per LOBBY, not per round -- one recording covers a whole Solo Shuffle. Each round's position
 * inside the recording comes from the wall-clock gap between the rounds' `played_at` values, which
 * includes the prep time between them for free.
 *
 * Nothing is uploaded. The page reads a local file through a picker; this command only draws the
 * timeline beside it. A video the desktop app recorded through OBS (2026-10-25) has a timing file
 * beside it, and its page opens the video by itself, already lined up (videos(), and
 * tools/desktop-app/README.md, "Recording").
 *
 *   php artisan wow:watch-page --user=1                      every lobby that user has
 *   php artisan wow:watch-page --user=1 --lobby=abc123
 *   php artisan wow:watch-page --user=1 --videos="D:/Videos" match recordings by their file time
 *   php artisan wow:watch-page --demo                        a shaped sample, no archive needed
 *
 * The demo exists because the round archive lives outside the repo (rule 14) and a dev machine
 * often has no rounds at all; it uses the same payload shape, so the page can be worked on anyway.
 */
class BuildWatchPage extends Command
{
    protected $signature = 'wow:watch-page
        {--user= : the user whose games to draw}
        {--lobby= : one lobby id, instead of all of them}
        {--videos= : a folder of recordings, matched to each game by file time}
        {--demo : render a shaped sample instead}
        {--out=storage/app/watch : directory to write into}';

    protected $description = "A game's video page: the measured goes and deaths on a timeline beside the player's own recording";

    public function handle(): int
    {
        $dir = base_path($this->option('out'));
        File::ensureDirectoryExists($dir);

        if ($this->option('demo') || ! $this->option('user')) {
            $page = $this->demo();
            File::put($dir.'/demo.html', view('desktop.watch', $page)->render());
            $this->info('Wrote '.$dir.'/demo.html  (sample data)');

            return self::SUCCESS;
        }

        $user = User::find((int) $this->option('user'));
        if ($user === null) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $videos = $this->videos($this->option('videos'));
        $lobbies = ArenaRound::query()
            ->where('user_id', $user->id)
            ->when($this->option('lobby'), fn ($q, $l) => $q->where('lobby_id', $l))
            ->orderBy('played_at')
            ->get()
            ->groupBy('lobby_id');

        if ($lobbies->isEmpty()) {
            $this->warn('No rounds for that user. Run `php artisan wow:sync` first, or try --demo.');

            return self::SUCCESS;
        }

        $written = 0;
        foreach ($lobbies as $lobby => $rounds) {
            $page = $this->lobbyPage($rounds->sortBy(fn (ArenaRound $r) => (string) $r->played_at)->values(), $videos);
            if ($page === null) {
                continue;
            }
            File::put($dir."/watch-{$lobby}.html", view('desktop.watch', $page)->render());
            $written++;
        }

        $this->info("Wrote {$written} watch page(s) to {$dir}");
        if ($videos === [] && $this->option('videos')) {
            $this->warn('No video files found in '.$this->option('videos'));
        }

        return self::SUCCESS;
    }

    /**
     * One lobby as the page's model. Round n's offset into the recording is the real time since
     * round 1 began, so the prep between rounds is included without being measured separately.
     *
     * @param  \Illuminate\Support\Collection<int, ArenaRound>  $rounds
     * @param  array<int, array{name: string, from: int, to: int}>  $videos
     * @return array<string, mixed>|null
     */
    private function lobbyPage($rounds, array $videos): ?array
    {
        $first = $rounds->first();
        $zero = $first->played_at ? Carbon::parse($first->played_at)->getTimestamp() : null;

        // Recorded by the app: its timing file holds each round's start to the millisecond, on the
        // log's own clock, so every rail is placed from it and round 1's position needs no lining up.
        $recorded = $zero !== null ? $this->recordingOf($videos, $zero) : null;
        $precise = function ($playedAt) use ($recorded): ?float {
            if (! $playedAt) {
                return null;
            }
            $at = (float) Carbon::parse($playedAt)->getTimestamp();
            foreach ($recorded['rounds'] ?? [] as $r) {
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
            if ($recorded !== null && $zeroPrecise !== null && ($p = $precise($round->played_at)) !== null) {
                $startOffset = round(max(0, $p - $zeroPrecise), 2);
            }

            $model[] = [
                'label' => $rounds->count() > 1 ? 'Round '.($i + 1) : 'The game',
                'result' => isset($analysis['won']) ? ($analysis['won'] ? 'won' : 'lost') : null,
                'duration' => (int) ceil(max((float) ($round->duration_seconds ?? 0), $last + 8)),
                'startOffset' => $startOffset,
                'goes' => array_values($analysis['goes'] ?? []),
                'deaths' => array_values($analysis['deaths'] ?? []),
            ];
        }

        if ($model === []) {
            return null;
        }

        $playedAt = $first->played_at ? Carbon::parse($first->played_at) : null;

        return [
            'title' => trim(($first->bracket ?? 'Game').' · '.($playedAt?->format('j M Y, H:i') ?? '')),
            'playedAt' => $playedAt?->format('j M Y, H:i'),
            'suggest' => $recorded === null && $playedAt ? $this->match($videos, $playedAt->getTimestamp()) : null,
            'video' => $recorded !== null && $zeroPrecise !== null ? [
                'name' => $recorded['name'],
                'url' => $this->fileUrl($recorded['path']),
                'offset' => round($zeroPrecise - $recorded['started'], 2),
            ] : null,
            'rounds' => $model,
        ];
    }

    /**
     * Recordings in a folder, each with the window of wall-clock time it covers. A file's mtime is
     * when writing FINISHED, so the window runs back from there by the file's own duration -- which
     * we do not know without decoding, so it is approximated generously and the match is a hint on
     * the page, never an automatic alignment.
     *
     * A video the desktop app recorded (2026-10-25, tools/desktop-app/Recorder.cs) has a timing file
     * beside it, "<video>.mindcollector.json": the instant its first frame was recorded and each
     * round's ARENA_MATCH_START, on this PC's clock, which is the combat log's clock too. Its window
     * is exact, so it is lined up automatically rather than suggested.
     *
     * @return array<int, array{name: string, from: float, to: float, path?: string, started?: float, rounds?: array<int, float>}>
     */
    private function videos(?string $folder): array
    {
        if (! $folder || ! File::isDirectory($folder)) {
            return [];
        }

        $out = [];
        foreach (File::files($folder) as $f) {
            if (! in_array(strtolower($f->getExtension()), ['mp4', 'mkv', 'mov', 'flv', 'avi', 'webm'], true)) {
                continue;
            }
            $timing = $f->getPathname().'.mindcollector.json';
            $t = File::exists($timing) ? json_decode(File::get($timing), true) : null;
            if (is_array($t) && isset($t['recordingStarted'], $t['recordingStopped'])) {
                $out[] = [
                    'name' => $f->getFilename(),
                    'path' => $f->getPathname(),
                    'from' => $this->clock($t['recordingStarted']),
                    'to' => $this->clock($t['recordingStopped']),
                    'started' => $this->clock($t['recordingStarted']),
                    'rounds' => array_map(fn ($r) => $this->clock($r), $t['rounds'] ?? []),
                ];

                continue;
            }
            $end = $f->getMTime();
            // Assume no single recording runs longer than three hours.
            $out[] = ['name' => $f->getFilename(), 'from' => $end - 10800, 'to' => $end];
        }

        return $out;
    }

    /** A timing file's "2026-10-25 11:05:40.063" as seconds, read the way played_at is (the log's wall clock, in the app's zone). */
    private function clock(string $at): float
    {
        return (float) Carbon::parse($at)->format('U.v');
    }

    /**
     * The app's recording of a game whose round 1 started at $startedAt (played_at, cut to the
     * second). Matched by the round starts in its timing file first: a recording that starts at the
     * gates has its first frame up to about 1s AFTER the START line (16:15:07.822 against a first
     * frame of 16:15:08.405 on 25 Oct), so a window from the first frame missed half of them.
     *
     * @param  array<int, array<string, mixed>>  $videos
     */
    private function recordingOf(array $videos, int $startedAt): ?array
    {
        foreach ($videos as $v) {
            foreach ($v['rounds'] ?? [] as $r) {
                if ($r >= $startedAt - 0.5 && $r < $startedAt + 1.5) {
                    return $v;
                }
            }
        }
        foreach ($videos as $v) {
            if (isset($v['started']) && $startedAt >= $v['from'] - 2 && $startedAt <= $v['to']) {
                return $v;
            }
        }

        return null;
    }

    /** file:///D:/MindCollector/videos/2026-10-25%2011-04-41.mp4 */
    private function fileUrl(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));

        return 'file:///'.implode('/', array_map(
            fn ($p) => preg_match('/^[A-Za-z]:$/', $p) ? $p : rawurlencode($p),
            $parts,
        ));
    }

    /** @param  array<int, array{name: string, from: int|float, to: int|float}>  $videos */
    private function match(array $videos, int $startedAt): ?string
    {
        $fits = array_values(array_filter($videos, fn ($v) => $startedAt >= $v['from'] && $startedAt <= $v['to']));
        if ($fits === []) {
            return null;
        }
        // The recording that finished soonest after the game is the likeliest to be the one.
        usort($fits, fn ($a, $b) => $a['to'] <=> $b['to']);

        return $fits[0]['name'];
    }

    /**
     * A sample in the real payload's shape (RoundAnalysisService's return, and the fixture in
     * tests/Feature/GameCardTest.php), for building the page where no archive exists.
     *
     * @return array<string, mixed>
     */
    private function demo(): array
    {
        return [
            'title' => 'Sample game (no archive on this machine)',
            'playedAt' => '19 Oct 2026, 21:14',
            'suggest' => null,
            'rounds' => [
                [
                    'label' => 'Round 1', 'result' => 'won', 'duration' => 180, 'startOffset' => 0,
                    'goes' => [
                        ['side' => 'us', 'from' => 8.0, 'to' => 27.5, 'kill' => null, 'target' => 'Umoongzin',
                            'chain' => 'Mighty Bash > kill_target, Convoke the Spirits, Rip'],
                        ['side' => 'them', 'from' => 41.2, 'to' => 63.0, 'kill' => null, 'target' => 'Bluegator',
                            'chain' => 'Cyclone > healer, Chaos Bolt, Havoc'],
                        ['side' => 'us', 'from' => 121.0, 'to' => 149.5, 'kill' => true, 'target' => 'Soulrulerxc',
                            'chain' => 'Solar Beam > healer, Starsurge, Wrath'],
                    ],
                    'deaths' => [['t' => 147.9, 'side' => 'them', 'name' => 'Soulrulerxc-Realm',
                        'killingBlow' => ['spell' => 'Starsurge']]],
                ],
                [
                    'label' => 'Round 2', 'result' => 'lost', 'duration' => 95, 'startOffset' => 235,
                    'goes' => [
                        ['side' => 'them', 'from' => 12.0, 'to' => 44.0, 'kill' => true, 'target' => 'Dzdv',
                            'chain' => 'Psychic Horror > kill_target, Chaos Bolt, Shadowburn'],
                    ],
                    'deaths' => [['t' => 42.5, 'side' => 'us', 'name' => 'Dzdv-Realm',
                        'killingBlow' => ['spell' => 'Chaos Bolt']]],
                ],
            ],
        ];
    }
}
