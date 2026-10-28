<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Uploading the app's recordings to the player's own YouTube channel (2026-10-26).
 *
 * The website holds the link: the player grants the youtube.upload scope once (OAuth, its own
 * client in the Google Cloud project, config services.youtube), and the refresh token is kept
 * encrypted on the user. The desktop app asks for a short-lived access token and the upload's text
 * (prepare()), uploads the file straight from the player's PC to YouTube, and reports the video id
 * back. No video ever passes through this server.
 *
 * The text names specs, never players: a title like "Jungle vs RMD · 1,990 MMR · Feral POV · Loss",
 * and YouTube chapters from the game's falls (FallsService), placed in the video by the recording's
 * timing file exactly as the Watch page places them.
 */
class YouTubeService
{
    public const SCOPE = 'https://www.googleapis.com/auth/youtube.upload';

    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const REVOKE = 'https://oauth2.googleapis.com/revoke';

    /** Shorter than this between two chapters and YouTube shows none: it needs 10s each. */
    private const CHAPTER_GAP = 10;

    private const SHORT = [
        'Beast Mastery' => 'BM', 'Marksmanship' => 'MM', 'Discipline' => 'Disc', 'Restoration' => 'Resto',
        'Assassination' => 'Sin', 'Subtlety' => 'Sub', 'Retribution' => 'Ret', 'Elemental' => 'Ele',
        'Enhancement' => 'Enh', 'Affliction' => 'Aff', 'Destruction' => 'Destro', 'Demonology' => 'Demo',
        'Preservation' => 'Pres', 'Augmentation' => 'Aug', 'Devastation' => 'Dev', 'Windwalker' => 'WW',
        'Mistweaver' => 'MW', 'Brewmaster' => 'Brew', 'Protection' => 'Prot', 'Unholy' => 'UH',
        'Devourer' => 'Devourer',
    ];

    public function configured(): bool
    {
        return filled(config('services.youtube.client_id')) && filled(config('services.youtube.client_secret'));
    }

    /** Whether this player is offered YouTube: set up, and either open to everyone (YOUTUBE_PUBLIC) or an admin or named tester (YOUTUBE_TESTERS) trying it. */
    public function availableTo(?User $user): bool
    {
        return $this->configured() && $user !== null
            && (config('services.youtube.public') || $user->is_admin || in_array($user->id, config('services.youtube.testers', []), true));
    }

    /** The callback on whichever host the player is on: both are registered with Google. */
    public function redirectUri(): string
    {
        return url('/auth/youtube/callback');
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => config('services.youtube.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            // A refresh token every time, so the app can upload later without the player present.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ]);
    }

    /** The refresh token for an authorization code, or null when Google gave none. */
    public function exchange(string $code): ?string
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN, [
            'code' => $code,
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        return $response->successful() ? ($response->json('refresh_token') ?: null) : null;
    }

    /**
     * A short-lived access token for the player's upload, or null when they are not linked. A
     * refresh token Google no longer honours (revoked from the Google account) unlinks them.
     *
     * @return array{token: string, expiresIn: int}|null
     */
    public function accessToken(User $user): ?array
    {
        if (! $user->youtube_token) {
            return null;
        }
        $response = Http::asForm()->timeout(20)->post(self::TOKEN, [
            'refresh_token' => $user->youtube_token,
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'grant_type' => 'refresh_token',
        ]);
        if ($response->json('error') === 'invalid_grant') {
            $user->forceFill(['youtube_token' => null, 'youtube_linked_at' => null])->save();

            return null;
        }
        if (! $response->successful() || ! $response->json('access_token')) {
            return null;
        }

        return ['token' => $response->json('access_token'), 'expiresIn' => (int) $response->json('expires_in', 3600)];
    }

    /** Revoke the link with Google and forget it. Forgotten even if Google cannot be reached. */
    public function disconnect(User $user): void
    {
        if ($user->youtube_token) {
            try {
                Http::asForm()->timeout(15)->post(self::REVOKE, ['token' => $user->youtube_token]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $user->forceFill(['youtube_token' => null, 'youtube_linked_at' => null])->save();
    }

    /**
     * The upload's title, description and tags for one of the player's games.
     *
     * @param  string|null  $started  the recording's first frame, from its timing file
     * @param  array<int, string>  $starts  each round's ARENA_MATCH_START, from the same file
     * @return array{title: string, description: string, tags: array<int, string>}|null
     */
    public function details(User $user, string $lobby, ?string $started, array $starts): ?array
    {
        $rounds = ArenaRound::where('user_id', $user->id)->where('lobby_id', $lobby)->get()
            ->sortBy(fn (ArenaRound $r) => (string) $r->played_at)->values();
        if ($rounds->isEmpty()) {
            return null;
        }
        $first = $this->analysis($rounds->first());
        $players = collect($first['players'] ?? []);
        $me = $players->firstWhere('logger', true);
        $shuffle = $rounds->count() > 1 || str_contains((string) $rounds->first()->bracket, 'Shuffle');
        $pov = $me ? $this->short($me['spec']).' POV' : null;
        $playedAt = $rounds->first()->played_at ? Carbon::parse($rounds->first()->played_at) : null;

        if ($shuffle) {
            $won = $rounds->filter(fn ($r) => $this->analysis($r)['won'] ?? false)->count();
            $record = $won.'-'.($rounds->count() - $won);
            $title = implode(' · ', array_filter(['Solo Shuffle', $pov, $record, $playedAt?->format('j M Y')]));
            $summary = "Solo Shuffle, {$record}".($me ? ', as '.$me['spec'] : '').($playedAt ? ' · '.$playedAt->format('j M Y') : '');
        } else {
            $ours = $this->comp($players->where('side', 'us'));
            $theirs = $this->comp($players->where('side', 'them'));
            $won = (bool) ($first['won'] ?? false);
            $mmr = $first['mmr']['us'] ?? null;
            // YouTube takes 100 characters: three specs a side can fill them, so the POV goes first and
            // then the MMR, rather than cutting off the result (28 Oct: "... · Disc POV ·").
            $parts = ['comp' => "{$ours} vs {$theirs}", 'mmr' => $mmr ? number_format((int) $mmr).' MMR' : null, 'pov' => $pov, 'result' => $won ? 'Win' : 'Loss'];
            foreach (['pov', 'mmr'] as $drop) {
                if (mb_strlen(implode(' · ', array_filter($parts))) > 100) {
                    $parts[$drop] = null;
                }
            }
            $title = implode(' · ', array_filter($parts));
            $summary = $this->specs($players->where('side', 'us')).' vs '.$this->specs($players->where('side', 'them'))
                .', '.($rounds->first()->bracket ?? '3v3').', '.($won ? 'won' : 'lost')
                .($playedAt ? ' · '.$playedAt->format('j M Y') : '')
                .($mmr ? "\nMMR ".number_format((int) $mmr).' against '.number_format((int) ($first['mmr']['them'] ?? 0)) : '');
        }

        $chapters = $this->chapters($rounds, $started, $starts, $shuffle);
        $description = $summary."\n\n"
            .($chapters ? implode("\n", $chapters)."\n\n" : '')
            ."Recorded and uploaded with MindCollector, a free WoW arena analysis app that reads your combat log and shows what decided each game: https://mindcollector.com/wow/app\n";

        $tags = array_values(array_unique(array_filter(array_merge(
            ['World of Warcraft', 'WoW', 'WoW Arena', 'PvP', $shuffle ? 'Solo Shuffle' : '3v3'],
            $players->pluck('spec')->all(),
        ))));

        return ['title' => mb_substr($title, 0, 100), 'description' => mb_substr($description, 0, 4900), 'tags' => $tags];
    }

    /**
     * YouTube chapters: "0:00 Gates open", then each round's start (a shuffle) and each fall,
     * placed in the video by the timing file; at least 10s apart, as YouTube needs (and three or
     * more before it draws them as chapters).
     *
     * @param  Collection<int, ArenaRound>  $rounds
     * @return array<int, string>
     */
    private function chapters(Collection $rounds, ?string $started, array $starts, bool $shuffle): array
    {
        $clock = fn (string $at) => (float) Carbon::parse($at)->format('U.v');
        $origin = $started ? $clock($started) : null;
        $precise = array_map($clock, $starts);
        $marks = [];
        foreach ($rounds as $i => $round) {
            if (! $round->played_at) {
                continue;
            }
            $at = (float) Carbon::parse($round->played_at)->getTimestamp();
            $start = collect($precise)->first(fn ($p) => $p >= $at - 0.5 && $p < $at + 1.5) ?? $at;
            $origin ??= $start;
            $base = $start - $origin;
            if ($shuffle && $i > 0) {
                $marks[] = [$base, 'Round '.($i + 1)];
            }
            $analysis = $this->analysis($round);
            $specOf = collect($analysis['players'] ?? [])->mapWithKeys(fn ($p) => [$p['guid'] => $this->short($p['spec'], true)])->all();
            foreach (app(FallsService::class)->read($analysis)['falls'] as $f) {
                $who = $specOf[$f['on']] ?? 'Someone';
                $how = $f['kind'] === 'control' ? 'control, no cooldowns' : ($f['kind'] === 'cooldown' ? 'cooldowns' : 'damage');
                $marks[] = [$base + $f['from'], $f['died'] ? "{$who} dies ({$how})" : "{$who} to {$f['low']}% ({$how})"];
            }
        }
        usort($marks, fn ($a, $b) => $a[0] <=> $b[0]);

        $out = ['0:00 Gates open'];
        $last = 0.0;
        foreach ($marks as [$t, $label]) {
            if ($t - $last < self::CHAPTER_GAP) {
                continue;
            }
            $out[] = sprintf('%d:%02d %s', intdiv((int) $t, 60), (int) $t % 60, $label);
            $last = $t;
        }

        // YouTube shows chapters from three timestamps up; fewer are still links into the video.
        return count($out) >= 2 ? $out : [];
    }

    private function analysis(ArenaRound $round): array
    {
        $p = is_string($round->payload) ? json_decode($round->payload, true) : (array) $round->payload;

        return $p['analysis'] ?? $p;
    }

    /** A team's nickname where a guide uses one (Jungle, RMD), else its specs. */
    private function comp(Collection $team): string
    {
        $healer = $team->firstWhere('healer', true);
        $dps = $team->reject(fn ($p) => ! empty($p['healer']))->pluck('classSlug')->filter()->sort()->values()->all();

        return (count($dps) === 2 ? CompLibraryService::nicknameFor(implode('+', $dps), $healer['classSlug'] ?? null) : null)
            ?? $this->specs($team);
    }

    private function specs(Collection $team): string
    {
        return $team->sortByDesc(fn ($p) => ! empty($p['healer']) ? 0 : 1)->map(fn ($p) => $this->short($p['spec'], true))->implode('/');
    }

    /** "Beast Mastery Hunter" -> "BM", or "BM Hunter" with its class. */
    public function short(string $spec, bool $withClass = false): string
    {
        foreach (['Death Knight', 'Demon Hunter', 'Druid', 'Evoker', 'Hunter', 'Mage', 'Monk', 'Paladin', 'Priest', 'Rogue', 'Shaman', 'Warlock', 'Warrior'] as $class) {
            if (str_ends_with($spec, ' '.$class)) {
                $name = substr($spec, 0, -strlen($class) - 1);
                $short = self::SHORT[$name] ?? $name;

                return $withClass ? $short.' '.$class : $short;
            }
        }

        return $spec;
    }
}
