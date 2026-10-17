<?php

namespace App\Quiz\Sc2;

/**
 * One unit or building as the SC2 quizzes see it (Sc2TechTree builds these).
 *
 * Costs are what the player pays for this step: a morph's cost leaves out the unit it morphs from
 * (a Baneling is 25/25, not 50/25), and a Zerg building's leaves out the Drone that becomes it.
 * That is how the game shows a price on the button, and what a player is asked to know.
 */
final class Sc2Unit
{
    /**
     * @param  array<int, string>  $attributes  the game's own: Light, Armored, Biological, Mechanical...
     * @param  ?string  $targets  'ground', 'air', 'both', or null when the data has no weapon to say
     * @param  ?string  $madeAt  the building that makes it (label), or 'Larva', or null
     * @param  ?string  $morphsFrom  the unit or building it morphs from (label)
     * @param  ?string  $needs  the building that must exist first (label), never a town hall
     * @param  ?string  $needsAddon  'Tech Lab' when the producer needs one
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $race,
        public readonly bool $structure,
        public readonly int $minerals,
        public readonly int $gas,
        public readonly ?float $supply,
        public readonly ?int $supplyProvided,
        public readonly ?int $seconds,
        public readonly array $attributes,
        public readonly ?string $targets,
        public readonly ?string $madeAt,
        public readonly ?string $morphsFrom,
        public readonly ?string $needs,
        public readonly ?string $needsAddon,
        public readonly bool $fromDrone = false,
        public readonly bool $worker = false,
    ) {}

    public function cost(): string
    {
        return $this->gas > 0 ? "{$this->minerals} / {$this->gas}" : "{$this->minerals} / 0";
    }

    public function costWords(): string
    {
        return $this->gas > 0 ? "{$this->minerals} minerals and {$this->gas} gas" : "{$this->minerals} minerals";
    }

    /** 'Light', 'Armored', or null when it is neither. */
    public function weight(): ?string
    {
        return in_array('Armored', $this->attributes, true) ? 'Armored' : (in_array('Light', $this->attributes, true) ? 'Light' : null);
    }
}
