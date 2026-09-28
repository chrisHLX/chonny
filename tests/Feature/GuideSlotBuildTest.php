<?php

namespace Tests\Feature;

use App\Enums\UserGuideMemberSide;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Http\Services\CharacterTalentResolver;
use App\Livewire\Guides\Show;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\TalentBuild;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A guide slot's own talent build is SHOWN on the guide page, not only used to resolve cooldowns
 * behind the scenes: a guide drawn from play explains its rotation through the talents the player
 * actually had, so a reader has to be able to open them.
 */
class GuideSlotBuildTest extends TestCase
{
    use RefreshDatabase;

    private function guideWithSlotBuild(): array
    {
        $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
        $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.1.0.69933', 'is_current' => true]);
        $class = GameClass::create(['game_id' => $game->id, 'name' => 'Death Knight', 'slug' => 'deathknight']);
        $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Unholy', 'slug' => 'unholy', 'external_spec_id' => 252]);
        $engine = User::factory()->create(['username' => 'mindcollector']);

        $guide = UserGuide::create([
            'user_id' => $engine->id, 'authored_by_model' => 'Claude Opus 5', 'type' => UserGuideType::ClassGuide,
            'title' => 'Unholy DK', 'slug' => 'unholy-dk', 'status' => UserGuideStatus::Published,
            'visibility' => UserGuideVisibility::Public,
        ]);
        $build = TalentBuild::create(['user_id' => null, 'spec_id' => $spec->id, 'patch_id' => $patch->id, 'is_default' => false, 'name' => 'Guide build', 'share_slug' => 'test-slot-build']);
        UserGuideMember::create([
            'user_guide_id' => $guide->id, 'side' => UserGuideMemberSide::Team->value, 'position' => 0,
            'spec_id' => $spec->id, 'talent_build_id' => $build->id,
        ]);

        return [$guide, $build, $spec];
    }

    public function test_a_saved_build_resolves_to_the_calculator_view(): void
    {
        [, $build, $spec] = $this->guideWithSlotBuild();

        $view = app(CharacterTalentResolver::class)->forBuild($build->fresh());

        $this->assertSame($spec->id, $view['spec']->id);
        $this->assertSame([], $view['chosenEntries']);
        $this->assertSame([], $view['pvpTalentIds']);
        $this->assertSame([], $view['unresolved'], 'a saved build is already resolved: nothing is ever unresolved');
    }

    public function test_the_guide_page_offers_the_slot_build(): void
    {
        [$guide] = $this->guideWithSlotBuild();

        $this->get(route('guides.show', ['username' => 'mindcollector', 'guide' => $guide->slug]))
            ->assertOk()
            ->assertSee('Talents this guide is written for')
            ->assertSee('Unholy Death Knight');
    }

    public function test_opening_a_slot_only_reaches_this_guides_own_slots(): void
    {
        [$guide] = $this->guideWithSlotBuild();

        $component = Livewire::test(Show::class, ['username' => 'mindcollector', 'guide' => $guide]);
        $component->call('toggleSlotBuild', 0)->assertSet('openBuildSlot', 0);
        $this->assertNotNull($component->instance()->slotBuildView());

        // A position this guide has no slot for opens nothing.
        $component->call('toggleSlotBuild', 5)->assertSet('openBuildSlot', 5);
        $this->assertNull($component->instance()->slotBuildView());
    }
}
