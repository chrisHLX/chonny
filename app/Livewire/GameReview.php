<?php

namespace App\Livewire;

use App\Http\Services\LobbyReviewService;
use App\Models\PageViewEvent;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Game Review — one of your played games read back: who won which round, what each player put out,
 * and where two players of the same spec differed.
 *
 * WHY IT EXISTS. It came from a question this site could not answer about a real game: "I went 5-1,
 * was my healing better than the other Disc Priest, and was it talents or gear?" Nothing here
 * measured throughput at all, so there was no way to find out that the two priests' output was
 * within 1.6% of each other and the real difference was a haste build against a mastery one.
 *
 * THE MIRROR IS THE UNIT OF COMPARISON. Same spec on both sides means the identical kit, so every
 * difference left is build, gear or play. Cross-spec output numbers are not comparable and the
 * page says so rather than ranking them — see LobbyReviewService::limitations(), rendered on the
 * page for the same reason rule 33 keeps MatchupLab's limits in one method.
 *
 * PRIVATE TO THE VIEWER. A review names five other players with their talents and their gear. The
 * route carries `auth` and every read here is scoped to the signed-in user; both halves are meant
 * to be there. It shipped public for about twenty minutes on 2026-09-25 — that is the mistake this
 * comment exists to stop being repeated.
 */
class GameReview extends Component
{
    /**
     * Which review is open. Locked: a public property is writable by anyone who posts to
     * /livewire/update (rule 23), and this selects which row is read. The scoping query also
     * filters by user, so a forged value can only ever miss.
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

    /** Called by the uploader once it has finished, to pull the new games in. */
    public function refreshAfterUpload(): void
    {
        $index = $this->reviews();

        if ($this->reviewId === null || ! collect($index)->contains(fn ($r) => $r['id'] === $this->reviewId)) {
            $this->reviewId = $index[0]['id'] ?? null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function reviews(): array
    {
        return auth()->check()
            ? app(LobbyReviewService::class)->index(auth()->user())
            : [];
    }

    public function review(): ?array
    {
        return $this->reviewId === null || ! auth()->check()
            ? null
            : app(LobbyReviewService::class)->load(auth()->user(), $this->reviewId);
    }

    public function render()
    {
        $review = $this->review();

        return view('livewire.game-review', [
            'reviews' => $this->reviews(),
            'review' => $review,
            'reviewId' => $this->reviewId,
            // Carried from the stored review rather than the service, so an old game keeps the
            // limits it was assembled with instead of silently acquiring today's wording.
            'limitations' => $review['limitations'] ?? app(LobbyReviewService::class)->limitations(),
        ])->layout('layouts.app', [
            'title' => 'Match Review — your arena games, measured | MindCollector',
            'description' => 'Read back your own WoW arena games round by round: effective healing, '
                .'absorbs, overheal and damage for every player, and a same-spec mirror comparison '
                .'showing exactly where two players of one spec differed in talents, gear and stats.',
        ]);
    }
}
