<?php

namespace Tests\Feature;

use App\Http\Services\ArenaLogService;
use App\Http\Services\CooldownLedgerService;
use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\RoundAnalysisService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A player's stored buttons reach past the spec's default build, but only to buttons people press.
 *
 * Read against the committed classification files and population norms, which is the rule under
 * test: a build holds Berserk as well as the Incarnation that replaced it, and nobody presses it.
 */
class KitFromTalentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, float>  $cooldowns
     * @param  array<string, true>  $pressed
     */
    private function kit(array $talents, array $cooldowns, array $pressed = [], bool $takes = true): array
    {
        $class = GameClass::create(['game_id' => Game::create(['name' => 'WoW', 'slug' => 'wow'])->id, 'name' => 'Druid', 'slug' => 'druid']);
        $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Feral', 'slug' => 'feral', 'external_spec_id' => 103]);

        $this->app->instance(CooldownLedgerService::class, new class($talents, $cooldowns, $takes, app(ArenaLogService::class), app(ModuleSpellReferenceService::class)) extends CooldownLedgerService
        {
            public function __construct(private array $talents, private array $cooldowns, private bool $hasIt, ArenaLogService $arena, ModuleSpellReferenceService $refs)
            {
                parent::__construct($arena, $refs);
            }

            public function build(array $combatant, Specialization $spec): array
            {
                return ['selected' => collect(), 'ranks' => collect(), 'key' => 'test', 'names' => $this->talents];
            }

            public function profile(?string $classSlug, ?string $specSlug): array
            {
                return ['offensive' => [['name' => 'Feral Frenzy', 'cooldown' => 30]]];
            }

            public function takes(string $name, Specialization $spec, array $build): bool
            {
                return $this->hasIt;
            }

            public function cooldown(string $name, Specialization $spec, array $build): ?array
            {
                return isset($this->cooldowns[$name]) ? ['cd' => $this->cooldowns[$name], 'charges' => 1, 'base' => null] : null;
            }
        });

        return collect(app(RoundAnalysisService::class)->kitFor(['talents' => []], $spec, $pressed))->keyBy('name')->all();
    }

    public function test_pressing_a_button_proves_the_player_has_it_when_the_build_does_not_list_it(): void
    {
        $name = 'Incarnation: Avatar of Ashamane';

        $this->assertArrayHasKey($name, $this->kit([], [$name => 90.0], [$name => true], takes: false));
    }

    public function test_a_talent_the_player_neither_took_nor_pressed_stays_out(): void
    {
        $name = 'Incarnation: Avatar of Ashamane';

        $this->assertArrayNotHasKey($name, $this->kit([], [$name => 90.0], takes: false));
    }

    public function test_a_talent_outside_the_default_build_joins_the_kit_at_the_players_cooldown(): void
    {
        $kit = $this->kit(['Incarnation: Avatar of Ashamane'], ['Feral Frenzy' => 30.0, 'Incarnation: Avatar of Ashamane' => 90.0]);

        $this->assertSame('offensive', $kit['Incarnation: Avatar of Ashamane']['kind']);
        $this->assertSame(90.0, $kit['Incarnation: Avatar of Ashamane']['cd']);
        $this->assertArrayHasKey('Feral Frenzy', $kit);
    }

    public function test_a_talent_nobody_presses_stays_out(): void
    {
        $kit = $this->kit(['Berserk', 'Incarnation: Avatar of Ashamane'], ['Berserk' => 120.0, 'Incarnation: Avatar of Ashamane' => 90.0]);

        $this->assertArrayNotHasKey('Berserk', $kit, 'Moments would call it ready');
        $this->assertArrayHasKey('Incarnation: Avatar of Ashamane', $kit);
    }

    public function test_a_talent_with_no_cooldown_stays_out(): void
    {
        $this->assertArrayNotHasKey('Incarnation: Avatar of Ashamane', $this->kit(['Incarnation: Avatar of Ashamane'], []));
    }
}
