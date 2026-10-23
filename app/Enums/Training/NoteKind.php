<?php

namespace App\Enums\Training;

/**
 * What a learner marked a passage as. The kind decides when the note comes back for a revisit:
 * confusion and questions return soonest, because that is where understanding is least settled.
 */
enum NoteKind: string
{
    case Highlight = 'highlight';
    case Note = 'note';
    case Question = 'question';
    case Confusing = 'confusing';
    case Disagree = 'disagree';
    case Apply = 'apply';

    public function label(): string
    {
        return match ($this) {
            self::Highlight => 'Highlight',
            self::Note => 'Note',
            self::Question => 'Question',
            self::Confusing => 'Confusing',
            self::Disagree => 'Disagree',
            self::Apply => 'Apply it',
        };
    }

    public function prompt(): string
    {
        return match ($this) {
            self::Highlight => '',
            self::Note => 'What do you want to remember about this?',
            self::Question => 'What do you want to find out?',
            self::Confusing => 'Which part doesn\'t make sense yet?',
            self::Disagree => 'What do you think is wrong, and why?',
            self::Apply => 'Where would this apply in your own games or work?',
        };
    }

    /** Days until the note comes back for a revisit. A plain highlight never does. */
    public function revisitAfterDays(): ?int
    {
        return match ($this) {
            self::Highlight => null,
            self::Question, self::Confusing => 1,
            self::Disagree => 3,
            self::Note, self::Apply => 7,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $k) => [$k->value => $k->label()])->all();
    }
}
