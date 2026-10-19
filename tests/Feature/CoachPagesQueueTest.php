<?php

namespace Tests\Feature;

use App\Jobs\BuildCoachPages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A game's card first, the rest after (2026-10-19): an upload builds only the cards, on the queue
 * the workers take first, and that build queues Improve, Comps and Classes behind it. Neither puts
 * back what the other wrote to the player's index.
 */
class CoachPagesQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_go_first_on_their_own_queue_and_queue_the_rest(): void
    {
        Queue::fake();
        BuildCoachPages::cardsFirst(7);
        Queue::assertPushedOn(BuildCoachPages::CARDS_QUEUE, BuildCoachPages::class, fn ($job) => $job->userId === 7 && $job->cardsOnly);

        $user = User::factory()->create();
        File::deleteDirectory(BuildCoachPages::dir($user->id));
        (new BuildCoachPages($user->id, true))->handle();

        Queue::assertPushed(BuildCoachPages::class, fn ($job) => $job->userId === $user->id && ! $job->cardsOnly && $job->queue === null);
        File::deleteDirectory(BuildCoachPages::dir($user->id));
    }

    public function test_a_cards_only_build_leaves_the_other_pages_in_the_index(): void
    {
        $user = User::factory()->create();
        $dir = BuildCoachPages::dir($user->id);
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/index.json", json_encode(['improveSig' => 'kept', 'comps' => ['x' => ['file' => 'comp-x.html']], 'games' => ['old' => []]]));

        $this->artisan('wow:game-cards', ['--user' => $user->id, '--dir' => $dir, '--web' => true, '--cards-only' => true])->assertSuccessful();

        $index = json_decode(File::get("{$dir}/index.json"), true);
        $this->assertSame('kept', $index['improveSig']);
        $this->assertArrayHasKey('x', $index['comps']);
        $this->assertSame([], $index['games'], 'the cards are this build\'s to write');
        File::deleteDirectory($dir);
    }
}
