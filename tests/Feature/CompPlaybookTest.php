<?php

use App\Http\Services\CompPlaybookService;
use App\Models\GameClass;
use App\Models\Specialization;
use App\Models\Spell;

/**
 * The comp page's "How to play it" (CompPlaybookService), built from what WowComps already
 * computed. Fixtures are unsaved models: the service reads only what it is handed, plus the
 * committed go-cooldowns file (data/comp-playbook/go-cooldowns.json).
 */
function playbookSpell(int $id, string $name, array $attrs = []): Spell
{
    return (new Spell)->forceFill(['id' => $id, 'spell_id' => $id + 100000, 'name' => $name] + $attrs);
}

function playbookEntry(Spell $spell, array $attrs = []): array
{
    return $attrs + [
        'spell' => $spell,
        'isSelected' => true,
        'isPriority' => true,
        'drCategory' => $spell->dr_category,
        'cooldown' => ['seconds' => $spell->cooldown_seconds],
        'offensiveDefensive' => null,
    ];
}

function playbookMember(string $class, string $spec, int $externalSpecId, array $entries, int $id): array
{
    return [
        'label' => 'x',
        'class' => (new GameClass)->forceFill(['name' => $class, 'slug' => strtolower($class)]),
        'spec' => (new Specialization)->forceFill(['id' => $id, 'name' => $spec, 'external_spec_id' => $externalSpecId]),
        'entries' => $entries,
    ];
}

function playbookFixture(): array
{
    $combustion = playbookSpell(1, 'Combustion', ['cooldown_seconds' => 60]);
    $pyroblast = playbookSpell(2, 'Pyroblast', ['cooldown_seconds' => 30]);
    $polymorph = playbookSpell(3, 'Polymorph', ['dr_category' => 'Incapacitate', 'cast_type' => 'cast', 'pvp_duration_seconds' => 6]);
    $iceBlock = playbookSpell(4, 'Ice Block', ['cooldown_seconds' => 240]);
    $medallion = playbookSpell(5, "Gladiator's Medallion", ['cooldown_seconds' => 120]);
    $hoj = playbookSpell(6, 'Hammer of Justice', ['dr_category' => 'Stun', 'cast_type' => 'instant', 'pvp_duration_seconds' => 5]);
    $cyclone = (new Spell)->forceFill(['id' => 7, 'spell_id' => 33786, 'name' => 'Cyclone', 'dr_category' => 'Disorient', 'cast_type' => 'cast', 'pvp_duration_seconds' => 5]);
    $mystery = playbookSpell(8, 'Mystery Strike', ['cooldown_seconds' => 90]);
    // Kill-target control: players put Kidney Shot on the target and split on Maim, Binding Shot
    // and Chaos Nova (range decides the first two), and
    // Mystery Bash has never been seen, so it comes from the kit.
    $kidney = playbookSpell(9, 'Kidney Shot', ['dr_category' => 'Stun', 'cast_type' => 'instant', 'range_yards' => '5 yards']);
    $binding = playbookSpell(10, 'Binding Shot', ['dr_category' => 'Stun', 'cast_type' => 'instant', 'range_yards' => '30 yards']);
    $maim = playbookSpell(12, 'Maim', ['dr_category' => 'Stun', 'cast_type' => 'instant', 'range_yards' => '5 yards']);
    $chaosNova = playbookSpell(13, 'Chaos Nova', ['dr_category' => 'Stun', 'cast_type' => 'instant']);
    $mysteryBash = playbookSpell(11, 'Mystery Bash', ['dr_category' => 'Stun', 'cast_type' => 'instant']);

    $offensive = ['offensive' => true, 'defensive' => false, 'label' => 'Offensive Buff'];
    $defensive = ['offensive' => false, 'defensive' => true, 'label' => 'Defensive'];

    $comp = [
        playbookMember('Paladin', 'Holy', 65, [playbookEntry($hoj)], 1),
        playbookMember('Mage', 'Fire', 63, [
            playbookEntry($pyroblast, ['offensiveDefensive' => $offensive]),
            playbookEntry($combustion, ['offensiveDefensive' => $offensive]),
            playbookEntry($polymorph),
            playbookEntry($iceBlock, ['offensiveDefensive' => $defensive]),
            playbookEntry($medallion, ['offensiveDefensive' => $defensive]),
            playbookEntry($binding),
        ], 2),
        // A spec nobody has measured goes in: it must say so, not guess a button.
        playbookMember('Druid', 'Unmeasured', 99999, [
            playbookEntry($mystery, ['offensiveDefensive' => $offensive]),
            playbookEntry($cyclone),
            playbookEntry($kidney),
            playbookEntry($mysteryBash),
            playbookEntry($maim),
            playbookEntry($chaosNova),
        ], 3),
    ];

    // The formula's chain: only the unmeasured player's step is used (Cyclone).
    $chain = ['primary' => [
        'poolEmpty' => false,
        'sequence' => [
            ['spell' => $hoj, 'label' => 'Holy Paladin'],
            ['spell' => $cyclone, 'label' => 'Unmeasured Druid'],
        ],
        'killTarget' => null,
    ]];

    $synergies = ['owner_map' => [], 'cooldown_by_id' => [], 'interrupts' => collect(), 'peels' => collect()];

    return [$comp, $chain, $synergies];
}

test('the guide waits for all three slots', function () {
    [$comp, $chain, $synergies] = playbookFixture();
    $comp[2]['spec'] = null;

    expect(app(CompPlaybookService::class)->build($comp, [], $chain, $synergies))->toBeNull();
});

test('the healer lock joins each spec\'s usual combo, stuns first, and says what can be kicked', function () {
    [$comp, $chain, $synergies] = playbookFixture();
    $pb = app(CompPlaybookService::class)->build($comp, [1 => 'healer'], $chain, $synergies);

    $steps = collect($pb['lock']['steps']);
    $names = $steps->map(fn ($s) => collect($s['options'])->pluck('spell.name')->implode(' or '))->all();
    $notes = $steps->map(fn ($s) => implode(' ', $s['notes']));

    // Paladin and Mage from their measured runs on the healer; the unmeasured Druid from the formula.
    expect($names)->toBe(['Hammer of Justice', 'Polymorph', 'Cyclone'])
        ->and($pb['lock']['seconds'])->toBe(16.0)
        ->and($notes[0])->toContain('cannot be kicked')
        ->and($notes[1])->toContain('kick can stop it')->toContain('Breaks if their healer takes damage')
        // Cyclone is a Disorient that does not break: its target is immune instead.
        ->and($notes[2])->toContain('cannot be hit or healed')->not->toContain('Breaks')
        ->and($steps[1]['options'][0]['mi'])->toBe(1);
});

test('kill-target control comes from where players put it, then from the kit', function () {
    [$comp, $chain, $synergies] = playbookFixture();
    $pb = app(CompPlaybookService::class)->build($comp, [1 => 'healer'], $chain, $synergies);

    $keep = collect($pb['keep'])->keyBy(fn ($k) => $k['spell']->name);

    expect($keep->has('Kidney Shot'))->toBeTrue()
        ->and($keep['Kidney Shot']['share'])->toBeGreaterThan(0.5)
        ->and($keep['Kidney Shot']['split'])->toBeFalse()
        // Players split on Maim and Binding Shot; range settles it (Chriso): the melee one goes on
        // the kill target the melee is already hitting, the ranged one reaches their healer.
        ->and($keep['Maim']['split'])->toBeFalse()
        ->and($keep->has('Binding Shot'))->toBeFalse()
        // Split with no range in the data: shown both ways.
        ->and($keep['Chaos Nova']['split'])->toBeTrue()
        ->and($keep['Mystery Bash']['share'])->toBeNull()
        // The healer's own stun is in the lock, so it is not offered for the kill target too.
        ->and($keep->has('Hammer of Justice'))->toBeFalse();
});

test('the burst comes from play, and an unmeasured spec says so instead of guessing', function () {
    [$comp, $chain, $synergies] = playbookFixture();
    $pb = app(CompPlaybookService::class)->build($comp, [1 => 'healer'], $chain, $synergies);

    $byMember = collect($pb['burst']['players'])->keyBy('mi');

    // Fire is measured: Combustion is pressed in most of its goes, Pyroblast in few.
    expect(collect($byMember[1]['buttons'])->pluck('entry.spell.name')->all())->toBe(['Combustion'])
        ->and($byMember[2]['buttons'])->toBe([])
        ->and($byMember->has(0))->toBeFalse();
});

test('defensives leave out the Medallion', function () {
    [$comp, $chain, $synergies] = playbookFixture();
    $pb = app(CompPlaybookService::class)->build($comp, [1 => 'healer'], $chain, $synergies);

    expect(collect($pb['defensives'][1])->pluck('spell.name')->all())->toBe(['Ice Block']);
});

test('the comps page shows the basics before anything is picked', function () {
    $this->get(route('wow-comps'))
        ->assertOk()
        ->assertSee('How a game is won')
        ->assertSee('Use line of sight')
        ->assertSee(route('wow-basics'), false)
        ->assertDontSee('Never crowd control the player you are hitting');
});
