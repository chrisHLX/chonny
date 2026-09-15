<?php

namespace App\Models;

use App\Http\Services\BattlenetCharacterSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
        'arena_titles',
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
        'arena_titles' => 'array',
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
     * The best season rank from ANY bracket is worth showing only when the bracket titles do not
     * already say it: a Gladiator or Legend is exactly what bracketTitles() shows, while an Elite
     * or a Duelist — which no achievement can tie to a bracket — has nowhere else to appear.
     */
    public function showsOverallRank(): bool
    {
        return $this->pvp_rank_title !== null
            && ! in_array($this->rankTitleShort(), array_values(BattlenetCharacterSyncService::BRACKET_TITLE_WORDS), true);
    }

    /**
     * The highest title in 3v3 and in Solo Shuffle.
     *
     * Each is the account's lifetime title in that bracket (Rank 1, then Gladiator / Legend) when
     * it has one. Otherwise it is Blizzard's rank for the character in that bracket THIS season,
     * and says so — every rank below Gladiator/Legend is earned from any bracket, so no lifetime
     * achievement can be tied to 3v3 or to Shuffle. For Shuffle, which is rated per spec, the
     * character's best spec this season.
     *
     * `score` orders entries across characters (see BattlenetAccount::bestBracketTitles()).
     *
     * @return list<array{bracket: string, title: string, count: int, rank_one: bool, this_season: bool, spec: ?string, tooltip: string, score: int}>
     */
    public function bracketTitles(): array
    {
        $out = [];
        $account = 'Blizzard shares PvP season achievements across a Battle.net account, so this can come from any character on it.';

        foreach (['3v3' => '3v3', 'shuffle' => 'Solo Shuffle'] as $key => $bracket) {
            if ($t = $this->arena_titles[$key] ?? null) {
                $word = BattlenetCharacterSyncService::BRACKET_TITLE_WORDS[$key];
                $count = $t['rank_one'] ? (int) $t['rank_one_seasons'] : (int) $t['seasons'];
                $when = $t['season'] ? " in {$t['season']}" : '';

                $out[] = [
                    'bracket' => $bracket,
                    'title' => $t['title'],
                    'count' => $count,
                    'rank_one' => (bool) $t['rank_one'],
                    'this_season' => false,
                    'spec' => null,
                    'tooltip' => ($t['rank_one']
                        ? "Rank 1 in {$bracket}{$when}. Rank 1 in {$t['rank_one_seasons']} ".Str::plural('season', (int) $t['rank_one_seasons'])."; {$word} in {$t['seasons']}."
                        : "{$word}{$when}. Earned in {$t['seasons']} ".Str::plural('season', (int) $t['seasons']).'.').' '.$account,
                    'score' => ($t['rank_one'] ? 3000 : 2000) + $count,
                ];

                continue;
            }

            $season = collect($this->currentRatings())
                ->where('label', $bracket)
                ->filter(fn ($r) => ! empty($r['tier']))
                ->sortByDesc(fn ($r) => BattlenetCharacterSyncService::RANK_TIERS[$r['tier']] ?? 0)
                ->first();

            if ($season) {
                $spec = $key === 'shuffle' ? ($season['spec_name'] ?? null) : null;

                $out[] = [
                    'bracket' => $bracket,
                    'title' => $season['tier'],
                    'count' => 1,
                    'rank_one' => false,
                    'this_season' => true,
                    'spec' => $spec,
                    'tooltip' => "Blizzard's {$bracket} rank for this character this season".($spec ? " ({$spec})" : '').'.',
                    'score' => 1000 + (BattlenetCharacterSyncService::RANK_TIERS[$season['tier']] ?? 0),
                ];
            }
        }

        return $out;
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
