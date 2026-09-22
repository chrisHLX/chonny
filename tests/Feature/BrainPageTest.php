<?php

namespace Tests\Feature;

use App\Models\BrainComment;
use App\Models\User;
use App\Support\BrainDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Brain page renders a committed markdown file, so the thing most worth guarding is the
 * contract between that file and the parser: explicit `{#id}` anchors and `::tier::` markers.
 * A renamed heading is routine; a silently changed section id orphans every comment under it.
 */
class BrainPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_source_document_parses_into_identified_sections(): void
    {
        $sections = BrainDocument::sections();

        $this->assertNotEmpty($sections, 'data/brain/brain.md produced no sections.');

        foreach ($sections as $section) {
            $this->assertNotSame('', $section['id'], 'A section parsed without an id.');
            $this->assertNotSame('', $section['heading']);
            $this->assertNotSame('', $section['html'], "Section {$section['id']} rendered empty.");
        }

        $ids = array_column($sections, 'id');
        $this->assertSame($ids, array_unique($ids), 'Duplicate section ids would merge two comment threads.');
    }

    public function test_every_section_declares_a_confidence_tier(): void
    {
        foreach (BrainDocument::sections() as $section) {
            $this->assertContains(
                $section['tier'],
                ['observed', 'derived', 'hypothesis', 'open'],
                "Section '{$section['id']}' has no recognised ::tier:: marker. An untiered claim reads "
                .'as confident as an observed one, which is the failure this page exists to avoid.'
            );
        }
    }

    public function test_the_page_renders_for_a_guest(): void
    {
        $this->get('/brain')
            ->assertOk()
            ->assertSee('Sign in to comment')
            ->assertSee(BrainDocument::sections()[0]['heading']);
    }

    public function test_a_signed_in_reader_can_comment_on_a_section(): void
    {
        $user = User::factory()->create();
        $sectionId = BrainDocument::sections()[0]['id'];

        Livewire::actingAs($user)
            ->test(\App\Livewire\Brain::class)
            ->call('startComment', $sectionId)
            ->set('body', 'This part is wrong because ...')
            ->call('postComment');

        $this->assertDatabaseHas('brain_comments', [
            'section_key' => $sectionId,
            'user_id' => $user->id,
            'body' => 'This part is wrong because ...',
        ]);
    }

    public function test_a_comment_cannot_be_anchored_to_a_section_that_does_not_exist(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Brain::class)
            ->set('commentingOn', 'not-a-real-section')
            ->set('body', 'tampered')
            ->call('postComment')
            ->assertSet('error', 'That section no longer exists.');

        $this->assertSame(0, BrainComment::count());
    }

    public function test_a_guest_cannot_comment(): void
    {
        Livewire::test(\App\Livewire\Brain::class)
            ->set('commentingOn', BrainDocument::sections()[0]['id'])
            ->set('body', 'hello')
            ->call('postComment')
            ->assertSet('error', 'Sign in to comment.');

        $this->assertSame(0, BrainComment::count());
    }

    public function test_a_reader_can_only_delete_their_own_comment(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $sectionId = BrainDocument::sections()[0]['id'];

        $comment = BrainComment::create([
            'section_key' => $sectionId,
            'user_id' => $theirs->id,
            'body' => 'not yours to remove',
        ]);

        Livewire::actingAs($mine)
            ->test(\App\Livewire\Brain::class)
            ->call('deleteComment', $comment->id);

        $this->assertDatabaseHas('brain_comments', ['id' => $comment->id]);
    }
}
