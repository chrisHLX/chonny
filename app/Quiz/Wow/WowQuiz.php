<?php

namespace App\Quiz\Wow;

use App\Models\Specialization;
use App\Quiz\GameQuiz;
use App\Quiz\QuizLevel;

/**
 * WoW's quizzes: one set of levels per spec, built from that spec's live kit.
 *
 * The subject is "spec:{id}". Levels are the start of a path from a new player to Gladiator; later
 * levels (the enemy's kit, counters, matchup plans from guides) add question types here.
 */
class WowQuiz implements GameQuiz
{
    public function __construct(private readonly WowAbilityFacts $facts) {}

    public function game(): string
    {
        return 'wow';
    }

    public function levels(): array
    {
        return [
            1 => new QuizLevel(1, 'Know your kit', 'Recognise your abilities and what each one is for.'),
            2 => new QuizLevel(2, 'Your cooldowns', 'Your offensive and defensive cooldowns, and how long they take to come back.'),
            3 => new QuizLevel(3, 'Crowd control', 'Which diminishing returns group your crowd control is in, and what shares it.'),
        ];
    }

    public static function subjectFor(Specialization $spec): string
    {
        return "spec:{$spec->id}";
    }

    public function questions(string $subject, int $level, int $count): array
    {
        $spec = str_starts_with($subject, 'spec:')
            ? Specialization::with('gameClass')->find((int) substr($subject, 5))
            : null;

        if (! $spec || ! isset($this->levels()[$level])) {
            return [];
        }

        return (new WowQuestionBuilder(
            specLabel: trim("{$spec->name} {$spec->gameClass?->name}"),
            abilities: $this->facts->specAbilities($spec),
            others: $level === 1 ? $this->facts->otherClassAbilities($spec) : [],
            ccPool: $level === 3 ? $this->facts->ccPool() : [],
        ))->build($level, $count);
    }
}
