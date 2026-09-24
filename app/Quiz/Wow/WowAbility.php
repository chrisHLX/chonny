<?php

namespace App\Quiz\Wow;

/**
 * The facts a quiz question can ask about one ability. Plain data, so the question builder never
 * touches the database and can be tested with made-up abilities.
 */
final class WowAbility
{
    /**
     * @param  ?float  $pvpDuration  how long it lasts on a PLAYER (spells.pvp_duration_seconds),
     *                               which is routinely shorter than the PvE duration. Null when
     *                               the data holds no arena figure — never substituted with the
     *                               PvE one, because that substitution is exactly the mistake the
     *                               stored question bank made (docs/learning/question-audit-2026-09-24.md).
     * @param  array<int, string>  $usableWhileCc  the CC states this can still be cast under, as
     *                                             spells.usable_while_cc tokens. Empty means the
     *                                             spell carries none of Blizzard's "Allow While …"
     *                                             attributes — a real no, not a gap, because
     *                                             SpellDataFileParser reads every spell's whole
     *                                             attribute line on every import.
     */
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
        public readonly ?float $pvpDuration = null,
        public readonly array $usableWhileCc = [],
    ) {}

    public function usableWhile(string $token): bool
    {
        return in_array($token, $this->usableWhileCc, true);
    }

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
