<?php

use App\Http\Services\ArenaLogService;

/**
 * Covers ArenaLogService::extractCombatantInfoFromLog() against the two shapes the talent
 * bracket takes in real 12.1.0 logs. The second, `[,(`, was found 2026-09-30 when every Beast
 * Mastery Hunter in a set of reviewed games came back with no talents: 108 of 3,598 archived
 * COMBATANT_INFO lines open that way, all of them Hunters.
 */
function combatantInfoLine(string $guid, string $talentBracket): string
{
    $stats = implode(',', array_fill(0, 22, '0'));

    return "9/30/2026 18:11:47.2001  COMBATANT_INFO,{$guid},1,{$stats},253,{$talentBracket},(0,356962,203340,53480),"
        .'[(271555,344,(),(13452),()),(240952,331,(),(),())],[],0,0,0,0';
}

test('reads a talent bracket that opens with a tuple', function () {
    $info = app(ArenaLogService::class)->extractCombatantInfoFromLog(
        combatantInfoLine('Player-1-AAA', '[(94962,117559,1),(102364,126426,2)]'), 'Player-1-AAA'
    );

    expect($info)->not->toBeNull()
        ->and($info['talents'])->toBe([
            ['nodeId' => 94962, 'entryId' => 117559, 'rank' => 1],
            ['nodeId' => 102364, 'entryId' => 126426, 'rank' => 2],
        ])
        ->and($info['pvpTalentIds'])->toBe([356962, 203340, 53480]);
});

test('reads a talent bracket that opens with an empty entry, as Hunters\' do', function () {
    $info = app(ArenaLogService::class)->extractCombatantInfoFromLog(
        combatantInfoLine('Player-1-BBB', '[,(94962,117559,1),(102364,126426,2)]'), 'Player-1-BBB'
    );

    expect($info)->not->toBeNull()
        ->and($info['talents'])->toBe([
            ['nodeId' => 94962, 'entryId' => 117559, 'rank' => 1],
            ['nodeId' => 102364, 'entryId' => 126426, 'rank' => 2],
        ])
        ->and($info['pvpTalentIds'])->toBe([356962, 203340, 53480])
        ->and($info['gear']['itemLevels'])->toBe([331, 344]);
});
