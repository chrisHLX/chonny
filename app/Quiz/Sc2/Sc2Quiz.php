<?php

namespace App\Quiz\Sc2;

use App\Quiz\GameQuiz;
use App\Quiz\QuizLevel;

/**
 * StarCraft II's quizzes (2026-10-18): one set of levels per race, built from the game's own unit
 * data (Sc2TechTree) each time a quiz is dealt. Subjects are `race:terran`, `race:protoss`,
 * `race:zerg`.
 */
class Sc2Quiz implements GameQuiz
{
    public function __construct(private readonly Sc2TechTree $tree) {}

    public function game(): string
    {
        return 'sc2';
    }

    public function levels(): array
    {
        return [
            1 => new QuizLevel(1, 'What it costs', 'The minerals and gas for every unit and building, and how much supply your supply buildings give.'),
            2 => new QuizLevel(2, 'Supply and time', 'How much supply each unit takes, and how long things take to build.'),
            3 => new QuizLevel(3, 'The tech tree', 'Where each unit comes from, and which building unlocks it.'),
            4 => new QuizLevel(4, 'In a fight', 'Light or Armored, and what each attack can hit.'),
        ];
    }

    public static function subjectFor(string $race): string
    {
        return "race:{$race}";
    }

    public function questions(string $subject, int $level, int $count): array
    {
        $race = str_starts_with($subject, 'race:') ? substr($subject, 5) : null;

        if ($race === null || ! isset($this->levels()[$level])) {
            return [];
        }

        return (new Sc2QuestionBuilder($race, $this->tree->race($race)))->build($level, $count);
    }
}
