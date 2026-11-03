<?php

namespace App\Http\Services;

use App\Jobs\BuildCoachPages;
use App\Models\ArenaRound;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * What the signed-in home page leads with (2026-11-03): the player's own games. The audit of that
 * date found the home page opened on five "Tooltips updated" items and a guide builder, with the
 * player's games nowhere on it.
 *
 * Read from the "Your games" index (BuildCoachPages writes it after every upload), never from the
 * rounds' payloads, so the page stays a file read however many games an account holds.
 *
 * One of four states, because each needs a different next step:
 *  - none:     no app key and no games. Next: get the app.
 *  - waiting:  the app is connected and nothing has arrived yet. Next: play a game.
 *  - building: games have arrived and their pages are being built.
 *  - ready:    the latest session, its games and the one thing to look at.
 */
class RecentGamesService
{
    /** A gap longer than this between two games starts a new session. */
    public const SESSION_GAP_HOURS = 3;

    /** Games listed from the latest session; the rest are one link away. */
    public const LIST = 8;

    public function forUser(User $user): array
    {
        $path = BuildCoachPages::dir($user->id).'/index.json';
        $index = File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];
        $games = collect($index['games'] ?? [])->map(fn ($g, $lobby) => $g + ['file' => $lobby.'.html'])
            ->sortByDesc('playedAt')->values();

        if ($games->isEmpty()) {
            $rounds = ArenaRound::where('user_id', $user->id)->count();

            return ['state' => $rounds > 0 ? 'building' : ($user->coach_token_at ? 'waiting' : 'none'), 'rounds' => $rounds];
        }

        // Rounds stored since the pages were last built: an upload whose pages are still being drawn.
        $builtAt = isset($index['generatedAt']) ? Carbon::parse($index['generatedAt']) : null;
        $pending = $builtAt ? ArenaRound::where('user_id', $user->id)->where('created_at', '>', $builtAt)->count() : 0;

        $session = $this->latestSession($games->all());
        $won = collect($session)->filter(fn ($g) => ($g['record'][0] ?? 0) > ($g['record'][1] ?? 0))->count();
        $lost = collect($session)->filter(fn ($g) => ($g['record'][0] ?? 0) < ($g['record'][1] ?? 0))->count();
        // Oldest first: the MMR the session started on and the one it ended on.
        $mmrs = collect(array_reverse($session))->pluck('mmr')->filter()->values();

        // The character played most this session, and the one thing its Improve page names.
        $you = collect($session)->pluck('you')->filter()->countBy()->sortDesc()->keys()->first();
        $character = collect($index['characters'] ?? [])->first(fn ($c) => $c['name'] === $you);
        $lastLoss = collect($session)->first(fn ($g) => ($g['record'][0] ?? 0) < ($g['record'][1] ?? 0));

        $icons = $this->specIcons();

        return [
            'state' => 'ready',
            'pending' => $pending,
            'total' => $games->count(),
            'session' => [
                'day' => Carbon::parse(end($session)['playedAt'])->format('l j M'),
                'from' => Carbon::parse(end($session)['playedAt'])->format('H:i'),
                'to' => Carbon::parse($session[0]['playedAt'])->format('H:i'),
                'count' => count($session),
                'won' => $won,
                'lost' => $lost,
                'mmrFrom' => $mmrs->count() > 1 ? $mmrs->first() : null,
                'mmrTo' => $mmrs->count() > 1 ? $mmrs->last() : null,
                'games' => collect($session)->take(self::LIST)->map(fn ($g) => [
                    'file' => $g['file'],
                    'time' => Carbon::parse($g['playedAt'])->format('H:i'),
                    'bracket' => str_replace('Rated ', '', (string) ($g['bracket'] ?? '')),
                    'shuffle' => empty($g['against']),
                    'record' => $g['record'] ?? [0, 0],
                    'result' => ($g['record'][0] ?? 0) <=> ($g['record'][1] ?? 0),
                    'you' => $g['you'] ?? null,
                    'against' => array_map(fn ($s) => ['name' => $s, 'icon' => $icons[$s] ?? null], $g['against'] ?? []),
                ])->all(),
            ],
            'character' => $character ? [
                'name' => $character['name'],
                'spec' => $character['spec'],
                'games' => $character['games'],
                'file' => $character['file'],
                'focus' => $character['focus'] ?? null,
                // An index built before 2026-11-03 holds no focus at all: say nothing then, rather
                // than that nothing stands out.
                'focusKnown' => array_key_exists('focus', $character),
            ] : null,
            'lastLoss' => $lastLoss ? [
                'file' => $lastLoss['file'],
                'time' => Carbon::parse($lastLoss['playedAt'])->format('H:i'),
                'against' => $lastLoss['against'] ?? [],
            ] : null,
        ];
    }

    /**
     * The newest game and every game before it with no gap over SESSION_GAP_HOURS, newest first.
     *
     * @param  list<array>  $games  newest first
     */
    public function latestSession(array $games): array
    {
        $session = [];
        foreach ($games as $g) {
            $prev = end($session);
            if ($prev && Carbon::parse($prev['playedAt'])->diffInMinutes(Carbon::parse($g['playedAt']), true) > self::SESSION_GAP_HOURS * 60) {
                break;
            }
            $session[] = $g;
        }

        return $session;
    }

    /** "Affliction Warlock" => its icon path, as the logs name specs. */
    private function specIcons(): array
    {
        return Specialization::with('gameClass')->get()
            ->filter(fn ($s) => $s->icon_name && $s->gameClass)
            ->mapWithKeys(fn ($s) => [$s->name.' '.$s->gameClass->name => '/storage/spec-icons/'.$s->icon_name])
            ->all();
    }
}
