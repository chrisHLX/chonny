<?php

namespace Tests\Feature;

use App\Livewire\GameReviewAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The raw tool output page. It names every opponent, so the tests are about who can see what:
 * a guest sees nothing, a player sees only their own directory, and the real URL renders with its
 * layout (a Livewire::test() alone passes without one — see CLAUDE.md, "Looking at a page").
 */
class GameReviewAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('game-review.analysis'))->assertRedirect(route('login'));
    }

    public function test_the_owner_sees_their_own_output(): void
    {
        $user = User::factory()->create();
        Storage::disk('local')->put(GameReviewAnalysis::directory($user->id).'/killread-26sep.txt', "=== REVIEW TABLE\n| 19:54 | L |");

        $this->actingAs($user)
            ->get(route('game-review.analysis'))
            ->assertOk()
            ->assertSee('killread-26sep')
            ->assertSee('=== REVIEW TABLE');
    }

    public function test_another_players_output_is_never_shown(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Storage::disk('local')->put(GameReviewAnalysis::directory($owner->id).'/killread.txt', 'OWNER-ONLY-OUTPUT');

        $this->actingAs($other)
            ->get(route('game-review.analysis'))
            ->assertOk()
            ->assertDontSee('OWNER-ONLY-OUTPUT')
            ->assertSee('No tool output has been uploaded');
    }

    public function test_analysis_is_not_read_as_a_review_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/wow/game-review/analysis')->assertOk()->assertSee('Raw analysis');
    }
}
