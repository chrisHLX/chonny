<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;

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
        ];
    }

    public function modules()
    {
        return $this->belongsToMany(Module::class)->withPivot(['status', 'score', 'current_difficulty', 'last_activity_at', 'completed_at'])->withTimestamps();
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


}
