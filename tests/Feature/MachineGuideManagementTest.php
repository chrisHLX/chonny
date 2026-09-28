<?php

namespace Tests\Feature;

use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Models\User;
use App\Models\UserGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The site admin manages machine-drafted guides; nobody but its author manages a person's guide.
 * And a guide drawn from observed play carries its level of play (guides-from-play.md).
 */
class MachineGuideManagementTest extends TestCase
{
    use RefreshDatabase;

    private function guide(User $owner, ?string $model, UserGuideStatus $status = UserGuideStatus::Draft): UserGuide
    {
        return UserGuide::create([
            'user_id' => $owner->id,
            'authored_by_model' => $model,
            'type' => UserGuideType::Comp,
            'title' => 'Walking Dead',
            'slug' => 'walking-dead-'.$owner->id.'-'.($model ? 'm' : 'h'),
            'status' => $status,
            'visibility' => UserGuideVisibility::Public,
        ]);
    }

    public function test_the_admin_manages_a_machine_guide_and_can_read_its_draft(): void
    {
        $engine = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $guide = $this->guide($engine, 'Claude Opus 5');

        $this->assertTrue($guide->isManagedBy($admin));
        $this->assertTrue($guide->isEditableBy($admin));
        $this->assertTrue($guide->isReadableBy($admin), 'the admin must be able to read an unpublished machine draft');
        $this->assertFalse($guide->isOwnedBy($admin), 'managing is not owning: the URL stays the engine account\'s');
    }

    public function test_the_admin_does_not_manage_a_persons_guide(): void
    {
        $player = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $guide = $this->guide($player, null);

        $this->assertFalse($guide->isManagedBy($admin));
        $this->assertFalse($guide->isEditableBy($admin));
        $this->assertFalse($guide->isReadableBy($admin));
    }

    public function test_a_non_admin_does_not_manage_a_machine_guide(): void
    {
        $engine = User::factory()->create();
        $player = User::factory()->create();
        $guide = $this->guide($engine, 'Claude Opus 5');

        $this->assertFalse($guide->isManagedBy($player));
        $this->assertFalse($guide->isReadableBy($player));
    }

    public function test_the_admin_can_open_a_machine_draft_in_the_builder(): void
    {
        $engine = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $guide = $this->guide($engine, 'Claude Opus 5');

        $this->actingAs($admin)->get(route('guides.edit', ['guide' => $guide->slug]))->assertOk();
    }

    public function test_the_level_of_play_shows_on_the_guide(): void
    {
        $engine = User::factory()->create(['username' => 'mindcollector']);
        $guide = $this->guide($engine, 'Claude Opus 5', UserGuideStatus::Published);
        $guide->update(['evidence_level' => 'Gladiator', 'evidence_games' => 7, 'evidence_note' => '26 Sep 2026 · 4 won, 3 lost']);

        $this->get(route('guides.show', ['username' => 'mindcollector', 'guide' => $guide->slug]))
            ->assertOk()
            ->assertSee('Gladiator level')
            ->assertSee('drawn from 7 observed games')
            ->assertSee('26 Sep 2026');
    }
}
