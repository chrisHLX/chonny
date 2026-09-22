<?php

namespace App\Livewire;

use App\Models\BrainComment;
use App\Models\PageViewEvent;
use App\Support\BrainDocument;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The MindCollector Brain — the public statement of what this project thinks an arena game is.
 *
 * WHY IT IS A PAGE AND NOT A DOC IN THE REPO. Every Claude-drafted guide on this site is written
 * from this model, so a wrong claim here is wrong in every guide downstream. Publishing it means
 * a reader who disagrees with a guide can argue with the thing that actually produced it, and one
 * correction here is worth more than a correction to any single guide. Same loop as
 * {@see Guides\MachineGuides} one level up: the guides are bait for corrections, this is bait for
 * corrections to the model that writes them.
 *
 * CONFIDENCE TIERS ARE RENDERED, NOT HIDDEN. Each section carries Observed / Derived / Hypothesis
 * straight from the source document. Stating a hypothesis in the same voice as an observation is
 * exactly the failure `arena-structure.md` was rewritten to fix, and it would be worse in public
 * than in a repo file.
 *
 * Comments anchor PER SECTION, matching the guide pages — a criticism of a claim is unreadable
 * without the claim it is attached to.
 */
class Brain extends Component
{
    /**
     * Which section's comment box is open. Client-writable by design (it is pure view state and
     * decides nothing about where a write lands) but still validated against the parsed document
     * before any insert — see postComment().
     */
    public ?string $commentingOn = null;

    public string $body = '';

    /** Server-owned: set only by this component, never bound. */
    #[Locked]
    public ?string $error = null;

    public function mount(): void
    {
        PageViewEvent::log('brain');
    }

    #[Computed]
    public function sections(): array
    {
        return BrainDocument::sections();
    }

    #[Computed]
    public function meta(): array
    {
        return BrainDocument::meta();
    }

    /** All comments, grouped by section key, so the view does one pass and no N+1. */
    #[Computed]
    public function commentsBySection()
    {
        return BrainComment::with('user')
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn ($c) => $c->section_key ?? '');
    }

    public function startComment(string $sectionKey): void
    {
        $this->error = null;
        $this->commentingOn = $this->commentingOn === $sectionKey ? null : $sectionKey;
        $this->body = '';
    }

    /**
     * The section key is re-derived from the parsed document rather than trusted from the request,
     * so a tampered value cannot create an anchor that no section owns — the same rule
     * Guides\Show applies to its own anchors.
     */
    public function postComment(): void
    {
        $this->error = null;
        $body = trim($this->body);

        if (! auth()->check()) {
            $this->error = 'Sign in to comment.';

            return;
        }

        if ($body === '') {
            return;
        }

        $known = array_column($this->sections(), 'id');
        $key = in_array($this->commentingOn, $known, true) ? $this->commentingOn : null;

        if ($key === null) {
            $this->error = 'That section no longer exists.';

            return;
        }

        BrainComment::create([
            'section_key' => $key,
            'user_id' => auth()->id(),
            'body' => mb_substr($body, 0, BrainComment::MAX_LENGTH),
        ]);

        $this->body = '';
        $this->commentingOn = null;
        unset($this->commentsBySection);
    }

    public function deleteComment(int $commentId): void
    {
        if (! auth()->check()) {
            return;
        }

        $comment = BrainComment::whereKey($commentId)->first();

        if ($comment && $comment->user_id === auth()->id()) {
            $comment->delete();
            unset($this->commentsBySection);
        }
    }

    public function render()
    {
        return view('livewire.brain', [
            'sections' => $this->sections(),
            'meta' => $this->meta(),
            'commentsBySection' => $this->commentsBySection(),
            'commentingOn' => $this->commentingOn,
            'error' => $this->error,
        ])->layout('layouts.app', [
            'title' => 'The MindCollector Brain | MindCollector',
            'description' => 'What MindCollector thinks an arena game actually is — the model every '
                .'Claude-drafted guide on this site is written from, with how sure we are about each part.',
        ]);
    }
}
