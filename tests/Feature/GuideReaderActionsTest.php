<?php

namespace Tests\Feature;

use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Livewire\Guides\Show;
use App\Models\Game;
use App\Models\Patch;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideAccuracyVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a reader can DO on a guide (2026-09-29): vote whether it is accurate for the current patch
 * with no account at all, and copy it into their own planner, which is where an account is asked
 * for. Reading stays free.
 */
class GuideReaderActionsTest extends TestCase
{
    use RefreshDatabase;

    private function publishedGuide(): UserGuide
    {
        $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
        Patch::create(['game_id' => $game->id, 'build_version' => '12.1.0.69933', 'is_current' => true]);
        $engine = User::factory()->create(['username' => 'mindcollector']);

        return UserGuide::create([
            'user_id' => $engine->id, 'authored_by_model' => 'Claude Opus 5', 'type' => UserGuideType::Comp,
            'title' => 'Walking Dead', 'slug' => 'walking-dead', 'status' => UserGuideStatus::Published,
            'visibility' => UserGuideVisibility::Public,
        ]);
    }

    private function show(UserGuide $guide, ?User $as = null)
    {
        return ($as ? Livewire::actingAs($as) : Livewire::withoutLazyLoading())
            ->test(Show::class, ['username' => 'mindcollector', 'guide' => $guide]);
    }

    public function test_a_guest_can_vote_without_an_account(): void
    {
        $guide = $this->publishedGuide();

        $this->show($guide)->call('voteAccuracy', true);

        $vote = UserGuideAccuracyVote::sole();
        $this->assertTrue($vote->accurate);
        $this->assertSame('12.1', $vote->build_version, 'votes are about the patch as players say it');
        $this->assertNull($vote->user_id);
    }

    public function test_one_vote_per_reader_and_it_can_be_changed(): void
    {
        $guide = $this->publishedGuide();
        $reader = User::factory()->create();

        $page = $this->show($guide, $reader);
        $page->call('voteAccuracy', true)->call('voteAccuracy', false);

        $this->assertSame(1, UserGuideAccuracyVote::count());
        $this->assertFalse(UserGuideAccuracyVote::sole()->accurate);
        $this->assertSame(['yes' => 0, 'no' => 1, 'mine' => false], $page->instance()->accuracy());
    }

    public function test_copying_gives_a_signed_in_reader_a_private_draft_in_the_builder(): void
    {
        $guide = $this->publishedGuide();
        $reader = User::factory()->create();

        $this->show($guide, $reader)->call('copyToPlanner')->assertRedirectContains('/guides/');

        $copy = UserGuide::where('user_id', $reader->id)->sole();
        $this->assertSame(UserGuideStatus::Draft, $copy->status);
        $this->assertNull($copy->authored_by_model, 'the copy is the reader\'s own plan, not a model\'s');
        $this->assertSame(UserGuideStatus::Published, $guide->fresh()->status, 'the original is untouched');
    }

    public function test_a_guest_who_copies_is_sent_to_sign_up_and_back_to_the_guide(): void
    {
        $guide = $this->publishedGuide();

        $this->show($guide)->call('copyToPlanner')->assertRedirect(route('register'));

        $this->assertSame($guide->publicUrl(), session('url.intended'));
        $this->assertSame(1, UserGuide::count(), 'a guest copies nothing');
    }
}
