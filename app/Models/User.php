<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Http\Services\CreditService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    // Attribution account for AI-generated content with no human author (seeded
    // modules, AI-only pipelines). Real pro-author accounts get their own row —
    // this is only a placeholder for "nobody specific wrote this."
    const SYSTEM_ENGINE_EMAIL = 'engine@mindcollector.internal';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tos_accepted_at',
        'tos_version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'tos_accepted_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function modules()
    {
        return $this->belongsToMany(Module::class)->withPivot(['status', 'score', 'current_difficulty', 'last_activity_at', 'completed_at', 'retake_started_at', 'retake_count', 'diagnostic_profile'])->withTimestamps();
    }

    public function answeredQuestions()
    {
        return $this->belongsToMany(Question::class)
            ->withPivot([
                'attempts',
                'correct_count',
                'last_answered_at',
                'total_time_spent',
                'last_time_spent',
                'last_answer',
                'last_answer_correct',
                'consecutive_fails',
            ])
            ->withTimestamps();
    }

    // User.php
    public function conceptMastery()
    {
        return $this->hasMany(UserConceptMastery::class);
    }

    public function credits()
    {
        return $this->hasOne(UserCredit::class);
    }

    public function proficiencies()
    {
        return $this->belongsToMany(Proficiency::class, 'user_proficiency')->withPivot('progress');
    }

    public function pipelines()
    {
        return $this->hasMany(Pipeline::class);
    }

    public function flaggedQuestions()
    {
        return $this->belongsToMany(Question::class, 'question_user_flags')->withTimestamps();
    }

    public function subjectContext()
    {
        return $this->hasMany(UserSubjectContext::class);
    }

    public function talentBuilds()
    {
        return $this->hasMany(TalentBuild::class);
    }

    public function axisMastery()
    {
        return $this->hasMany(UserAxisMastery::class);
    }

    /** Guides this user has authored, drafts included. */
    /** Guilds this user belongs to, including ones they own. */
    public function guilds()
    {
        return $this->belongsToMany(Guild::class)->withPivot('role')->withTimestamps()->orderBy('name');
    }

    /** Guilds this user created. */
    public function ownedGuilds()
    {
        return $this->hasMany(Guild::class, 'owner_id');
    }

    public function guides()
    {
        return $this->hasMany(UserGuide::class);
    }

    /** The linked Battle.net account, if any — see create_battlenet_tables. */
    public function battlenetAccount()
    {
        return $this->hasOne(BattlenetAccount::class);
    }

    /** Every character on the linked Battle.net account. */
    public function battlenetCharacters()
    {
        return $this->hasManyThrough(BattlenetCharacter::class, BattlenetAccount::class);
    }

    /** Private guides other people have explicitly shared with this user. */
    public function sharedGuides()
    {
        return $this->belongsToMany(UserGuide::class, 'user_guide_viewers')->withTimestamps();
    }

    /**
     * Ids of every player this one is friends with (accepted only — a pending request grants
     * nothing). Memoised on the instance because the nav, the home page and every guide permission
     * check on a page all ask it, and a friendship does not change mid-request except through
     * FriendshipService, which callers follow with a fresh model or forgetFriendCache().
     */
    public function friendIds(): \Illuminate\Support\Collection
    {
        return $this->friendIdsMemo ??= Friendship::accepted()
            ->involving($this->id)
            ->get(['requester_id', 'addressee_id'])
            ->map(fn (Friendship $f) => $f->otherUserId($this->id))
            ->values();
    }

    private ?\Illuminate\Support\Collection $friendIdsMemo = null;

    private ?int $pendingFriendRequestsMemo = null;

    public function forgetFriendCache(): void
    {
        $this->friendIdsMemo = null;
        $this->pendingFriendRequestsMemo = null;
    }

    /** Accepted friends, as users, in name order. */
    public function friends()
    {
        return static::whereIn('id', $this->friendIds())->orderBy('name');
    }

    public function isFriendsWith(?User $other): bool
    {
        return $other !== null && $other->id !== $this->id && $this->friendIds()->contains($other->id);
    }

    /** Requests other players have sent to this one and are waiting on. */
    public function incomingFriendRequests()
    {
        return Friendship::pending()->where('addressee_id', $this->id)->with('requester')->latest();
    }

    /** How many requests are waiting — the nav badge. Memoised for the same reason as friendIds(). */
    public function pendingFriendRequestCount(): int
    {
        return $this->pendingFriendRequestsMemo ??= Friendship::pending()->where('addressee_id', $this->id)->count();
    }

    /**
     * How this player is named to other players: their handle, which is also what a friend types
     * to add them. Falls back to the display name only for an account that has never needed a
     * handle yet (see resolveUsername()).
     */
    public function handle(): string
    {
        return $this->username ?: ($this->name ?: 'player');
    }

    /**
     * This account's public handle, assigning one on first use if it has none.
     *
     * Every account predates the username column and no signup step collects one yet (see the
     * add_username_to_users migration), so a handle is derived from the display name the first
     * time something actually needs it — publishing a guide — rather than blocking on a profile
     * step nobody has been asked to complete. Uniqueness is resolved by suffixing, the same way
     * guide slugs are.
     */
    public function resolveUsername(): string
    {
        if (filled($this->username)) {
            return $this->username;
        }

        $base = \Illuminate\Support\Str::slug($this->name ?? '') ?: 'player';
        $candidate = $base;
        $n = 1;

        while (static::where('username', $candidate)->whereKeyNot($this->id)->exists()) {
            $candidate = $base.'-'.$n++;
        }

        $this->forceFill(['username' => $candidate])->save();

        return $candidate;
    }

    /**
     * Change this account's handle. The old one is kept as a redirect so shared guide links keep
     * working, and stays reserved to this player; taking an old handle back removes that record.
     * Validation (format, reserved, taken) is UsernameUpdateRequest's job, not this method's.
     */
    public function changeUsername(string $username): void
    {
        $old = $this->username;

        if ($old === $username) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($old, $username) {
            PreviousUsername::where('user_id', $this->id)->where('username', $username)->delete();

            if (filled($old)) {
                PreviousUsername::firstOrCreate(['username' => $old], ['user_id' => $this->id]);
            }

            $this->forceFill(['username' => $username])->save();
        });
    }

    public function previousUsernames(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PreviousUsername::class);
    }

    public function hasVerifiedEmail(): bool
    {
        if (! app()->isProduction()) {
            return true;
        }

        return parent::hasVerifiedEmail();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\QueuedVerifyEmail);
    }

    /** Queued for the same reason as the verification email — see QueuedResetPassword. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\QueuedResetPassword($token));
    }

    protected static function booted()
    {
        static::created(function ($user) {
            // Resolve CreditService from the container
            $creditService = app(CreditService::class);

            $creditService->addAiCredits(
                $user->id,
                50,
                'Welcome signup credits'
            );
        });
    }
}
