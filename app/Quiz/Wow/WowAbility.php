<?php

namespace App\Quiz\Wow;

/**
 * The facts a quiz question can ask about one ability. Plain data, so the question builder never
 * touches the database and can be tested with made-up abilities.
 */
final class WowAbility
{
    public function __construct(
        public readonly int $spellId,
        public readonly string $name,
        public readonly ?string $icon = null,
        public readonly ?string $drCategory = null,
        public readonly ?float $cooldown = null,
        public readonly bool $offensive = false,
        public readonly bool $defensive = false,
        public readonly bool $interrupt = false,
        public readonly ?string $className = null,
    ) {}

    public function iconPath(): ?string
    {
        return $this->icon ? '/storage/spell-icons/'.$this->icon : null;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
