<?php

namespace App\Quiz;

/**
 * What a game has to provide to get quizzes. The quiz pages, attempts and grading are shared; a
 * game only decides its levels and how to build questions from its own data.
 *
 * Questions are built when an attempt starts, from the game data as it is at that moment, rather
 * than written once and stored. That is what keeps a quiz current when the game changes: a new
 * cooldown shows up in the next attempt with nothing to regenerate.
 */
interface GameQuiz
{
    /** The `game` value stored on attempts, e.g. "wow". */
    public function game(): string;

    /** @return array<int, QuizLevel> keyed by level number */
    public function levels(): array;

    /**
     * @param  string  $subject  what the quiz is about, in the game's own terms (WoW: "spec:{id}")
     * @return array<int, QuizQuestion>
     */
    public function questions(string $subject, int $level, int $count): array;
}
