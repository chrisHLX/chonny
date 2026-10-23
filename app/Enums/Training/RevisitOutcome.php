<?php

namespace App\Enums\Training;

/**
 * What the learner found when a note came back: the learning loop's sensor. It records whether
 * understanding moved, not just whether the note was read.
 */
enum RevisitOutcome: string
{
    case StillConfused = 'still_confused';
    case NowUnderstand = 'now_understand';
    case ChangedMind = 'changed_mind';
    case StillHolds = 'still_holds';

    public function label(): string
    {
        return match ($this) {
            self::StillConfused => 'Still unclear',
            self::NowUnderstand => 'Now I understand',
            self::ChangedMind => 'I changed my mind',
            self::StillHolds => 'Still holds',
        };
    }

    /**
     * The status the note moves to, and days until it returns (null: it doesn't).
     *
     * @return array{0: string, 1: ?int}
     */
    public function next(int $revisitsSoFar): array
    {
        return match ($this) {
            self::StillConfused => ['open', 2],
            self::NowUnderstand, self::ChangedMind => ['resolved', null],
            // A view that keeps holding is checked at widening intervals, then retired.
            self::StillHolds => $revisitsSoFar >= 3 ? ['resolved', null] : ['revisited', [7, 14, 30][$revisitsSoFar] ?? 30],
        };
    }
}
