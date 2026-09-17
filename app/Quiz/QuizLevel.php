<?php

namespace App\Quiz;

/** One step on a game's learning path: level 1 first, harder levels after. */
final class QuizLevel
{
    public function __construct(
        public readonly int $number,
        public readonly string $title,
        public readonly string $description,
    ) {}
}
