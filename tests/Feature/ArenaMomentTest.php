<?php

namespace Tests\Feature;

use App\Http\Services\ArenaMomentService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moment detection: the instants in a round when somebody committed, and what followed.
 *
 * The fixtures build real spell rows rather than mocking, because what counts as a commitment is
 * decided by `spells.cooldown_seconds` and the whole point of these tests is that the rule is the
 * cooldown and nothing else.
 *
 * Log lines are built to the REAL field counts. Every offset in the parser is read from the end of
 * the line, so a fixture of the wrong length would pass while the real thing failed.
 */
class ArenaMomentTest extends TestCase
{
    use RefreshDatabase;

    private int $patchId;

    protected function setUp(): void
    {
        parent::setUp();

        $game = Game::create(['name' => 'WoW', 'slug' => 'wow']);
        $this->patchId = Patch::create([
            'game_id' => $game->id, 'build_version' => '12.1.0.test', 'is_current' => true,
        ])->id;

        // Who counts as a healer is resolved through specializations, so the roster needs real
        // rows — without them nobody is a healer and control on one is silently never reported.
        $priest = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
        $warrior = GameClass::create(['game_id' => $game->id, 'name' => 'Warrior', 'slug' => 'warrior']);
        $mage = GameClass::create(['game_id' => $game->id, 'name' => 'Mage', 'slug' => 'mage']);

        Specialization::create(['class_id' => $priest->id, 'name' => 'Discipline', 'slug' => 'discipline', 'external_spec_id' => 256]);
        Specialization::create(['class_id' => $warrior->id, 'name' => 'Arms', 'slug' => 'arms', 'external_spec_id' => 71]);
        Specialization::create(['class_id' => $mage->id, 'name' => 'Fire', 'slug' => 'fire', 'external_spec_id' => 63]);
    }

    private function spell(int $id, string $name, ?int $cooldown = null, ?string $dr = null): void
    {
        Spell::create([
            'patch_id' => $this->patchId, 'spell_id' => $id, 'name' => $name,
            'cooldown_seconds' => $cooldown, 'dr_category' => $dr,
        ]);
    }

    /** Pads to the real field count, which is what the parser's end-relative offsets depend on. */
    private function line(string $event, array $lead, array $tail, int $total, string $clock): string
    {
        $pad = $total - 1 - count($lead) - count($tail);
        $this->assertGreaterThanOrEqual(0, $pad, "Fixture for {$event} is longer than the real event.");

        return "9/25/2026 {$clock}  ".implode(',', array_merge([$event], $lead, array_fill(0, $pad, '0'), $tail));
    }

    private function cast(string $who, int $spellId, string $name, string $clock): string
    {
        return "9/25/2026 {$clock}  SPELL_CAST_SUCCESS,{$who},\"Caster\",0x511,0x0,0000000000000000,nil,0x0,0x0,{$spellId},\"{$name}\",0x1";
    }

    /**
     * A damage event, carrying the victim's health in the advanced-parameter block — which is
     * where the parser reads it from, at base+2 and base+3.
     */
    private function damage(string $src, string $dst, int $amount, int $curHp, int $maxHp, string $clock): string
    {
        $advanced = array_fill(0, 17, '0');
        $advanced[2] = (string) $curHp;
        $advanced[3] = (string) $maxHp;

        return $this->line(
            'SPELL_DAMAGE',
            array_merge([$src, '"Src"', '0x511', '0x0', $dst, '"Dst"', '0x548', '0x0', '589', '"Pain"', '0x20'], $advanced),
            [(string) $amount, (string) $amount, '-1', '32', '0', '0', '0', 'nil', 'nil', 'nil', 'ST'],
            42,
            $clock
        );
    }

    private function aura(string $event, string $src, string $dst, int $spellId, string $name, string $clock): string
    {
        return "9/25/2026 {$clock}  {$event},{$src},\"Src\",0x511,0x0,{$dst},\"Dst\",0x548,0x0,{$spellId},\"{$name}\",0x1,DEBUFF";
    }

    private function metadata(): array
    {
        return ['units' => [
            ['id' => 'Player-A', 'name' => 'Ally-Realm', 'spec' => '71', 'reaction' => 1, 'affiliation' => 1],
            ['id' => 'Player-B', 'name' => 'Mate-Realm', 'spec' => '63', 'reaction' => 1, 'affiliation' => 2],
            ['id' => 'Player-F', 'name' => 'Foe-Realm', 'spec' => '256', 'reaction' => 2, 'affiliation' => 8],
        ]];
    }

    public function test_a_moment_is_found_from_cooldowns_not_from_a_fixed_window(): void
    {
        $this->spell(1, 'Avatar', 90);
        $this->spell(2, 'Recklessness', 90);

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 1, 'Avatar', '10:00:10.0000'),
            $this->cast('Player-B', 2, 'Recklessness', '10:00:12.0000'),
            $this->damage('Player-A', 'Player-F', 500000, 400000, 1000000, '10:00:13.0000'),
        ], $this->metadata());

        $this->assertCount(1, $moments);
        $this->assertSame(0.0, $moments[0]['from']);
        // The window ends a tail after the LAST commitment, not at an arbitrary clock.
        $this->assertSame(2.0 + ArenaMomentService::MOMENT_TAIL_SECONDS, $moments[0]['to']);
        $this->assertSame('one-sided', $moments[0]['kind']);
    }

    public function test_a_rotational_spell_is_not_a_commitment_however_it_is_classified(): void
    {
        // The regression this guards: the hand-reviewed classification lists Penance, which is on
        // a nine-second cooldown. Letting the classification decide significance produced
        // "moments" that were one player casting Penance.
        $this->spell(47540, 'Penance', 9);

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 47540, 'Penance', '10:00:10.0000'),
            $this->cast('Player-A', 47540, 'Penance', '10:00:20.0000'),
        ], $this->metadata());

        $this->assertSame([], $moments);
    }

    public function test_one_press_logged_twice_is_counted_once(): void
    {
        // Power Infusion on self and on an ally logs twice. Counted twice, it inflates how much a
        // side committed and made "three cooldowns" read as a tighter go than it was.
        $this->spell(10060, 'Power Infusion', 120);

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 10060, 'Power Infusion', '10:00:10.0000'),
            $this->cast('Player-A', 10060, 'Power Infusion', '10:00:10.2000'),
        ], $this->metadata());

        $this->assertCount(1, $moments);
        $this->assertCount(1, $moments[0]['committed'][1]);
    }

    public function test_a_long_enough_lull_starts_a_new_moment(): void
    {
        $this->spell(1, 'Avatar', 90);
        $this->spell(2, 'Recklessness', 90);

        $gap = (int) ArenaMomentService::MOMENT_GAP_SECONDS + 5;

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 1, 'Avatar', '10:00:10.0000'),
            $this->cast('Player-A', 2, 'Recklessness', sprintf('10:00:%02d.0000', 10 + $gap)),
        ], $this->metadata());

        $this->assertCount(2, $moments);
    }

    public function test_both_sides_committing_in_one_window_is_a_trade(): void
    {
        $this->spell(1, 'Avatar', 90);
        $this->spell(3, 'Pain Suppression', 180);

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 1, 'Avatar', '10:00:10.0000'),
            $this->cast('Player-F', 3, 'Pain Suppression', '10:00:12.0000'),
        ], $this->metadata());

        $this->assertCount(1, $moments);
        $this->assertSame('trade', $moments[0]['kind']);
        $this->assertArrayHasKey(1, $moments[0]['committed']);
        $this->assertArrayHasKey(2, $moments[0]['committed']);
    }

    public function test_control_on_a_healer_records_who_broke_it(): void
    {
        // The actionable half is the name. "Your own Shadow Word: Pain broke your Warrior's fear"
        // is a fixable habit; "the fear ended early" is not.
        $this->spell(1, 'Avatar', 90);
        $this->spell(118, 'Polymorph', 15, 'Incapacitate');

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 1, 'Avatar', '10:00:10.0000'),
            $this->aura('SPELL_AURA_APPLIED', 'Player-A', 'Player-F', 118, 'Polymorph', '10:00:10.5000'),
            $this->damage('Player-B', 'Player-F', 1000, 900000, 1000000, '10:00:11.5000'),
            $this->aura('SPELL_AURA_REMOVED', 'Player-A', 'Player-F', 118, 'Polymorph', '10:00:12.0000'),
        ], $this->metadata());

        $control = $moments[0]['controlOnHealer'];

        $this->assertCount(1, $control);
        $this->assertSame('Polymorph', $control[0]['spell']);
        $this->assertSame(['Mate-Realm'], $control[0]['brokenBy']);
    }

    public function test_control_left_alone_is_not_reported_as_broken(): void
    {
        $this->spell(1, 'Avatar', 90);
        $this->spell(118, 'Polymorph', 15, 'Incapacitate');

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 1, 'Avatar', '10:00:10.0000'),
            $this->aura('SPELL_AURA_APPLIED', 'Player-A', 'Player-F', 118, 'Polymorph', '10:00:10.5000'),
            $this->aura('SPELL_AURA_REMOVED', 'Player-A', 'Player-F', 118, 'Polymorph', '10:00:14.0000'),
        ], $this->metadata());

        $this->assertSame([], $moments[0]['controlOnHealer'][0]['brokenBy']);
        $this->assertSame(3.5, $moments[0]['controlOnHealer'][0]['held']);
    }

    public function test_a_garrote_bleed_is_not_read_as_a_silence(): void
    {
        // 703 is curated as a Silence because pressing it silences, but its aura in the log is the
        // bleed. The silence is 1330. Reading the bleed as control showed a healer locked out for
        // eighteen seconds at a time.
        $this->spell(1, 'Avatar', 90);
        $this->spell(703, 'Garrote', null, 'Silence');
        $this->spell(1330, 'Garrote - Silence', null, 'Silence');

        $timeline = app(ArenaMomentService::class)->readTimeline([
            $this->cast('Player-A', 1, 'Avatar', '10:00:10.0000'),
            $this->aura('SPELL_AURA_APPLIED', 'Player-A', 'Player-F', 703, 'Garrote', '10:00:10.5000'),
            $this->aura('SPELL_AURA_APPLIED', 'Player-A', 'Player-F', 1330, 'Garrote - Silence', '10:00:10.5000'),
            $this->aura('SPELL_AURA_REMOVED', 'Player-A', 'Player-F', 1330, 'Garrote - Silence', '10:00:13.5000'),
            $this->aura('SPELL_AURA_REMOVED', 'Player-A', 'Player-F', 703, 'Garrote', '10:00:28.5000'),
        ], app(ArenaMomentService::class)->roster($this->metadata()));

        $this->assertCount(1, $timeline['control']);
        $this->assertSame('Garrote - Silence', $timeline['control'][0]['spell']);
        $this->assertSame(3.0, $timeline['control'][0]['to'] - $timeline['control'][0]['from']);
    }

    public function test_control_from_a_totem_does_not_crash_or_guess_who_broke_it(): void
    {
        // A Capacitor Totem stuns, and a totem is not in the roster — so there is no side to
        // attribute the control to, and no such thing as "the caster's own damage". Before this
        // was guarded, an unknown caster matched every other unknown damage source and the name
        // lookup threw on a real round.
        $this->spell(1, 'Avatar', 90);
        $this->spell(118345, 'Capacitor Totem', 60, 'Stun');
        $this->spell(119, 'Polymorph', 15, 'Incapacitate');

        $totem = 'Creature-0-1234-5678-9012-118345-0000ABCDEF';

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 1, 'Avatar', '10:00:10.0000'),
            $this->aura('SPELL_AURA_APPLIED', $totem, 'Player-F', 119, 'Polymorph', '10:00:10.5000'),
            // Damage from another non-player source, which used to be read as the same side.
            $this->damage($totem, 'Player-F', 1000, 900000, 1000000, '10:00:11.0000'),
            $this->aura('SPELL_AURA_REMOVED', $totem, 'Player-F', 119, 'Polymorph', '10:00:12.0000'),
        ], $this->metadata());

        $control = $moments[0]['controlOnHealer'];

        $this->assertCount(1, $control);
        $this->assertSame('a pet or totem', $control[0]['by']);
        $this->assertSame([], $control[0]['brokenBy'], 'An unattributable caster has no own-team damage.');
    }

    public function test_self_damage_is_not_counted_as_output(): void
    {
        // A health-cost ability, or damage a player redirects onto themselves, otherwise counts
        // toward their own team's damage and puts a peak on the wrong side of the scoreboard.
        $this->spell(1, 'Avatar', 90);

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 1, 'Avatar', '10:00:10.0000'),
            $this->damage('Player-F', 'Player-F', 900000, 100000, 1000000, '10:00:11.0000'),
        ], $this->metadata());

        $this->assertSame([], $moments[0]['damageBySide']);
        $this->assertNull($moments[0]['pressure']);
    }

    public function test_the_pressured_player_and_their_health_are_reported(): void
    {
        $this->spell(1, 'Avatar', 90);

        $moments = app(ArenaMomentService::class)->detect([
            $this->cast('Player-A', 1, 'Avatar', '10:00:10.0000'),
            $this->damage('Player-A', 'Player-F', 300000, 700000, 1000000, '10:00:11.0000'),
            $this->damage('Player-A', 'Player-F', 400000, 300000, 1000000, '10:00:12.0000'),
        ], $this->metadata());

        $pressure = $moments[0]['pressure'];

        $this->assertSame('Foe-Realm', $pressure['who']);
        $this->assertSame(70.0, $pressure['from']);
        $this->assertSame(30.0, $pressure['low']);
        $this->assertSame(700000, $pressure['damageTaken']);
    }
}
