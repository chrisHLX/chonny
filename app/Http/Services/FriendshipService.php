<?php

namespace App\Http\Services;

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer to `friendships`. Every rule about a friend request lives here so the friends
 * page, the home page and a guide page's "add friend" button cannot disagree about any of them.
 *
 * Friendship is what grants edit access to a guide whose author allowed friends to edit (see
 * UserGuide::isEditableBy()), so each rule below is also an access rule: nobody becomes a friend
 * without the other player accepting.
 */
class FriendshipService
{
    /**
     * Find a player by their handle, as it appears in their guide URLs (/g/{username}/...).
     *
     * Deliberately by handle only, never by email. Looking an account up by email would let anyone
     * test whether an address is registered here, and a handle is what players already see on each
     * other's guides. Case-insensitive, and a leading "@" is ignored, because both are what people
     * actually type.
     */
    public function findByHandle(string $handle): ?User
    {
        $handle = ltrim(trim($handle), '@');

        if ($handle === '') {
            return null;
        }

        return User::whereRaw('LOWER(username) = ?', [mb_strtolower($handle)])->first();
    }

    /**
     * Ask to be friends. Returns a short outcome the caller shows as-is.
     *
     * If the other player already asked YOU, this accepts their request rather than sending a
     * second one the other way — two pending rows for one pair is exactly what the one-row rule
     * exists to prevent, and it is also what someone clicking "add" on a person who added them
     * means.
     *
     * @return 'self'|'already_friends'|'already_sent'|'accepted'|'sent'
     */
    public function request(User $from, User $to): string
    {
        if ($from->id === $to->id) {
            return 'self';
        }

        return DB::transaction(function () use ($from, $to) {
            $existing = Friendship::between($from->id, $to->id)->lockForUpdate()->first();

            if ($existing?->status === FriendshipStatus::Accepted) {
                return 'already_friends';
            }

            if ($existing && $existing->requester_id === $from->id) {
                return 'already_sent';
            }

            if ($existing) {
                $this->markAccepted($existing);

                return 'accepted';
            }

            Friendship::create([
                'requester_id' => $from->id,
                'addressee_id' => $to->id,
                'status' => FriendshipStatus::Pending,
            ]);

            return 'sent';
        });
    }

    /** Accept a request sent TO this player. A request they sent themselves is not theirs to accept. */
    public function accept(User $user, int $friendshipId): bool
    {
        $request = Friendship::pending()
            ->where('id', $friendshipId)
            ->where('addressee_id', $user->id)
            ->first();

        if (! $request) {
            return false;
        }

        $this->markAccepted($request);

        return true;
    }

    /**
     * Turn down a request sent to this player, or withdraw one they sent. Deleted rather than
     * marked declined, so either side can ask again later.
     */
    public function decline(User $user, int $friendshipId): bool
    {
        return Friendship::pending()
            ->where('id', $friendshipId)
            ->involving($user->id)
            ->delete() > 0;
    }

    /** End a friendship. Either player can. Edit access through it ends at the same moment. */
    public function remove(User $user, int $otherUserId): bool
    {
        return Friendship::between($user->id, $otherUserId)->delete() > 0;
    }

    private function markAccepted(Friendship $friendship): void
    {
        $friendship->forceFill([
            'status' => FriendshipStatus::Accepted,
            'accepted_at' => now(),
        ])->save();
    }
}
