<?php

namespace App\Livewire;

use App\Http\Services\ArenaLogService;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use Illuminate\Support\Collection;
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
        $firstClass = GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')
            ->first();

        if (!$firstClass) {
            return;
        }

        $this->classId = $firstClass->id;
        $this->specId = Specialization::where('class_id', $firstClass->id)->orderBy('name')->first()?->id;

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
        }

        return [
            'windows' => $rotation['windows'],
            'matches' => $rotation['matches'],
            'anchors' => $rotation['anchors'],
            'window' => $window,
            'generatedAt' => $rotation['generated_at'] ?? null,
        ];
    }

    /**
     * "Important Mechanics (Review)" block data — reads the STAGING mechanics.txt via
     * ArenaLogService::mechanicsForSpec() (see that method's docblock for why staging, not
     * promoted, is deliberate here). Length-independent (mechanics.txt isn't scoped per burst
     * length the way $length is), so this only depends on classId/specId.
     *
     * Rows resolved to real Spell models via resolveWindowSteps() — the exact same method the
     * Peak Burst Example sequence already uses (it's generic: any array of associative arrays
     * with a `spellId` key works, mechanics.txt rows qualify as-is), so the two card styles
     * share real icon/name/talent-linked-copy resolution rather than a second implementation.
     *
     * Each row also gets a `kind` — 'talent' / 'pvp_talent' / 'passive' / 'ability' — added
     * 2026-08-27, direct request ("is it a talent or a buff or an ability buff"). Reuses the
     * exact same `source` classification WowComps/SpellExplorer already compute for their own
     * spell listings (TalentSelectionService::allTalentSpellIds()/allPvpTalentSpellIds(), see
     * WowComps::spellReferencesFor()'s identical `'source' => ...` line) rather than a second
     * heuristic — 'talent'/'pvp_talent' means the spell is a real pickable node for this spec;
     * anything else is split by the spell's own `is_passive` column (already-verified data,
     * same field `<x-spells.table>`'s Active Abilities/Buffs & Passives split already trusts)
     * into 'passive' (an innate, always-on buff — never pressed) vs 'ability' (an active
     * button-press whose casting produces the tracked buff/debuff).
     */
    public function getMechanicsProperty(): ?array
    {
        if (!$this->classId || !$this->specId) {
            return null;
        }

        $class = GameClass::find($this->classId);
        $spec = Specialization::find($this->specId);

        if (!$class || !$spec) {
            return null;
        }

        $mechanics = app(ArenaLogService::class)->mechanicsForSpec($class->slug, $spec->slug);

        if ($mechanics === null) {
            return null;
        }

        $talentService = app(TalentSelectionService::class);

        $mechanics['rows'] = app(ArenaLogService::class)->resolveWindowSteps(
            $mechanics['rows'],
            $this->specId,
            $talentService
        );

        $talentIds = $talentService->allTalentSpellIds($this->specId);
        $pvpTalentIds = $talentService->allPvpTalentSpellIds($this->specId);

        $mechanics['rows'] = array_map(function ($row) use ($talentIds, $pvpTalentIds) {
            $spell = $row['spell'] ?? null;

            $row['kind'] = match (true) {
                $spell === null => null,
                $talentIds->contains($spell->id) => 'talent',
                $pvpTalentIds->contains($spell->id) => 'pvp_talent',
                (bool) $spell->is_passive => 'passive',
                default => 'ability',
            };

            return $row;
        }, $mechanics['rows']);

        return $mechanics;
    }

    public function render()
    {
        return view('livewire.top-damage-rotations', [
            'classes' => $this->classes,
            'classSpecs' => $this->classSpecs,
            'rotation' => $this->rotation,
            'mechanics' => $this->mechanics,
        ])->layout('layouts.app', [
            'title' => 'WoW Burst Windows — Real Arena Damage Rotations | MindCollector',
            'description' => 'The single highest-damage burst window per WoW spec, taken straight from real arena logs — the exact cast sequence, anchored on that spec\'s offensive cooldowns.',
        ]);
    }
}
