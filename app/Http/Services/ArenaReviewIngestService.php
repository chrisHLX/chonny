<?php

namespace App\Http\Services;

use App\Models\ArenaReview;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takes one arena round uploaded from a player's browser, derives it, and re-assembles the review
 * over whatever rounds of that lobby have arrived so far.
 *
 * WHY THE BROWSER SENDS ROUNDS AND NOT THE LOG. A session's `WoWCombatLog.txt` is 64MB after one
 * evening and up to 250MB in this archive. Production's nginx sets no `client_max_body_size` for
 * this site, so it is on the 1MB default, PHP is at `upload_max_filesize=2M`, and
 * `max_execution_time` is 30 seconds on a single vCPU. Posting the file is not possible and
 * raising all three to make it possible would still mean parsing 250MB inside a web request. So
 * the browser does the one cheap thing it is well placed to do — scan for
 * ARENA_MATCH_START..ARENA_MATCH_END and send those slices, a few hundred KB each — and every
 * piece of interpretation stays here, server side, where it can be fixed without redistributing
 * anything.
 *
 * THE CLIENT IS DELIBERATELY DUMB. It finds boundaries; it does not parse. Field offsets, the
 * feign-death filter, the swing de-duplication, team derivation — all of it has been wrong at
 * least once already and all of it lives in PHP. A client that understood the log would have to be
 * shipped again every time one of those is corrected.
 *
 * THE RAW LOG IS NOT KEPT. A round is derived on arrival and the uploaded text is discarded; what
 * persists is `arena_rounds.payload`, which holds the metadata, the throughput and each player's
 * COMBATANT_INFO. That is enough to re-assemble a review after a parser fix without asking anyone
 * to upload again, and it means the site is not storing other people's combat logs indefinitely.
 *
 * OWNERSHIP IS NOT TAKEN ON TRUST. A round is stored against the uploading user, and the review's
 * character link is derived from the log's own `affiliation 1` player — the character that wrote
 * the log — matched against that account's synced Battle.net characters. The client never says
 * whose game it is.
 */
class ArenaReviewIngestService
{
    /**
     * A round's slice of a combat log, once decompressed. Generous next to the ~2MB a 260-second
     * round actually produces, and a hard stop on a request that is not a round at all.
     */
    public const MAX_ROUND_BYTES = 24 * 1024 * 1024;

    /**
     * How far apart two rounds of one lobby can be. Six rounds plus their ~35s intermissions run
     * about fifteen minutes, and the longest single round measured was 262 seconds, so half an hour
     * either side of a round covers a whole lobby comfortably while staying far too tight for the
     * same six players to be re-drawn on the same map by coincidence.
     */
    private const LOBBY_WINDOW_SECONDS = 1800;

    public function __construct(private LobbyReviewService $reviews) {}

    /**
     * Ingests one round's raw log text for a player.
     *
     * @return array{status: string, lobbyId?: string, sequence?: int, matchId?: string, review?: ArenaReview, reason?: string}
     */
    public function ingestRound(User $user, string $rawRound): array
    {
        if (strlen($rawRound) > self::MAX_ROUND_BYTES) {
            return ['status' => 'rejected', 'reason' => 'That round is larger than a round can be.'];
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($rawRound)) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => $l !== ''));

        if ($lines === []) {
            return ['status' => 'rejected', 'reason' => 'Empty upload.'];
        }

        $derived = $this->reviews->deriveRound($lines);

        if ($derived === null) {
            return ['status' => 'skipped', 'reason' => 'Not a Solo Shuffle round.'];
        }

        $meta = $derived['metadata'];

        // Without COMBATANT_INFO there are no specs, so nothing downstream can use the round.
        // Advanced Combat Logging being off is the usual cause, and it is worth saying so.
        $withSpec = array_filter($meta['units'], fn ($u) => ($u['spec'] ?? '0') !== '0');

        if ($withSpec === []) {
            return [
                'status' => 'skipped',
                'reason' => 'No COMBATANT_INFO — turn on Advanced Combat Logging (System > Network).',
            ];
        }

        $playedAt = isset($meta['startTime'])
            ? date('Y-m-d H:i:s', (int) ($meta['startTime'] / 1000))
            : null;

        $rosterKey = $this->rosterKey($meta);
        $lobbyId = $this->lobbyIdFor($user, $rosterKey, $playedAt, $meta['id']);

        ArenaRound::updateOrCreate(
            ['user_id' => $user->id, 'match_id' => $meta['id']],
            [
                'lobby_id' => $lobbyId,
                'roster_key' => $rosterKey,
                'sequence' => $meta['sequenceNumber'] ?? 1,
                'bracket' => $meta['startInfo']['bracket'] ?? 'unknown',
                'played_at' => $playedAt,
                'payload' => $derived,
            ]
        );

        return [
            'status' => 'stored',
            'lobbyId' => $lobbyId,
            'matchId' => $meta['id'],
        ];
    }

    /**
     * The six players and the arena, hashed — what makes two uploaded rounds recognisable as the
     * same lobby.
     *
     * Player GUIDs, not names: a name is a display string and a GUID is not. Sorted, because the
     * roster order in COMBATANT_INFO follows that round's teams, which are re-dealt every round.
     */
    private function rosterKey(array $meta): string
    {
        $players = [];

        foreach ($meta['units'] as $unit) {
            if (str_starts_with($unit['id'], 'Player-') && ($unit['spec'] ?? '0') !== '0') {
                $players[] = $unit['id'];
            }
        }

        sort($players);

        return substr(md5(($meta['startInfo']['zoneId'] ?? '').'|'.implode(',', $players)), 0, 32);
    }

    /**
     * The lobby an uploaded round joins: an existing one with the same roster close enough in time,
     * or a new one named after this round.
     *
     * The window is generous against a lobby's real length — six rounds plus intermissions runs
     * about fifteen minutes — and still far too tight for the same six players to be re-drawn
     * against each other on the same map by coincidence. Without a time bound at all, two genuinely
     * separate lobbies with an identical roster would be welded into one twelve-round game.
     */
    private function lobbyIdFor(User $user, string $rosterKey, ?string $playedAt, string $matchId): string
    {
        $near = ArenaRound::query()
            ->where('user_id', $user->id)
            ->where('roster_key', $rosterKey);

        if ($playedAt !== null) {
            $near->whereBetween('played_at', [
                date('Y-m-d H:i:s', strtotime($playedAt) - self::LOBBY_WINDOW_SECONDS),
                date('Y-m-d H:i:s', strtotime($playedAt) + self::LOBBY_WINDOW_SECONDS),
            ]);
        }

        return $near->value('lobby_id') ?? $matchId;
    }

    /**
     * Re-assembles the review for one lobby from every round of it this player has uploaded.
     *
     * Called after a batch rather than after each round: a lobby's six rounds arrive as six
     * requests, and assembling on every one of them would do the work six times and briefly show
     * a review that says 1-0.
     */
    public function assembleLobby(User $user, string $lobbyId): ?ArenaReview
    {
        $rounds = ArenaRound::query()
            ->where('user_id', $user->id)
            ->where('lobby_id', $lobbyId)
            ->orderBy('played_at')
            ->orderBy('id')
            ->get();

        if ($rounds->isEmpty()) {
            return null;
        }

        // An uploaded round has no idea which round of the lobby it was — it was derived on its own
        // and every one of them says 1. Order decides: earliest start is round one. Written back
        // onto the round rows too, so the numbering is visible and not re-derived differently by
        // some later reader.
        $payloads = [];

        foreach ($rounds->values() as $i => $round) {
            $payload = $round->payload;
            $payload['metadata']['sequenceNumber'] = $i + 1;
            $payloads[] = $payload;

            if ($round->sequence !== $i + 1) {
                $round->update(['sequence' => $i + 1]);
            }
        }

        $review = $this->reviews->assemble($lobbyId, $payloads);

        if ($review === null) {
            return null;
        }

        return DB::transaction(fn () => $this->reviews->store($user, $review));
    }

    /**
     * Every lobby this player has rounds for but whose review is missing or out of date — used to
     * finish an upload, and to rebuild after a parser fix.
     *
     * @return array<int, string>
     */
    public function lobbiesNeedingAssembly(User $user): array
    {
        $roundLobbies = ArenaRound::query()
            ->where('user_id', $user->id)
            ->selectRaw('lobby_id, COUNT(*) as round_count')
            ->groupBy('lobby_id')
            ->pluck('round_count', 'lobby_id');

        $reviewed = ArenaReview::query()
            ->where('user_id', $user->id)
            ->pluck('rounds', 'lobby_id');

        return $roundLobbies
            ->reject(fn ($count, $lobbyId) => ($reviewed[$lobbyId] ?? null) === (int) $count)
            ->keys()
            ->all();
    }
}
