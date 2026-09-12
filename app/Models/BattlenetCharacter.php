<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One WoW character on a linked Battle.net account.
 *
 * The list-level fields (name, realm, class, level) come from the account's own character list at
 * link time. Everything else — exp, ratings, gear, talents — is filled by
 * BattlenetCharacterSyncService from Blizzard's public character endpoints, and `synced_at` stays
 * null until that has happened at least once.
 */
class BattlenetCharacter extends Model
{
    protected $fillable = [
        'battlenet_account_id',
        'region',
        'blizzard_character_id',
        'name',
        'realm_slug',
        'realm_name',
        'class_id',
        'spec_id',
        'race',
        'faction',
        'level',
        'item_level',
        'achievement_points',
        'exp_2v2',
        'exp_3v3',
        'pvp_rank_title',
        'pvp_rank_tier',
        'arenas_played',
        'arenas_won',
        'ratings',
        'talents',
        'equipment',
        'last_login_at',
        'synced_at',
        'sync_error',
    ];

    protected $casts = [
        'ratings' => 'array',
        'talents' => 'array',
        'equipment' => 'array',
        'last_login_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(BattlenetAccount::class, 'battlenet_account_id');
    }

    public function gameClass()
    {
        return $this->belongsTo(GameClass::class, 'class_id');
    }

    /** The spec the character was last logged out in. */
    public function specialization()
    {
        return $this->belongsTo(Specialization::class, 'spec_id');
    }

    public function guides()
    {
        return $this->hasMany(UserGuide::class);
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->account?->user_id === $user->id;
    }

    /** "Name-Realm", the form players write a character in. */
    public function fullName(): string
    {
        return $this->name.'-'.str_replace(' ', '', $this->realm_name);
    }

    /**
     * The character's best lifetime arena exp — the higher of its 2v2 and 3v3 highest personal
     * ratings — or null when it has never played rated arena.
     *
     * @return array{rating: int, bracket: string}|null
     */
    public function bestExp(): ?array
    {
        $best = collect(['3v3' => $this->exp_3v3, '2v2' => $this->exp_2v2])
            ->filter(fn ($r) => $r > 0)
            ->sortDesc();

        return $best->isEmpty() ? null : ['rating' => (int) $best->first(), 'bracket' => (string) $best->keys()->first()];
    }

    /** The rank title without its season ("Rival II: Midnight Season 1" -> "Rival II"). */
    public function rankTitleShort(): ?string
    {
        return $this->pvp_rank_title ? trim(explode(':', $this->pvp_rank_title)[0]) : null;
    }

    /**
     * This season's ratings, best first. A bracket at 0 is dropped even when it has games in it
     * (a real case: one Blitz game, lost, rated 0) — Blizzard's number is truthful, but a "0"
     * beside a player's name reads as broken data rather than as "has not climbed yet".
     */
    public function currentRatings(): array
    {
        return collect($this->ratings ?? [])
            ->filter(fn ($r) => ($r['current'] ?? false) && ($r['rating'] ?? 0) > 0)
            ->sortByDesc('rating')
            ->values()
            ->all();
    }

    /** The active spec's talent snapshot, or the first one on file. */
    public function activeTalents(): ?array
    {
        $all = collect($this->talents ?? []);

        return $all->firstWhere('active', true) ?? $all->first();
    }

    public function isSynced(): bool
    {
        return $this->synced_at !== null;
    }
}
