<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Http\Services\CreditService;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
                'consecutive_fails'
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

    public function cards()
    {
        return $this->hasMany(Card::class);
    }

    public function axisMastery()
    {
        return $this->hasMany(UserAxisMastery::class);
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
