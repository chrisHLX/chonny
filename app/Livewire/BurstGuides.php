<?php

namespace App\Livewire;

use App\Models\GameClass;
use App\Models\PageViewEvent;
use Illuminate\Support\Facades\File;
use Livewire\Component;

/**
 * "Burst Guides" — a grid of compact, per-spec burst-sequence blocks: a definite, filtered
 * series of real ability presses (offensive cooldowns + Crowd Control on the kill target,
 * ordinary rotation kept, purely-defensive noise dropped), one block per spec that has real
 * rotation data. Reads ONLY data/claudes-guides/burst-guides/{class}/{spec}.json — written by
 * `php artisan wow:build-burst-guides` (see ArenaLogService::buildBurstGuideSequence() for the
 * full filter/truncation design this page is a pure read-and-render layer over).
 *
 * Distinct from the existing Claude's Guides page (/claudes-guides, App\Livewire\ClaudesGuides)
 * — that page is a deep-dive per spec, hand-written, and only covers 11 of the 38 real specs.
 * This page is comprehensive (every spec with rotation data), auto-computed, and deliberately
 * shallow — one block per spec, no prose, matching the design brief's own "that's a simple
 * layout" framing. Both live under data/claudes-guides/ but in separate subfolders and are read
 * by separate, independent page components — neither reads the other's files.
 *
 * THIS COMPONENT IS DELIBERATELY THIN — it only ever lists which classes have burst-guide data
 * on disk (a cheap directory scan, no spell resolution at all) and mounts one lazy-loaded
 * App\Livewire\BurstGuideClassBlock child per class. Split out 2026-09-04 after a real, direct
 * report: the original single-component design resolved and rendered all 38 specs (508 total
 * step-cards) synchronously in one request — even after an earlier same-day fix had already cut
 * peak memory from 430MB to 64MB, that was still real, unavoidable per-page-view cost for
 * content most of which the viewer hadn't scrolled to yet. See BurstGuideClassBlock's own
 * docblock for the full reasoning on why per-CLASS lazy loading (13 small requests) was chosen
 * over per-SPEC (38) or no split at all.
 */
class BurstGuides extends Component
{
    public function mount(): void
    {
        PageViewEvent::log('burst_guides');
    }

    /**
     * Every class slug that has at least one burst-guide file on disk, ordered by the class's
     * real display name — a plain directory listing + one cheap query, deliberately NOT resolving
     * or rendering any spec/spell data itself (that's entirely BurstGuideClassBlock's job, one
     * lazy request per class).
     *
     * @return array<int, string>
     */
    public function getAvailableClassSlugsProperty(): array
    {
        $dir = base_path('data/claudes-guides/burst-guides');
        $slugs = File::exists($dir)
            ? collect(File::directories($dir))->map(fn ($path) => basename($path))->values()->all()
            : [];

        if (empty($slugs)) {
            return [];
        }

        return GameClass::whereIn('slug', $slugs)->orderBy('name')->pluck('slug')->all();
    }

    public function render()
    {
        return view('livewire.burst-guides', [
            'availableClassSlugs' => $this->availableClassSlugs,
        ])->layout('layouts.app', [
            'title' => 'Burst Guides | MindCollector',
            'description' => 'A definite, filtered sequence of key presses per spec — offensive cooldowns and kill-target crowd control only — derived from real archived burst windows.',
        ]);
    }
}
