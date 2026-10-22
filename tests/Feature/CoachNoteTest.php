<?php

namespace Tests\Feature;

use App\Models\ArenaNote;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Notes written on a game from the browser (CoachController::note, 2026-10-22).
 *
 * The desktop app has written notes for a while, into a notes.json matched to rounds by time.
 * These are the same idea from the website: they name their game outright, so GameCardService's
 * attachNotes() files them without any timestamp matching.
 *
 * The rule worth testing is whose game it is. A lobby id is a guessable string, and this is the
 * first thing on /wow/coach that writes, so the lobby is checked against the viewer's own rounds
 * rather than trusted from the form.
 */
class CoachNoteTest extends TestCase
{
    use RefreshDatabase;

    private function roundFor(User $user, string $lobby): ArenaRound
    {
        return ArenaRound::create([
            'user_id' => $user->id,
            'lobby_id' => $lobby,
            'match_id' => $lobby,
            'roster_key' => md5($lobby),
            'bracket' => 'Rated Solo Shuffle',
            'sequence' => 1,
            'played_at' => now()->subHour(),
            'payload' => ['analysis' => ['players' => []]],
        ]);
    }

    public function test_a_player_can_write_a_note_on_their_own_game(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->roundFor($user, 'lobby-abc');

        $this->actingAs($user)
            ->post(route('coach.note'), ['lobby' => 'lobby-abc', 'body' => '  They opened on me every round.  '])
            ->assertRedirect(route('coach'))
            ->assertSessionHas('note_status');

        $note = ArenaNote::sole();
        $this->assertSame('lobby-abc', $note->lobby_id);
        $this->assertSame('They opened on me every round.', $note->body, 'stored trimmed');
        $this->assertSame($user->id, $note->user_id);
    }

    public function test_a_note_cannot_be_written_on_someone_elses_game(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $this->roundFor($theirs, 'not-mine');

        $this->actingAs($mine)
            ->post(route('coach.note'), ['lobby' => 'not-mine', 'body' => 'should not land'])
            ->assertNotFound();

        $this->assertSame(0, ArenaNote::count());
    }

    public function test_a_note_requires_a_body(): void
    {
        $user = User::factory()->create();
        $this->roundFor($user, 'lobby-abc');

        $this->actingAs($user)
            ->post(route('coach.note'), ['lobby' => 'lobby-abc', 'body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, ArenaNote::count());
    }

    /** Separate from the test above: actingAs lasts the whole test method, so a signed-out
     *  request has to be made in a test that never signs anybody in. */
    public function test_a_signed_out_visitor_cannot_write_a_note(): void
    {
        $this->post(route('coach.note'), ['lobby' => 'lobby-abc', 'body' => 'hello'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, ArenaNote::count());
    }

    public function test_notes_reach_the_card_builder_in_the_shape_it_expects(): void
    {
        $user = User::factory()->create();
        ArenaNote::create(['user_id' => $user->id, 'lobby_id' => 'lobby-abc', 'body' => 'first']);
        ArenaNote::create(['user_id' => $user->id, 'lobby_id' => 'lobby-xyz', 'body' => 'second']);
        ArenaNote::create(['user_id' => User::factory()->create()->id, 'lobby_id' => 'lobby-abc', 'body' => 'theirs']);

        $rows = ArenaNote::forCards($user->id);

        $this->assertCount(2, $rows, "only this player's notes");
        // The shape wow:game-cards --notes= already reads: a `game` key means attachNotes() files
        // it against that lobby directly, with no matching by time.
        $this->assertSame(['at', 'text', 'game', 'kind'], array_keys($rows[0]));
        $this->assertSame('first', $rows[0]['text']);
        $this->assertSame('lobby-abc', $rows[0]['game']);
        $this->assertSame('note', $rows[0]['kind']);
    }
}
