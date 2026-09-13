<?php

namespace App\Models;

use App\Enums\FriendshipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One friendship between two players, in either state. One row per pair whichever of the two
 * asked — see the create_friendships_table migration. Created and changed only through
 * FriendshipService, which is where the one-row-per-pair rule is enforced.
 */
class Friendship extends Model
{
    protected $fillable = ['requester_id', 'addressee_id', 'status', 'accepted_at'];

    protected $casts = [
        'status' => FriendshipStatus::class,
        'accepted_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function addressee()
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    /** The row between these two players, whichever of them asked. */
    public function scopeBetween(Builder $query, int $a, int $b): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->where('requester_id', $a)->where('addressee_id', $b))
            ->orWhere(fn (Builder $w) => $w->where('requester_id', $b)->where('addressee_id', $a)));
    }

    /** Every row this player is on, whichever side. */
    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('requester_id', $userId)
            ->orWhere('addressee_id', $userId));
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', FriendshipStatus::Accepted->value);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', FriendshipStatus::Pending->value);
    }

    /** The other player on this row, from the point of view of $userId. */
    public function otherUserId(int $userId): int
    {
        return $this->requester_id === $userId ? $this->addressee_id : $this->requester_id;
    }
}
