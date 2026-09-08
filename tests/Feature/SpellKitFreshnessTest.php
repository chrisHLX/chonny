<?php

use Illuminate\Support\Facades\File;

/**
 * The 40 precomputed kits under data/spell-kits/ each embed the spellCacheVersion and
 * codeFingerprint they were built against, and SpecKitComputer::tryReadPrecomputed() silently
 * returns null the moment either stops matching — dropping every page site-wide onto a live
 * compute that is safe, ~25x slower, and completely quiet about it.
 *
 * That has been found in production twice (2026-09-05: 37 of 40 stale; 2026-09-08: all 40, and
 * it presented as "selecting abilities takes a while to register" in the guide builder). Both
 * times the files were committed stale, which is what makes this checkable here rather than only
 * at runtime.
 *
 * This asserts the COMMITTED files agree with each other and are internally well-formed. It
 * deliberately does NOT compare them against the live database's version counter: that counter
 * lives in wow_spell_cache_state, RefreshDatabase gives the suite an empty schema, and a
 * developer's local counter legitimately runs ahead of what is committed. The failure mode this
 * catches is the real one — a partial regeneration that leaves some specs on an older version
 * than others, which is how "37 of 40" happens.
 */
test('every committed spell kit was built against the same version and fingerprint', function () {
    $files = collect(File::glob(base_path('data/spell-kits/*/*.json')));

    expect($files)->not->toBeEmpty('No precomputed spell kits found — run wow:precompute-spell-kits.');

    $stamps = $files->mapWithKeys(function (string $path) {
        $d = json_decode(File::get($path), true);

        return [basename(dirname($path)).'/'.basename($path, '.json') => [
            'version' => $d['spellCacheVersion'] ?? null,
            'fingerprint' => $d['codeFingerprint'] ?? null,
            'entries' => is_array($d['entries'] ?? null) ? count($d['entries']) : null,
        ]];
    });

    // Every file carries the three keys tryReadPrecomputed() requires; a null is a file that
    // would be rejected at runtime and fall back to the slow path.
    $malformed = $stamps->filter(fn (array $s) => $s['version'] === null || $s['fingerprint'] === null || $s['entries'] === null);
    expect($malformed->keys()->all())->toBe([], 'Malformed kit files: '.$malformed->keys()->implode(', '));

    // All built by the same run. A split means a partial regeneration, and the specs on the older
    // stamp are the ones quietly paying the live-compute cost.
    $versions = $stamps->pluck('version')->unique();
    expect($versions->count())->toBe(
        1,
        'Kits are on mixed spellCacheVersions ('.$versions->implode(', ').') — regenerate with '
        .'`php artisan wow:precompute-spell-kits`. Mixed versions mean some specs are on the '
        .'slow live-compute path.'
    );

    expect($stamps->pluck('fingerprint')->unique()->count())->toBe(1);

    // A kit that resolved almost nothing is a failed run that still wrote a file.
    $thin = $stamps->filter(fn (array $s) => $s['entries'] < 10);
    expect($thin->keys()->all())->toBe([], 'Suspiciously empty kits: '.$thin->keys()->implode(', '));
});

/**
 * Five commands bump the shared cache version, and every one of them invalidates all 40 kits by
 * doing so. Only ImportSpellData regenerated them, which is why the site ended up fully stale a
 * second time. Asserting the trait is present is what stops a sixth bump site being added
 * without it — the rationale lives in RegeneratesSpellKits' docblock, but a docblock does not
 * fail a build.
 */
test('every command that bumps the spell cache version also regenerates the kits', function () {
    $bumpers = collect(File::files(app_path('Console/Commands')))
        ->filter(fn ($f) => str_contains(File::get($f->getPathname()), 'bumpSpellCacheVersion()'))
        ->map(fn ($f) => $f->getFilename());

    expect($bumpers)->not->toBeEmpty();

    $missing = $bumpers->reject(
        fn (string $name) => str_contains(File::get(app_path("Console/Commands/{$name}")), 'regenerateSpellKits()')
    );

    expect($missing->values()->all())->toBe(
        [],
        'These bump the spell cache version without regenerating the precomputed kits, which '
        .'leaves every page on the slow live-compute path: '.$missing->implode(', ')
        .'. Add `use RegeneratesSpellKits;` and call $this->regenerateSpellKits() after the bump.'
    );
});
