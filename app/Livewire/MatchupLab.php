<?php

namespace App\Livewire;

use App\Http\Services\CooldownGraphService;
use App\Http\Services\MatchupProfileService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Matchup Lab — pick two 3v3 comps and see whose kill window opens first, and why.
 *
 * WHY IT EXISTS. arena-structure.md states its rules without a **when**: "a go into an empty
 * pool is a kill" is true and unusable until something says at which point in the round their
 * pool is empty. Every other page on this site answers a question about one spec or one comp;
 * this is the first that answers a question about a *matchup*, which is the unit a guide is
 * actually written for. See Part 19, and `data/matchup-profiles/README.md` for the honest
 * limits.
 *
 * WHAT IT DELIBERATELY DOES NOT SHOW. A win probability. There is no outcome corpus to fit one
 * to — match search is discontinued upstream (CLAUDE.md rule 12) and the archive's comp index
 * holds two entries — so a percentage here would be a number the data cannot produce. The page
 * shows a structural read with its reasons, and prints what it cannot see. All the honest-limits
 * copy lives in one place, {@see limitations()}, so it cannot drift out of the page by being
 * edited in a blade.
 *
 * THE THREE EXECUTION SETTINGS ARE THE POINT, not a toggle. Part 19.3: the same matchup read at
 * "answering late" and at "playing the pool" is a different picture and often a different plan,
 * and Part 0 already requires a guide to say which use it is written for. That is what this page
 * gives an author that nothing else on the site does.
 *
 * COST. Six small committed profiles (~7KB each) and a ~5ms simulation — no database reads for
 * the matchup itself. Deliberately not built on SpecKitComputer's 750KB-per-spec kits, which
 * would be ~4.5MB of JSON parse per render on a one-vCPU box.
 */
class MatchupLab extends Component
{
    /**
     * Locked for the same reason WowComps' slots are: a public array property is writable by
     * anyone who posts to /livewire/update, bound or not, and live scanners do exactly that —
     * they grab a snapshot and write junk into every public property. An int where an array
     * belongs turns into a 500 per request. Only selectSpec() and applyPreset() write these.
     *
     * Slot 0 is the healer slot on both sides, matching WoW Comps.
     */
    #[Locked]
    public array $teamA = [
        ['label' => 'Healer', 'classId' => null, 'specId' => null],
        ['label' => 'DPS', 'classId' => null, 'specId' => null],
        ['label' => 'DPS', 'classId' => null, 'specId' => null],
    ];

    #[Locked]
    public array $teamB = [
        ['label' => 'Healer', 'classId' => null, 'specId' => null],
        ['label' => 'DPS', 'classId' => null, 'specId' => null],
        ['label' => 'DPS', 'classId' => null, 'specId' => null],
    ];

    /** Validated against EXECUTION_SETTINGS on every read, so a posted junk value cannot reach the engine. */
    #[Locked]
    public string $execution = CooldownGraphService::EXECUTION_CLEAN;

    /**
     * Which of the two trigger tables is open. Pure view state that decides nothing about what
     * is computed or where anything saves, so it is deliberately not locked.
     */
    public string $triggerSide = 'a';

    /**
     * The one chart palette. VALIDATED, not chosen by eye: `#B98722` with `#7B6EE8` passes all
     * six checks of the dataviz validator against this site's `surface-1` (#111116) — lightness
     * band, chroma floor, CVD separation (ΔE 29.9 protan / 17.4 tritan), normal-vision
     * separation and 3:1 contrast. The brand's own `gold` (#C8952C) FAILS the dark-mode
     * lightness band at L 0.701 against a ceiling of 0.67, so this is a step darker than the
     * token deliberately. Do not "correct" it back to the brand gold without re-running
     * `scripts/validate_palette.js` — the point of a validated palette is that it is computed.
     *
     * Identity is never colour-alone on this page: both charts are direct-labelled and carry a
     * legend, per the same guidance.
     */
    public const TEAM_COLOURS = ['a' => '#B98722', 'b' => '#7B6EE8'];

    /** Every third second. 121 points draws smoothly and keeps the SVG out of the hundreds of KB. */
    public const CHART_STEP_SECONDS = 3;

    public function mount(): void
    {
        PageViewEvent::log('matchup_lab');
    }

    /**
     * Sets one slot. Arguments arrive from the browser, so the spec is checked to really belong
     * to the class — otherwise one class's name renders over another's kit — and the side and
     * index are checked before either is used as a key. Same discipline as WowComps::selectSpec().
     *
     * Logs an ATTRIBUTED page view per real selection (never on a default), which is what
     * CLAUDE.md rule 28 requires on top of the bare mount() log, and what makes the class/spec
     * breakdown in Admin\PageUsage mean anything for this page.
     */
    public function selectSpec(string $side, int $index, int $classId, int $specId): void
    {
        if (! in_array($side, ['a', 'b'], true)) {
            return;
        }

        $slots = $side === 'a' ? $this->teamA : $this->teamB;

        if (! array_key_exists($index, $slots)
            || ! Specialization::whereKey($specId)->where('class_id', $classId)->exists()) {
            return;
        }

        $slots[$index]['classId'] = $classId;
        $slots[$index]['specId'] = $specId;

        if ($side === 'a') {
            $this->teamA = $slots;
        } else {
            $this->teamB = $slots;
        }

        PageViewEvent::log('matchup_lab', $classId, $specId, $side.$index);
    }

    /**
     * Loads one of WoW Comps' own named comps into a side. Reuses WowComps::presetLinks() rather
     * than keeping a second list — the two pages would drift, and "Jungle" meaning different
     * specs on two pages of one site is exactly the kind of quiet inconsistency this codebase
     * has paid for before.
     */
    public function applyPreset(string $side, string $key): void
    {
        foreach (WowComps::presetLinks() as $preset) {
            if ($preset['key'] !== $key) {
                continue;
            }

            foreach ($preset['specs'] as $index => $spec) {
                $this->selectSpec($side, $index, $spec->class_id, $spec->id);
            }

            PageViewEvent::log('matchup_lab_preset', slot: $side.':'.$key);

            return;
        }
    }

    public function setExecution(string $execution): void
    {
        if (! array_key_exists($execution, CooldownGraphService::EXECUTION_SETTINGS)) {
            return;
        }

        $this->execution = $execution;

        PageViewEvent::log('matchup_lab_execution', slot: $execution);
    }

    public function setTriggerSide(string $side): void
    {
        $this->triggerSide = in_array($side, ['a', 'b'], true) ? $side : 'a';
    }

    public function getClassSpecsProperty(): Collection
    {
        return GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->with(['specializations' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Blizzard's own role per spec, read from WoW Comps' single definition rather than a second
     * copy — the healer slot must allow the same specs on both pages.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function specRoles(): array
    {
        return (new WowComps)->getSpecRoleMapProperty();
    }

    #[Computed]
    public function presets(): array
    {
        return WowComps::presetLinks();
    }

    /**
     * Both comps resolved to real Specialization models, keyed by side, for the pickers and the
     * headers. A slot with nothing chosen is null, so the blade can render an empty slot rather
     * than the page needing a "complete" branch around every label.
     *
     * @return array{a: array<int, ?Specialization>, b: array<int, ?Specialization>}
     */
    #[Computed]
    public function chosen(): array
    {
        $ids = collect($this->teamA)->pluck('specId')
            ->merge(collect($this->teamB)->pluck('specId'))
            ->filter()
            ->unique();

        $specs = $ids->isEmpty()
            ? collect()
            : Specialization::with('gameClass')->whereIn('id', $ids)->get()->keyBy('id');

        $resolve = fn (array $slots) => collect($slots)
            ->map(fn (array $slot) => $slot['specId'] ? $specs->get($slot['specId']) : null)
            ->all();

        return ['a' => $resolve($this->teamA), 'b' => $resolve($this->teamB)];
    }

    public function isComplete(): bool
    {
        return collect($this->teamA)->pluck('specId')->filter()->count() === 3
            && collect($this->teamB)->pluck('specId')->filter()->count() === 3;
    }

    /**
     * The engine's output, or null until both comps are picked.
     *
     * A side whose specs have no committed profile yet returns null with the missing names in
     * {@see missingProfiles()}, rather than running a matchup with a member's answers absent. A
     * comp missing one member's pool does not read as an incomplete answer — it reads as a comp
     * with a shorter answer list, which is a wrong answer rather than a missing one, and it
     * would silently move the verdict.
     */
    #[Computed]
    public function result(): ?array
    {
        if (! $this->isComplete() || $this->missingProfiles() !== []) {
            return null;
        }

        return app(CooldownGraphService::class)->run(
            $this->sideForEngine('a'),
            $this->sideForEngine('b'),
            $this->execution
        );
    }

    /**
     * Spec names with no `data/matchup-profiles/{class}/{spec}.json`, or one written against an
     * older shape. Surfaced on the page as a named gap with the command that fixes it, never as
     * an empty chart.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function missingProfiles(): array
    {
        $service = app(MatchupProfileService::class);
        $missing = [];

        foreach (['a', 'b'] as $side) {
            foreach ($this->chosen()[$side] as $spec) {
                if (! $spec || ! $spec->gameClass) {
                    continue;
                }

                if ($service->read($spec->gameClass->slug, $spec->slug) === null) {
                    $missing[] = $spec->name.' '.$spec->gameClass->name;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return array<int, array{role: string, profile: array}>
     */
    private function sideForEngine(string $side): array
    {
        $service = app(MatchupProfileService::class);
        $members = [];

        foreach ($this->chosen()[$side] as $index => $spec) {
            $members[] = [
                // Slot 0 is the healer slot on both sides, and the engine's control allocation
                // puts the healer first (Part 5: a free healer is what neutralises a go). Read
                // off the picker's own slot rather than re-derived, so a tank in a DPS slot is
                // treated as the DPS the player put there.
                'role' => $index === 0 ? 'healer' : 'dps',
                'profile' => $service->read($spec->gameClass->slug, $spec->slug),
            ];
        }

        return $members;
    }

    /**
     * The two charts, pre-shaped so the blade holds no arithmetic.
     *
     * TWO CHARTS, NOT ONE WITH TWO AXES. Threat is a 0–100 availability index and answers are a
     * count of buttons; putting them on one pair of axes would be a dual-axis chart, which
     * invites the reader to see a crossing point that means nothing. They share one x axis and
     * one set of kill-window bands instead, which is what actually makes the pair readable
     * together.
     *
     * The answers series is each side's THINNEST list, not its total. Part 2: the pool is
     * per-player, and the kill target is whoever's list is shortest — a team total averages away
     * the only number that decides the go.
     */
    #[Computed]
    public function chart(): ?array
    {
        $result = $this->result();

        if (! $result) {
            return null;
        }

        $threat = ['a' => [], 'b' => []];
        $answers = ['a' => [], 'b' => []];
        $maxAnswers = 1;

        foreach ($result['samples'] as $sample) {
            if ($sample['t'] % self::CHART_STEP_SECONDS !== 0) {
                continue;
            }

            foreach (['a', 'b'] as $side) {
                $threat[$side][] = ['t' => $sample['t'], 'v' => $sample[$side.'Threat']];

                $thinnest = null;
                foreach ($sample[$side.'Pool'] as $player) {
                    if ($thinnest === null || $player['live'] < $thinnest) {
                        $thinnest = $player['live'];
                    }
                }

                $answers[$side][] = ['t' => $sample['t'], 'v' => $thinnest ?? 0];
                $maxAnswers = max($maxAnswers, $thinnest ?? 0);
            }
        }

        $bands = [];
        foreach ($result['killWindows'] as $window) {
            $bands[] = ['t' => $window['t'], 'side' => $window['side']];
        }

        return [
            'threat' => $threat,
            'answers' => $answers,
            'maxAnswers' => $maxAnswers,
            'bands' => $bands,
            'horizon' => $result['horizon'],
        ];
    }

    /**
     * Everything the page must say about what it cannot see. In one place on purpose: this copy
     * is the difference between a tool and a tool that overclaims, and scattered across a blade
     * it would be quietly trimmed one line at a time by somebody tidying the layout.
     *
     * @return array<int, array{title: string, body: string}>
     */
    public function limitations(): array
    {
        return [
            [
                'title' => 'This is not a win probability',
                'body' => 'Nothing here is fitted to match outcomes, because there is no outcome data to fit it to — arena log search was discontinued upstream and the archive holds a fixed corpus with two comps indexed. What you are reading is cooldown arithmetic with its reasoning shown. A percentage would look more authoritative and mean less.',
            ],
            [
                'title' => 'A kill window is not a kill',
                'body' => 'An empty answer list means the target has no button left. It does not mean the damage is lethal — that needs a damage model tied to a real character\'s gear, which this site does not have and does not fake. The window is where a go is a kill attempt instead of a strip.',
            ],
            [
                'title' => 'Every cadence here is the slowest it could be',
                'body' => 'Cooldowns that shrink as you spend resources are not in our data, so real goes come round sooner than the timeline shows — by an amount that differs per spec and that nothing currently measures.',
            ],
            [
                'title' => 'No positioning, no comms, no reaction time',
                'body' => 'A curve has no geometry. The model assumes both enemy DPS are reachable when they are being controlled on the same global, which is exactly the assumption real games break. It also assumes the call gets made and the button gets pressed.',
            ],
            [
                'title' => 'One build per spec',
                'body' => 'Each comp is read against that spec\'s current default build. A talent swap into a matchup can change which go is even possible, which is the thing the top of the ladder spends its preparation on.',
            ],
        ];
    }

    public function render()
    {
        return view('livewire.matchup-lab', [
            'classSpecs' => $this->classSpecs,
            'specRoles' => $this->specRoles(),
            'presets' => $this->presets(),
            'chosen' => $this->chosen(),
            'result' => $this->result(),
            'chart' => $this->chart(),
            'missingProfiles' => $this->missingProfiles(),
            'limitations' => $this->limitations(),
            'executionSettings' => CooldownGraphService::EXECUTION_SETTINGS,
            'teamColours' => self::TEAM_COLOURS,
        ])->layout('layouts.app', [
            'title' => 'Matchup Lab — whose kill window opens first | MindCollector',
            'description' => 'Put two WoW 3v3 arena comps on one clock. See every cooldown both teams hold, '
                .'where each side runs out of answers, and which team the structure favours — with the '
                .'reasoning shown and no invented win percentage.',
        ]);
    }
}
