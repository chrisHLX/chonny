<?php

namespace App\Livewire;

use App\Http\Services\LobbyReviewService;
use App\Models\PageViewEvent;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Game Review — one played game read back: who won which round, what each player put out, and
 * where two players of the same spec differed.
 *
 * WHY IT EXISTS. It came from a question this site could not answer about a real game: "I went
 * 5-1, was my healing better than the other Disc Priest, and was it talents or gear?" Nothing
 * here measured throughput at all, so there was no way to find out that the two priests' output
 * was within 1.6% of each other and the real difference was a haste build against a mastery one.
 *
 * THE MIRROR IS THE UNIT OF COMPARISON. Same spec on both sides means the identical kit, so every
 * difference left is build, gear or play. Cross-spec output numbers are not comparable and the
 * page says so rather than ranking them — see LobbyReviewService::limitations(), which is
 * rendered on the page for the same reason rule 33 keeps MatchupLab's limits in one method.
 *
 * IT READS A COMMITTED ARTIFACT AND NOTHING ELSE. `data/arena-logs/metadata/*` and `raw/*` are
 * gitignored (rule 14), so a page built on them works on a dev machine and is empty for every
 * real visitor. Everything here comes from `data/arena-logs/lobby-reviews/*.json`, written by
 * `wow:review-lobby` and committed. There is a test that deletes the archive and still expects
 * this page to render, because a normal dev run structurally cannot catch that mistake.
 */
class GameReview extends Component
{
    /**
     * Which review is open. Locked: it selects which file is read, and a public property is
     * writable by anyone who posts to /livewire/update (rule 23). The artifact path is also
     * scrubbed to hex in LobbyReviewService::artifactPath(), so a traversal attempt reads nothing.
     */
    #[Locked]
    public ?string $reviewId = null;

    public function mount(?string $id = null): void
    {
        PageViewEvent::log('game_review');

        $index = $this->reviews();

        $this->reviewId = $id !== null && collect($index)->contains(fn ($r) => $r['id'] === $id)
            ? $id
            : ($index[0]['id'] ?? null);
    }

    /** Selecting a game is a real explicit choice, so it is attributed (rule 28). */
    public function open(string $id): void
    {
        if (! collect($this->reviews())->contains(fn ($r) => $r['id'] === $id)) {
            return;
        }

        $this->reviewId = $id;

        PageViewEvent::log('game_review', slot: $id);
    }

    /** @return array<int, array<string, mixed>> */
    public function reviews(): array
    {
        return app(LobbyReviewService::class)->index();
    }

    public function review(): ?array
    {
        return $this->reviewId === null
            ? null
            : app(LobbyReviewService::class)->load($this->reviewId);
    }

    public function render()
    {
        $review = $this->review();

        return view('livewire.game-review', [
            'reviews' => $this->reviews(),
            'review' => $review,
            'reviewId' => $this->reviewId,
            // Carried from the artifact rather than the service, so an old review keeps the
            // limits it was written with instead of silently acquiring today's wording.
            'limitations' => $review['limitations'] ?? app(LobbyReviewService::class)->limitations(),
        ])->layout('layouts.app', [
            'title' => 'Game Review — your arena games, measured | MindCollector',
            'description' => 'Read back a played WoW arena game round by round: effective healing, '
                .'absorbs, overheal and damage for every player, and a same-spec mirror comparison '
                .'showing exactly where two players of one spec differed in talents, gear and stats.',
        ]);
    }
}
