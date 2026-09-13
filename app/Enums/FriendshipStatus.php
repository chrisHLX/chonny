<?php

namespace App\Enums;

/**
 * Where a friend request stands. Two states, because a declined request is deleted rather than
 * kept — see the create_friendships_table migration.
 *
 * Stored as a plain string column, never a DB enum.
 */
enum FriendshipStatus: string
{
    /** Sent, waiting for the other player to accept. */
    case Pending = 'pending';

    /** Both players agreed. Only this state grants anything. */
    case Accepted = 'accepted';
}
