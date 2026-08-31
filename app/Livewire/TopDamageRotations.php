<?php

namespace App\Livewire;

use App\Http\Services\ArenaLogService;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use App\Models\TalentNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Component;

/**
 * Single-spec counterpart to WowComps' "Top DPS Rotation" tab (2026-08-22, direct request) —
 * same underlying data (ArenaLogService::rotationForSpec()'s `topDpsWindowsByLength`, see
 * offensive-rotations.php in wow-arena-archive for the derivation: a real, verified highest-
 * damage window found by scanning each match's ENTIRE timeline, not boxed inside any one
 * cooldown's usage window), but lets the viewer pick which burst length to look at instead of
 * always showing whichever one length WowComps happens to have wired into its own tab.
 *
 * Length options are 6/12/20/30s — matches BURST_LENGTHS_SECONDS in offensive-rotations.php
 * minus 10s, which was only ever the interim bracket WowComps' tab used before 12s replaced it
 * (see that class's docblock) and isn't offered as a separate choice here.
 *
 * Picker is the same class/spec grid modal as WowComps/SpellExplorer (SpellExplorer's own
 * docblock: "matches WowComps' picker exactly") — single selection, since this page only ever
 * has one spec to look at, not a 3-slot comp.
 */
class TopDamageRotations extends Component
{
    public const LENGTHS = [6, 12, 20, 30];

    public ?int $classId = null;

    public ?int $specId = null;

    public int $length = 12;

    public function mount(): void
    {
        // Default to the first WoW spec that actually has a promoted burst window — NOT just
        // `orderBy('name')->first()`, which lands on Death Knight / Blood: a tank spec with
        // effectively no rated-3v3 pre-kill data, so it has neither a rotation window nor a
        // mechanics file, and the whole page renders empty (reported 2026-08-28).
        $spec = Specialization::with('gameClass')
            ->whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')
            ->get()
            ->first(fn ($s) => $s->gameClass
                && File::exists(base_path("data/arena-logs/rotations/{$s->gameClass->slug}/{$s->slug}.json")));

        if ($spec) {
            $this->classId = $spec->class_id;
            $this->specId = $spec->id;
        } else {
            $firstClass = GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->orderBy('name')->first();
            $this->classId = $firstClass?->id;
            $this->specId = $firstClass ? Specialization::where('class_id', $firstClass->id)->orderBy('name')->first()?->id : null;
        }

        // Bare page view only, not attributed to the default class/spec — same reasoning as
        // SpellExplorer::mount()'s identical guard (every visitor lands on this default
        // regardless of interest; attributing it would make it look artificially popular).
        PageViewEvent::log('top_damage_rotations');
    }

    /**
     * Sets class + spec in one action — the shared class/spec grid modal's click target, same
     * pattern as WowComps::selectSpec()/SpellExplorer::selectSpec().
     */
    public function selectSpec(int $classId, int $specId): void
    {
        $this->classId = $classId;
        $this->specId = $specId;

        PageViewEvent::log('top_damage_rotations', $this->classId, $this->specId);
    }

    public function selectLength(int $length): void
    {
        if (!in_array($length, self::LENGTHS, true)) {
            return;
        }

        $this->length = $length;

        if ($this->classId && $this->specId) {
            PageViewEvent::log('top_damage_rotations', $this->classId, $this->specId);
        }
    }

    public function getClassesProperty(): Collection
    {
        return GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Every class with its specs eager-loaded — backs the class/spec grid modal. Same shape as
     * WowComps::getClassSpecsProperty()/SpellExplorer::getClassSpecsProperty().
     */
    public function getClassSpecsProperty(): Collection
    {
        return $this->classes->load(['specializations' => fn ($q) => $q->orderBy('name')]);
    }

    /**
     * @return array{windows: int, matches: int, anchors: array, window: ?array, generatedAt: ?string}|null
     */
    public function getRotationProperty(): ?array
    {
        if (!$this->classId || !$this->specId) {
            return null;
        }

        $class = GameClass::find($this->classId);
        $spec = Specialization::find($this->specId);

        if (!$class || !$spec) {
            return null;
        }

        $service = app(ArenaLogService::class);
        $talentService = app(TalentSelectionService::class);

        $rotation = $service->rotationForSpec($class->slug, $spec->slug);

        if ($rotation === null) {
            return null;
        }

        $window = $rotation['topDpsWindowsByLength'][$this->length] ?? null;

        if ($window !== null) {
            $window['steps'] = $service->resolveWindowSteps($window['steps'], $this->specId, $talentService);

            // Embedded once, at rotation-generation time, by `wow:enrich-rotation-talents` (see
            // that command's docblock) — the real talent build the player who produced this
            // exact window actually had selected. Resolving `spell` here reuses
            // resolveWindowSteps() as-is (it's generic over any {spellId: ...} array) purely for
            // icon display — this doesn't re-derive or re-verify the talent build itself.
            if (isset($window['talentBuild'])) {
                $window['talentBuild']['talents'] = $service->resolveWindowSteps(
                    $window['talentBuild']['talents'] ?? [],
                    $this->specId,
                    $talentService
                );
                $window['talentBuild']['pvpTalents'] = $service->resolveWindowSteps(
                    $window['talentBuild']['pvpTalents'] ?? [],
                    $this->specId,
                    $talentService
                );

                // The embedded talent list only ever carries `treeType` (the generic
                // 'class'/'spec'/'hero' string — see ArenaLogService::resolveCombatantTalents())
                // never the specific hero tree's own proper name (e.g. "Deathstalker"). Resolved
                // here, live, rather than re-running wow:enrich-rotation-talents across every
                // spec just to embed one extra string: any hero-type talent already carries its
                // real internal `nodeId` (added 2026-08-28 for the read-only calculator preset),
                // so one cheap lookup gets the tree it actually belongs to.
                $heroTalent = collect($window['talentBuild']['talents'] ?? [])
                    ->first(fn ($t) => ($t['treeType'] ?? null) === 'hero' && !empty($t['nodeId']));
                $window['talentBuild']['heroTreeName'] = $heroTalent
                    ? TalentNode::find($heroTalent['nodeId'])?->talentTree?->name
                    : null;
            }

            // Embedded once, at rotation-generation time, by `wow:enrich-rotation-mechanics` —
            // real champion/target buff+debuff facts for this exact real window (see
            // ArenaLogService::enrichBurstWindow()'s docblock for the full reasoning: this
            // replaced an earlier, rejected attempt at a frequency-ranked AGGREGATE across many
            // different windows, direct correction 2026-08-29 — "the window was only ever
            // recorded with one target and one specific example"). Each of the four categories
            // resolved to real Spell models the same way steps/talentBuild already are.
            if (isset($window['mechanics'])) {
                foreach (['championBuffs', 'championDebuffs', 'targetBuffs', 'targetDebuffs'] as $key) {
                    $resolved = $service->resolveWindowSteps(
                        $window['mechanics'][$key] ?? [],
                        $this->specId,
                        $talentService
                    );

                    // Unlike steps/talentBuild (an ordered sequence, where dropping an
                    // unresolvable entry would misrepresent the real cast count — see
                    // resolveWindowSteps()'s own docblock), mechanics is an unordered "what was
                    // active" list, so an entry with no real Spell match carries no
                    // informational value here and is dropped rather than shown as an
                    // unclickable plain-text chip. Confirmed 2026-08-31 (direct user report of
                    // "generic world buff" clutter): every one of a real sample of noisy names —
                    // Find Herbs, Rune of Masterful Cunning, Flight Style: Skyriding, Sign of
                    // Battle, Touch of Elune - Day — has ZERO row in `spells` for the current
                    // patch at all (professions/world-seasonal-content/crafted-gear-rune procs,
                    // none of which are part of the SimC class dumps this import is built from),
                    // while every real class buff checked — including cross-class ones like
                    // Guardian Spirit, Chaos Brand, Mystic Touch, Skyfury — resolves cleanly.
                    // Deliberately NOT filtered by "is this in the champion/target's own spec
                    // kit" (e.g. reusing WowComps' Buffs & Passives list) — targetBuffs/
                    // targetDebuffs/championDebuffs are intentionally cross-class (a teammate's
                    // healing cooldown or an enemy's CC showing up on someone else's buff list is
                    // exactly the real signal these categories exist to surface), so a spec-kit
                    // whitelist would incorrectly strip real, correct cross-class entries. No
                    // curated list needed — "does it resolve to a real Spell row at all" already
                    // separates the two cleanly.
                    $window['mechanics'][$key] = array_values(array_filter(
                        $resolved,
                        fn ($item) => $item['spell'] !== null
                    ));
                }
            }
        }

        return [
            'windows' => $rotation['windows'],
            'matches' => $rotation['matches'],
            'anchors' => $rotation['anchors'],
            'window' => $window,
            'generatedAt' => $rotation['generated_at'] ?? null,
        ];
    }

    public function render()
    {
        return view('livewire.top-damage-rotations', [
            'classes' => $this->classes,
            'classSpecs' => $this->classSpecs,
            'rotation' => $this->rotation,
        ])->layout('layouts.app', [
            'title' => 'WoW Burst Windows — Real Arena Damage Rotations | MindCollector',
            'description' => 'The single highest-damage burst window per WoW spec, taken straight from real arena logs — the exact cast sequence, anchored on that spec\'s offensive cooldowns.',
        ]);
    }
}
