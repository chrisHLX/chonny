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
    $polymorph = playbookSpell(3, 'Polymorph', ['dr_category' => 'Incapacitate']);
    $iceBlock = playbookSpell(4, 'Ice Block', ['cooldown_seconds' => 240]);
    $medallion = playbookSpell(5, "Gladiator's Medallion", ['cooldown_seconds' => 120]);
    $hoj = playbookSpell(6, 'Hammer of Justice', ['dr_category' => 'Stun']);
    $cyclone = (new Spell)->forceFill(['id' => 7, 'spell_id' => 33786, 'name' => 'Cyclone', 'dr_category' => 'Disorient']);
    $mystery = playbookSpell(8, 'Mystery Strike', ['cooldown_seconds' => 90]);

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
        ], 2),
        // A spec nobody has measured goes in: it must say so, not guess a button.
        playbookMember('Druid', 'Unmeasured', 99999, [playbookEntry($mystery, ['offensiveDefensive' => $offensive]), playbookEntry($cyclone)], 3),
    ];

    $chain = ['primary' => [
        'poolEmpty' => false,
        'sequence' => [
            ['spell' => $hoj, 'label' => 'Holy Paladin', 'stealthNote' => null, 'castType' => 'instant', 'durationSeconds' => 5.0],
            ['spell' => $polymorph, 'label' => 'Fire Mage', 'stealthNote' => null, 'castType' => 'cast', 'durationSeconds' => 6.0],
            ['spell' => $cyclone, 'label' => 'Unmeasured Druid', 'stealthNote' => null, 'castType' => 'cast', 'durationSeconds' => 5.0],
        ],
        'killTarget' => null,
    ]];

    $synergies = [
        'groups' => ['Diminishing Returns Groups' => collect([$hoj, $polymorph, $cyclone])],
        'dr_by_id' => [6 => 'Stun', 3 => 'Incapacitate', 7 => 'Disorient'],
        'owner_map' => [6 => 0, 3 => 1, 7 => 2],
        'cooldown_by_id' => [],
        'interrupts' => collect(),
        'peels' => collect(),
    ];

    return [$comp, $chain, $synergies];
}

test('the guide waits for all three slots', function () {
    [$comp, $chain, $synergies] = playbookFixture();
    $comp[2]['spec'] = null;

    expect(app(CompPlaybookService::class)->build($comp, [], $chain, $synergies))->toBeNull();
});

test('the healer lock says what can be kicked and what damage does to it', function () {
    [$comp, $chain, $synergies] = playbookFixture();
    $pb = app(CompPlaybookService::class)->build($comp, [1 => 'healer'], $chain, $synergies);

    $notes = collect($pb['lock']['steps'])->map(fn ($s) => implode(' ', $s['notes']));

    expect($pb['lock']['seconds'])->toBe(16.0)
        ->and($notes[0])->toContain('cannot be kicked')
        ->and($notes[1])->toContain('kick can stop it')->toContain('Breaks if their healer takes damage')
        // Cyclone is a Disorient that does not break: its target is immune instead.
        ->and($notes[2])->toContain('cannot be hit or healed')->not->toContain('Breaks')
        ->and($pb['lock']['steps'][1]['mi'])->toBe(1);
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

test('breakable control, and defensives without the Medallion', function () {
    [$comp, $chain, $synergies] = playbookFixture();
    $pb = app(CompPlaybookService::class)->build($comp, [1 => 'healer'], $chain, $synergies);

    $breakable = collect($pb['breakable'])->keyBy(fn ($x) => $x['spell']->name);

    expect($breakable->keys()->all())->toBe(['Polymorph', 'Cyclone'])
        ->and($breakable['Cyclone']['immune'])->toBeTrue()
        ->and(collect($pb['defensives'][1])->pluck('spell.name')->all())->toBe(['Ice Block']);
});

test('the comps page shows the basics before anything is picked', function () {
    $this->get(route('wow-comps'))
        ->assertOk()
        ->assertSee('How a game is won')
        ->assertSee('Never crowd control the player you are hitting');
});
