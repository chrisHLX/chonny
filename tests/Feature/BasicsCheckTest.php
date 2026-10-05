<?php

use App\Quiz\Wow\BasicsCheck;
use App\Quiz\Wow\WowAbility;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The arena basics check (BasicsCheck): one question per basic, built from the player's own spec
 * where the data allows, and a page that offers it before anything is picked.
 */
function basicsAbility(string $name, array $attrs = []): WowAbility
{
    static $id = 0;

    return new WowAbility(...['spellId' => ++$id, 'name' => $name] + $attrs);
}

function basicsCheck(bool $healer = false, int $seed = 1): BasicsCheck
{
    $mine = [
        basicsAbility('Combustion', ['offensive' => true, 'cooldown' => 60]),
        basicsAbility('Polymorph', ['drCategory' => 'Incapacitate', 'castType' => 'cast', 'pvpDuration' => 6]),
        basicsAbility("Dragon's Breath", ['drCategory' => 'Disorient', 'castType' => 'instant', 'pvpDuration' => 3]),
        basicsAbility('Ice Block', ['defensive' => true, 'cooldown' => 240]),
        basicsAbility('Alter Time', ['defensive' => true, 'cooldown' => 60]),
    ];
    $pool = [
        basicsAbility('Hex', ['drCategory' => 'Incapacitate', 'castType' => 'cast', 'pvpDuration' => 6]),
        basicsAbility('Freezing Trap', ['drCategory' => 'Incapacitate', 'castType' => 'instant', 'pvpDuration' => 6]),
        basicsAbility('Hammer of Justice', ['drCategory' => 'Stun', 'castType' => 'instant', 'pvpDuration' => 5]),
        basicsAbility('Kidney Shot', ['drCategory' => 'Stun', 'castType' => 'instant', 'pvpDuration' => 6]),
        basicsAbility('Blind', ['drCategory' => 'Disorient', 'castType' => 'instant', 'pvpDuration' => 6]),
        basicsAbility('Scare Beast', ['drCategory' => 'Disorient', 'castType' => 'cast', 'pvpDuration' => 6]),
    ];
    $goData = ['specs' => [
        '63' => ['goes' => 100, 'players' => 10, 'spells' => [['name' => 'Combustion', 'share' => 0.95]]],
        '253' => ['goes' => 100, 'players' => 10, 'spells' => [['name' => 'Bestial Wrath', 'share' => 1.0]]],
    ]];

    return new BasicsCheck('Fire Mage', $mine, $pool, 63, $healer,
        [253 => ['label' => 'Beast Mastery Hunter', 'class' => 'Hunter'], 63 => ['label' => 'Fire Mage', 'class' => 'Mage']],
        'Mage', new Randomizer(new Mt19937($seed)), $goData);
}

test('one question per basic, in order, each with exactly one right answer', function () {
    $questions = basicsCheck()->build();

    expect(array_map(fn ($q) => BasicsCheck::basicOf($q->type), $questions))->toBe(array_keys(BasicsCheck::BASICS));

    foreach ($questions as $q) {
        expect(collect($q->options)->where('key', $q->correctKey))->toHaveCount(1)
            ->and(collect($q->options)->pluck('label')->unique())->toHaveCount(count($q->options));
    }
});

test('press together uses the player\'s measured go button and a partner of another class', function () {
    $q = collect(basicsCheck()->build())->first(fn ($q) => $q->type === 'basic:together');

    expect($q->prompt)->toContain('Combustion')->toContain('a Beast Mastery Hunter')->toContain('Bestial Wrath')
        ->and(collect($q->options)->firstWhere('key', $q->correctKey)['label'])->toStartWith('Now');

    // A healer gets the general form.
    $h = collect(basicsCheck(healer: true)->build())->first(fn ($q) => $q->type === 'basic:together');
    expect($h->prompt)->not->toContain('Combustion');
});

test('diminishing returns halves the second control of one kind, and never offers creature-only control', function () {
    foreach (range(1, 20) as $seed) {
        $questions = collect(basicsCheck(seed: $seed)->build());
        $dr = $questions->first(fn ($q) => $q->type === 'basic:dr');

        expect(collect($dr->options)->firstWhere('key', $dr->correctKey)['label'])->toContain('half its time')
            ->and($questions->pluck('prompt')->implode(' ').$questions->flatMap(fn ($q) => collect($q->options)->pluck('label'))->implode(' '))
            ->not->toContain('Scare Beast');
    }
});

test('a kick question offers one cast and three instants', function () {
    $q = collect(basicsCheck()->build())->first(fn ($q) => $q->type === 'basic:kicks');

    expect(collect($q->options)->firstWhere('key', $q->correctKey)['label'])->toBeIn(['Polymorph', 'Hex']);
});

test('the basics check page offers a spec picker', function () {
    $this->get(route('wow-basics'))
        ->assertOk()
        ->assertSee('Arena basics check')
        ->assertSee('Eight questions');

    $this->get(route('wow-basics', ['classSlug' => 'nope', 'specSlug' => 'nope']))->assertNotFound();
});
