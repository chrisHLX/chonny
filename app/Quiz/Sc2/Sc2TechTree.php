<?php

namespace App\Quiz\Sc2;

/**
 * StarCraft II's units and buildings, from the game's own data (data/sc2/techtree.json) narrowed
 * and corrected by data/sc2/roster.json. Both files are committed: nothing here reads the game
 * or the network at runtime (rule 14's reasoning).
 *
 * WHAT IS DERIVED, AND HOW. The tech tree's units each list the abilities they have; an ability
 * that trains, builds or morphs names what it produces and what it needs first. So "what makes a
 * Marauder" is read off the Barracks, and "what does it need" off that same ability's
 * requirements (a Tech Lab). Nothing about which building makes what is written by hand, except
 * the one morph the data leaves out (the Baneling, in roster.json).
 *
 * TIMES are game loops at 22.4 a second, the Faster speed ladder is played at, rounded to whole
 * seconds as the game shows them.
 *
 * DATA VERSION. The extraction is 5.0.14 and the game is 5.0.17; roster.json's `corrections` are
 * the differences that matter to a quiz, each from Blizzard's own notes. A field no correction
 * covers and that a later patch may have touched is left out instead (`noTime`).
 */
class Sc2TechTree
{
    public const TECHTREE = 'data/sc2/techtree.json';

    public const ROSTER = 'data/sc2/roster.json';

    /** Game loops per second at Faster speed. */
    private const LOOPS_PER_SECOND = 22.4;

    /** Town halls every base already has: a requirement on one of these is not a question. */
    private const BASE_HALLS = ['CommandCenter', 'Nexus', 'Hatchery'];

    private const WORKERS = ['SCV', 'Probe', 'Drone'];

    /** @var array<string, array<int, Sc2Unit>>|null race => units and buildings */
    private ?array $races = null;

    private ?array $roster = null;

    public function __construct(private readonly ?string $root = null) {}

    /** @return array<string, string> race slug => name */
    public function raceNames(): array
    {
        return array_map(fn (array $r) => $r['name'], $this->roster()['races']);
    }

    /** @return array<int, Sc2Unit> */
    public function race(string $race): array
    {
        return $this->all()[$race] ?? [];
    }

    public function gameVersion(): string
    {
        return (string) ($this->roster()['gameVersion'] ?? '');
    }

    public function extractedVersion(): string
    {
        return (string) ($this->roster()['extractedVersion'] ?? '');
    }

    /** @return array<int, array{name: string, patch: string, source: string, note: string}> */
    public function corrections(): array
    {
        return array_map(fn (array $c) => $c + ['label' => $this->label($c['name'])], $this->roster()['corrections'] ?? []);
    }

    /** @return array<string, array<int, Sc2Unit>> */
    public function all(): array
    {
        return $this->races ??= $this->build();
    }

    private function roster(): array
    {
        return $this->roster ??= json_decode((string) file_get_contents($this->path(self::ROSTER)), true) ?: ['races' => []];
    }

    private function path(string $relative): string
    {
        return $this->root ? rtrim($this->root, '/\\').'/'.$relative : base_path($relative);
    }

    private function label(string $name): string
    {
        return $this->roster()['labels'][$name] ?? trim(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name));
    }

    /** @return array<string, array<int, Sc2Unit>> */
    private function build(): array
    {
        $tree = json_decode((string) @file_get_contents($this->path(self::TECHTREE)), true);
        if (! is_array($tree) || empty($tree['Unit'])) {
            return [];
        }

        $roster = $this->roster();
        $byId = [];
        $byName = [];
        foreach ($tree['Unit'] as $u) {
            $byId[$u['id']] = $u;
            $byName[$u['name']] ??= $u;
        }
        $abilities = [];
        foreach ($tree['Ability'] ?? [] as $a) {
            $abilities[$a['id']] = $a;
        }

        $inRoster = [];
        foreach ($roster['races'] as $race) {
            foreach ([...$race['units'], ...$race['structures']] as $name) {
                $inRoster[$name] = true;
            }
        }

        // Who makes what: produced unit id => [[maker name, kind, requirements], ...], only from
        // makers a player presses (roster units and buildings, and Larva).
        $makers = [];
        foreach ($tree['Unit'] as $maker) {
            if (! isset($inRoster[$maker['name']]) && $maker['name'] !== 'Larva') {
                continue;
            }
            foreach ($maker['abilities'] ?? [] as $entry) {
                $target = $abilities[$entry['ability']]['target'] ?? null;
                if (! is_array($target)) {
                    continue;
                }
                $kind = array_key_first($target);
                $produces = $target[$kind]['produces'] ?? null;
                if ($produces) {
                    $makers[$produces][] = [$maker['name'], $kind, $entry['requirements'] ?? []];
                }
            }
        }

        $corrections = [];
        foreach ($roster['corrections'] ?? [] as $c) {
            $corrections[$c['name']] = $c;
        }
        $noTime = array_flip($roster['noTime'] ?? []);
        $noTargets = array_flip($roster['noTargets'] ?? []);

        $out = [];
        foreach ($roster['races'] as $slug => $race) {
            foreach (['units' => false, 'structures' => true] as $list => $structure) {
                foreach ($race[$list] as $name) {
                    $raw = $byName[$name] ?? null;
                    if ($raw === null) {
                        continue;
                    }
                    $out[$slug][] = $this->unit($slug, $raw, $structure, $makers[$raw['id']] ?? [], $byId, $byName, $roster['morphs'][$name] ?? null,
                        $corrections[$name] ?? null, isset($noTime[$name]), isset($noTargets[$name]));
                }
            }
        }

        return $out;
    }

    private function unit(string $race, array $raw, bool $structure, array $makers, array $byId, array $byName, ?array $morph, ?array $fix, bool $noTime, bool $noTargets): Sc2Unit
    {
        // A trained or built unit is made where it is trained; it only counts as a morph when
        // nothing trains it (a Hellbat comes from the Factory, though a Hellion can also become one).
        $made = collect($makers)->first(fn ($m) => $m[1] !== 'Morph' || $m[0] === 'Larva')
            ?? collect($makers)->first(fn ($m) => $m[1] === 'Morph');

        $from = null;
        $needs = null;
        $addon = null;
        $fromDrone = false;
        $minerals = (int) $raw['minerals'];
        $gas = (int) $raw['gas'];

        if ($morph) {
            $from = $byName[$morph['from']] ?? null;
            $needs = $morph['needs'] ?? null;
        } elseif ($made) {
            [$makerName, $kind, $requirements] = $made;
            if ($kind === 'Morph' && $makerName !== 'Larva') {
                $from = $byName[$makerName];
            }
            $fromDrone = $makerName === 'Drone';
            foreach ($requirements as $group) {
                if (isset($group['addon'])) {
                    $addon = 'Tech Lab';
                }
                $building = isset($group['building']) ? ($byId[$group['building']]['name'] ?? null) : null;
                if ($building && ! in_array($building, self::BASE_HALLS, true)) {
                    $needs ??= $building;
                }
            }
        }

        if ($from) {
            $minerals -= (int) $from['minerals'];
            $gas -= (int) $from['gas'];
        } elseif ($fromDrone) {
            $minerals -= (int) $byName['Drone']['minerals'];
        }

        $supply = (float) $raw['supply'];
        $seconds = $raw['time'] > 0 ? (int) round($raw['time'] / self::LOOPS_PER_SECOND) : null;
        $attributes = $raw['attributes'] ?? [];

        if ($fix) {
            $minerals = $fix['minerals'] ?? $minerals;
            $gas = $fix['gas'] ?? $gas;
            $seconds = $fix['seconds'] ?? $seconds;
            $supply = (float) ($fix['supply'] ?? $supply);
            if (isset($fix['addAttribute']) && ! in_array($fix['addAttribute'], $attributes, true)) {
                $attributes[] = $fix['addAttribute'];
            }
        }

        $kinds = collect($raw['weapons'] ?? [])->pluck('target_type')->unique()->values()->all();
        $targets = match (true) {
            $noTargets || $kinds === [] => null,
            in_array('Any', $kinds, true), in_array('Ground', $kinds, true) && in_array('Air', $kinds, true) => 'both',
            $kinds === ['Ground'] => 'ground',
            $kinds === ['Air'] => 'air',
            default => null,
        };

        $makerName = $made[0] ?? null;

        return new Sc2Unit(
            key: $raw['name'],
            label: $this->label($raw['name']),
            race: $race,
            structure: $structure,
            minerals: $minerals,
            gas: $gas,
            supply: ! $structure && $supply > 0 ? $supply : null,
            supplyProvided: $supply < 0 ? (int) round(-$supply) : null,
            seconds: $noTime ? null : $seconds,
            attributes: $attributes,
            targets: $targets,
            madeAt: $structure || $from || ! $makerName ? null : ($makerName === 'Larva' ? 'Larva' : $this->label($makerName)),
            morphsFrom: $from ? $this->label($from['name']) : null,
            needs: $needs ? $this->label($needs) : null,
            needsAddon: $addon,
            fromDrone: $fromDrone,
            worker: in_array($raw['name'], self::WORKERS, true),
        );
    }
}
