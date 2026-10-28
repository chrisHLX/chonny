<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\User;
use App\Models\YoutubeVideo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The games the app uploaded to YouTube, shared (2026-10-28): what YouTube says about each video
 * (who can watch it, whether it is still processing, its views) and what the library filters by.
 *
 * YouTube is asked in up to three ways, the first that answers:
 *  1. the owner's own access token (the link the app uploads with), which sees private videos too;
 *  2. a plain API key (services.youtube.api_key), which sees public and unlisted ones;
 *  3. oEmbed, which needs nothing and says only whether anyone can play it (no views).
 * The token's scope is youtube.upload, which Google may not accept for reading; when it refuses,
 * that is remembered for a day and the other two are used.
 */
class YouTubeVideoService
{
    private const LIST = 'https://www.googleapis.com/youtube/v3/videos';

    private const OEMBED = 'https://www.youtube.com/oembed';

    /** Ask YouTube again about any of these videos last asked about longer ago than this. */
    public function refresh(Collection $videos, int $staleMinutes = 10): void
    {
        $due = $videos->filter(fn (YoutubeVideo $v) => ! $v->checked_at || $v->checked_at->lt(now()->subMinutes($staleMinutes)))->values();
        if ($due->isEmpty()) {
            return;
        }

        $found = [];
        foreach ($due->groupBy('user_id') as $userId => $mine) {
            $found += $this->listWithToken($userId, $mine->pluck('video_id')->all());
        }
        if ($key = config('services.youtube.api_key')) {
            $left = $due->reject(fn ($v) => isset($found[$v->video_id]))->pluck('video_id')->all();
            foreach (array_chunk($left, 50) as $ids) {
                $found += $this->list($ids, ['key' => $key]) ?? [];
            }
        }

        foreach ($due as $v) {
            if (isset($found[$v->video_id])) {
                $item = $found[$v->video_id];
                $upload = $item['status']['uploadStatus'] ?? 'processed';
                $v->status = in_array($upload, ['failed', 'rejected', 'deleted'], true) ? 'removed' : ($item['status']['privacyStatus'] ?? $v->status);
                $v->processing = $upload === 'uploaded';
                $v->embeddable = (bool) ($item['status']['embeddable'] ?? true);
                $v->views = isset($item['statistics']['viewCount']) ? (int) $item['statistics']['viewCount'] : $v->views;
                $v->title = mb_substr((string) ($item['snippet']['title'] ?? $v->title), 0, 200) ?: null;
            } elseif (! $this->oembed($v)) {
                continue;   // YouTube could not be reached: keep what we had, and ask next time
            }
            $v->checked_at = now();
            $v->save();
        }
    }

    /**
     * The owner's videos read with their own upload link, or nothing when they have none or Google
     * will not let that scope read.
     *
     * @return array<string, array<string, mixed>>
     */
    private function listWithToken(int $userId, array $ids): array
    {
        if (Cache::get('youtube-read-refused')) {
            return [];
        }
        $user = User::find($userId);
        if (! $user?->youtube_token) {
            return [];
        }
        $token = Cache::remember("youtube-access:{$userId}", now()->addMinutes(50), fn () => app(YouTubeService::class)->accessToken($user)['token'] ?? null);
        if (! $token) {
            Cache::forget("youtube-access:{$userId}");

            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $found = $this->list($chunk, [], $token);
            if ($found === null) {
                return $out;
            }
            $out += $found;
        }

        return $out;
    }

    /**
     * videos.list for these ids: item by id, or null when YouTube refused or could not be reached.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function list(array $ids, array $query, ?string $token = null): ?array
    {
        try {
            $request = Http::timeout(6);
            if ($token) {
                $request = $request->withToken($token);
            }
            $res = $request->get(self::LIST, $query + ['part' => 'status,statistics,snippet', 'id' => implode(',', $ids)]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        if ($token && $res->status() === 403) {
            // The upload scope cannot read: say so once a day rather than ask on every page.
            Cache::put('youtube-read-refused', true, now()->addDay());
        }
        if (! $res->successful()) {
            return null;
        }

        return collect($res->json('items', []))->keyBy('id')->all();
    }

    /** oEmbed answers for anyone: playable (with its title), private, or gone. False when unreachable. */
    private function oembed(YoutubeVideo $v): bool
    {
        try {
            $res = Http::timeout(5)->get(self::OEMBED, ['url' => 'https://www.youtube.com/watch?v='.$v->video_id, 'format' => 'json']);
        } catch (\Throwable $e) {
            return false;
        }
        if ($res->successful()) {
            // Unlisted and public both play; oEmbed cannot tell them apart, so the upload's choice stands.
            $v->status = in_array($v->status, ['public', 'unlisted'], true) ? $v->status : ($v->privacy === 'public' ? 'public' : 'unlisted');
            $v->processing = false;
            $v->title = mb_substr((string) $res->json('title', $v->title), 0, 200) ?: null;

            return true;
        }
        if (in_array($res->status(), [401, 403], true)) {
            $v->status = 'private';

            return true;
        }
        if (in_array($res->status(), [400, 404], true)) {
            $v->status = 'removed';

            return true;
        }

        return false;
    }

    /**
     * What the library shows and filters by, from the game's stored rounds: bracket, when, the
     * result, MMR, and both teams by spec (the recording player's team first). Specs only, never
     * names, like the video's own title.
     *
     * @return array<string, mixed>|null
     */
    public function summary(YoutubeVideo $video): ?array
    {
        $rounds = ArenaRound::where('user_id', $video->user_id)->where('lobby_id', $video->lobby_id)->get()
            ->sortBy(fn (ArenaRound $r) => (string) $r->played_at)->values();
        if ($rounds->isEmpty()) {
            return null;
        }
        $analysis = fn (ArenaRound $r) => ($p = (array) $r->payload)['analysis'] ?? $p;
        $first = $analysis($rounds->first());
        $shuffle = $rounds->count() > 1 || str_contains((string) $rounds->first()->bracket, 'Shuffle');
        $logs = app(ArenaLogService::class);

        $player = function (array $p) use ($logs) {
            $spec = isset($p['specExternalId']) ? $logs->specForExternalId((string) $p['specExternalId']) : null;

            return [
                'spec' => $p['spec'] ?? '?',
                // The spec's own slug, else its name without the class ("Frost Death Knight" -> frost).
                'specSlug' => $spec?->slug ?? (isset($p['spec'], $p['classSlug'])
                    ? Str::slug(Str::beforeLast($p['spec'], ' '.Str::title(str_replace('-', ' ', $p['classSlug'])))) : null),
                'classSlug' => $p['classSlug'] ?? null,
                'healer' => ! empty($p['healer']),
                'pov' => ! empty($p['logger']),
                'icon' => $spec?->icon_name ? '/storage/spec-icons/'.$spec->icon_name : null,
            ];
        };
        $team = fn (string $side) => collect($first['players'] ?? [])->where('side', $side)
            ->sortBy(fn ($p) => (! empty($p['logger']) ? 0 : 1).(! empty($p['healer']) ? 2 : 1).($p['spec'] ?? ''))
            ->map($player)->values()->all();

        $won = $rounds->filter(fn ($r) => $analysis($r)['won'] ?? false)->count();

        return [
            'bracket' => $shuffle ? 'Solo Shuffle' : str_replace('Rated ', '', (string) ($rounds->first()->bracket ?: '3v3')),
            'playedAt' => $rounds->first()->played_at?->toIso8601String(),
            'won' => $shuffle ? null : (bool) ($first['won'] ?? false),
            'record' => $shuffle ? [$won, $rounds->count() - $won] : null,
            'mmr' => isset($first['mmr']['us']) ? (int) $first['mmr']['us'] : null,
            'us' => $team('us'),
            // In a Solo Shuffle the teams change every round: the first round's is shown.
            'them' => $team('them'),
            'pov' => collect($first['players'] ?? [])->firstWhere('logger', true)['spec'] ?? null,
            'title' => app(YouTubeService::class)->details(User::findOrFail($video->user_id), $video->lobby_id, null, [])['title'] ?? null,
        ];
    }

    /** Fill in the summary of a video that has none yet (uploaded before 28 Oct, or just now). */
    public function ensureSummary(YoutubeVideo $video): void
    {
        if ($video->game !== null) {
            return;
        }
        $game = $this->summary($video);
        if ($game === null) {
            return;
        }
        $video->game = $game;
        $video->played_at = isset($game['playedAt']) ? Carbon::parse($game['playedAt']) : null;
        $video->save();
    }
}
