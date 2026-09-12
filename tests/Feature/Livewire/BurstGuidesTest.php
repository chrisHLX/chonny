<?php

use App\Livewire\BurstGuideClassBlock;
use App\Livewire\BurstGuides;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * Covers BurstGuides (the thin parent shell), BurstGuideClassBlock (the real, lazy-loaded
 * per-class content), and the invariants every committed guide file must satisfy.
 *
 * Same two-tier convention as ModuleSpellReferenceServiceDescriptionTest.php for this class of
 * page (content depends entirely on real Spell/GameClass/Specialization rows that only ever come
 * from import:spelldata, never a seeder): a bare-DB degrade check, then focused tests against
 * real, committed data.
 *
 * The guide files themselves are asserted against directly rather than through a fixture — they
 * are committed, they are what production serves, and App\Http\Services\BurstGuideBuilder (which
 * writes them) cannot run in CI at all, since it reads a per-window corpus that lives in the
 * arena archive and is deliberately not in this repo. Asserting the committed output's
 * invariants is the only place a mistake in that builder can be caught automatically.
 */
test('the parent page paints instantly with placeholders only — no real spec content until a child actually loads', function () {
    $dir = base_path('data/claudes-guides/burst-guides');
    if (! File::exists($dir) || File::glob("{$dir}/*/*.json") === []) {
        $this->markTestSkipped('No burst-guide files on disk — run php artisan wow:build-burst-guides first.');
    }

    // Deliberately NOT calling Livewire::withoutLazyLoading() here — this test simulates a real
    // browser's first paint, where every #[Lazy] child renders only its placeholder() output
    // until its own deferred follow-up request completes.
    $component = Livewire::test(BurstGuides::class);
    $html = $component->html();

    expect($html)->toContain('Offensive Kits');
    // A real spec name should NOT appear on first paint — only after a child's deferred load.
    expect($html)->not->toContain('Assassination');
});

test('with no real spelldata in the DB, the parent shows the empty state rather than crashing', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    Patch::create(['game_id' => $game->id, 'build_version' => '0.0.0-test', 'is_current' => true]);

    $component = Livewire::test(BurstGuides::class);

    expect($component->instance()->availableClassSlugs)->toBe([]);
    expect($component->html())->toContain('No offensive kits available yet');
});

test('a real, deferred child block resolves a committed guide into ordered phases, fill and live spell data', function () {
    $path = base_path('data/claudes-guides/burst-guides/rogue/assassination.json');
    if (! File::exists($path)) {
        $this->markTestSkipped('No committed rogue/assassination burst guide on disk.');
    }

    $decoded = json_decode(File::get($path), true);
    expect($decoded['sequence'])->not->toBeEmpty();

    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '0.0.0-test', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    Specialization::create(['class_id' => $class->id, 'name' => 'Assassination', 'slug' => 'assassination']);

    $ids = collect($decoded['sequence'])->concat($decoded['fill'])->concat($decoded['alsoPressed'])
        ->pluck('spellId')->unique();

    foreach ($ids as $i => $id) {
        Spell::create([
            'patch_id' => $patch->id,
            'spell_id' => $id,
            'name' => "Test Spell {$id}",
            'cooldown_seconds' => $i % 2 === 0 ? 30 : null,
        ]);
    }

    // #[Lazy] short-circuits mount()/render() to a placeholder by default, even when the
    // component is tested directly — Livewire::withoutLazyLoading() is the documented way to
    // get the real content in a test, simulating the deferred follow-up request completing.
    Livewire::withoutLazyLoading();

    $component = Livewire::test(BurstGuideClassBlock::class, ['classSlug' => 'rogue']);
    $guide = $component->instance()->guide;

    expect($guide['class'])->not->toBeNull();
    expect($guide['specs'])->toHaveCount(1);

    $spec = $guide['specs'][0];

    // Phases render in go order, and only phases that actually have steps appear at all.
    expect(array_keys($spec['phases']))->toBe(
        array_values(array_intersect(['setup', 'commit', 'execute'], array_keys($spec['phases'])))
    );
    expect($spec['anchor'])->not->toBeNull();
    expect($spec['anchor']['role'])->toBe('anchor');

    // Every step resolved to a real Spell model, and the measured statistics survived resolution.
    $steps = collect($spec['phases'])->flatten(1);
    expect($steps)->not->toBeEmpty();
    foreach ($steps as $step) {
        expect($step['spell'])->toBeInstanceOf(Spell::class);
        expect($step)->toHaveKeys(['role', 'phase', 'presence', 'medianOffset', 'cooldown']);
    }

    // The headline numbers the whole page exists to deliver.
    expect($spec['window']['goLengthSeconds'])->toBeGreaterThan(0);
    expect($spec['window']['globals'])->toBeGreaterThan(0);
    expect($spec['evidence']['anchoredWindows'])->toBeGreaterThan(0);

    $html = $component->html();
    expect($html)->toContain('Assassination');
    expect($html)->toContain('show-spell-detail');
    expect($html)->toContain('Commit');
});

test('a class with no resolvable data renders an empty (but valid, single-root) block', function () {
    Livewire::withoutLazyLoading();

    $component = Livewire::test(BurstGuideClassBlock::class, ['classSlug' => 'does-not-exist']);

    expect($component->instance()->guide)->toBe(['class' => null, 'specs' => []]);
    // Must not throw "missing root tag" — confirms the always-present wrapping <div> works.
    expect($component->html())->toBeString();
});

test('every committed guide satisfies the builder\'s own invariants', function () {
    $files = File::glob(base_path('data/claudes-guides/burst-guides/*/*.json'));
    if ($files === [] || $files === false) {
        $this->markTestSkipped('No burst-guide files on disk — run php artisan wow:build-burst-guides first.');
    }

    foreach ($files as $path) {
        $name = basename(dirname($path)).'/'.basename($path, '.json');
        $guide = json_decode(File::get($path), true);

        expect($guide)->toHaveKeys(['evidence', 'window', 'sequence', 'fill', 'alsoPressed'], $name);

        // Exactly one anchor, and it is the reference point every offset is measured against.
        $anchors = array_filter($guide['sequence'], fn (array $s) => $s['role'] === 'anchor');
        expect($anchors)->toHaveCount(1, "{$name}: expected exactly one anchor step");
        expect(reset($anchors)['spellId'])->toBe($guide['window']['anchorSpellId'], "{$name}: anchor step must be the window's anchor");

        // The sequence is the thing a player reads top to bottom — it must be in time order.
        $offsets = array_column($guide['sequence'], 'medianOffset');
        $sorted = $offsets;
        sort($sorted);
        expect($offsets)->toBe($sorted, "{$name}: sequence must be ordered by median offset");

        foreach ($guide['sequence'] as $step) {
            expect($step['role'])->toBeIn(['anchor', 'cooldown', 'control', 'interrupt'], $name);
            expect($step['phase'])->toBeIn(['setup', 'commit', 'execute'], $name);
            expect($step['presence'])->toBeGreaterThanOrEqual(0.25, "{$name}: step below the presence bar");
            expect($step['presence'])->toBeLessThanOrEqual(1.0, $name);

            // Phase must follow from the measured timing, not be set independently of it.
            if ($step['role'] !== 'anchor') {
                $expected = $step['medianOffset'] < -0.5 ? 'setup' : ($step['medianOffset'] <= 1.5 ? 'commit' : 'execute');
                expect($step['phase'])->toBe($expected, "{$name}: phase disagrees with median offset");
            }

            // Control placement is measured from real matches where a sample exists, and only
            // falls back to the curated column or a dr_category inference otherwise — which of
            // the three it was must travel with the answer, so a fallback never reads as
            // measured. Inference must never positively assert kill-target: "survives damage"
            // says an ability is *eligible* for the kill target, never that it belongs there,
            // and reading it as guidance is exactly what mislabelled Solar Beam and Intimidation.
            if ($step['role'] === 'control') {
                expect($step['controlTarget'])->toBeIn(['kill_target', 'healer', 'both', 'peel'], $name);
                expect($step['controlTargetSource'])->toBeIn(['measured', 'curated', 'inferred'], $name);

                if ($step['controlTargetSource'] === 'inferred') {
                    expect($step['controlTarget'])->not->toBe('kill_target', "{$name}: inference must never assert kill-target");
                }

                // A measured answer must carry its evidence, so the page can show it.
                if ($step['controlTargetSource'] === 'measured') {
                    expect($step['targetObservations'])->toBeGreaterThanOrEqual(
                        \App\Http\Services\CcTargetingAnalyzer::MIN_SAMPLE, $name
                    );
                    expect($step['healerShare'])->toBeGreaterThanOrEqual(0.0, $name);
                    expect($step['healerShare'])->toBeLessThanOrEqual(1.0, $name);
                    expect($step['targetRatio'])->toBeGreaterThanOrEqual(0.0, $name);
                }
            }
        }

        // Fill is what you spend leftover globals on — by definition it has no real cooldown.
        foreach ($guide['fill'] as $step) {
            expect($step['role'])->toBe('fill', $name);
            expect($step['castsPerWindow'])->toBeGreaterThan(0, $name);
        }

        // Globals must be derived from the window and the spec's own GCD, not stated freely.
        $expectedGlobals = max(1, (int) round($guide['window']['goLengthSeconds'] / $guide['evidence']['gcdSeconds']));
        expect($guide['window']['globals'])->toBe($expectedGlobals, "{$name}: globals must follow from window / GCD");

        expect($guide['window']['goLengthBasis'])->toBeIn(['buff-duration', 'measured'], $name);
        expect($guide['evidence']['gcdSeconds'])->toBeGreaterThanOrEqual(0.75, $name);
        expect($guide['evidence']['gcdSeconds'])->toBeLessThanOrEqual(1.5, $name);
        // The whole point of the rebuild: an aggregate, never a single observed window.
        expect($guide['evidence']['anchoredWindows'])->toBeGreaterThanOrEqual(20, "{$name}: too few windows to be an aggregate");
    }
});
