<?php

namespace App\Quiz;

/**
 * One multiple-choice question, game-neutral.
 *
 * Each option is ['key' => string, 'label' => string, 'icon' => ?string]. `icon` is a path under
 * /storage, so any game can show pictures without the view knowing whose they are.
 */
final class QuizQuestion
{
    /**
     * @param  array{label: string, icon: ?string}|null  $subject  what the question is about, shown above the options
     * @param  array<int, array{key: string, label: string, icon: ?string}>  $options
     */
    public function __construct(
        public readonly string $type,
        public readonly string $prompt,
        public readonly ?array $subject,
        public readonly array $options,
        public readonly string $correctKey,
        public readonly string $explanation,
    ) {}

    public function isCorrect(string $key): bool
    {
        return $key === $this->correctKey;
    }

    public function hasOption(string $key): bool
    {
        return collect($this->options)->contains('key', $key);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'prompt' => $this->prompt,
            'subject' => $this->subject,
            'options' => $this->options,
            'correct' => $this->correctKey,
            'explanation' => $this->explanation,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['type'], $data['prompt'], $data['subject'] ?? null, $data['options'], $data['correct'], $data['explanation']);
    }
}
