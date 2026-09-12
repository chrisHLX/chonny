<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A MindCollector user's linked Battle.net account. See create_battlenet_tables for why no token is
 * stored and why both sides of the link are unique.
 */
class BattlenetAccount extends Model
{
    protected $fillable = [
        'user_id',
        'battlenet_id',
        'battletag',
        'characters_synced_at',
    ];

    protected $casts = [
        'characters_synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Highest level first. Exp is a tie-break applied in PHP by callers that need it (see
     * BattlenetCharacter::bestExp()) rather than here: MySQL spells a two-column max GREATEST()
     * and SQLite — which the test suite runs on — spells it MAX(), and the list is small.
     */
    public function characters()
    {
        return $this->hasMany(BattlenetCharacter::class)->orderByDesc('level')->orderBy('name');
    }

    /**
     * The account's single best arena exp across every character, or null when none has any.
     *
     * @return array{rating: int, bracket: string, character: BattlenetCharacter}|null
     */
    public function bestExp(): ?array
    {
        return $this->characters
            ->map(fn (BattlenetCharacter $c) => ($e = $c->bestExp()) ? $e + ['character' => $c] : null)
            ->filter()
            ->sortByDesc('rating')
            ->first();
    }

    /** The highest PvP season rank title earned on any character, or null. */
    public function bestRankTitle(): ?BattlenetCharacter
    {
        return $this->characters
            ->filter(fn (BattlenetCharacter $c) => $c->pvp_rank_tier !== null)
            ->sortByDesc('pvp_rank_tier')
            ->first();
    }

    /** The battletag without its #1234 discriminator, for places the full tag is noise. */
    public function battletagName(): string
    {
        return explode('#', $this->battletag)[0];
    }
}
