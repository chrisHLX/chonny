<?php

namespace App\Livewire;

use App\Models\PageViewEvent;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * Game Review — raw analysis: the unedited console output of the review tools
 * (tools/match-review/, see match-review-operations.md), for the player to read for themselves.
 *
 * WHERE THE OUTPUT COMES FROM. The tools run locally against the combat-log archive, which never
 * reaches the server (rule 14), and their output names every opponent with their experience. So it
 * is neither committed nor deployed: the owner's output is uploaded to
 * storage/app/private/match-review/{user id}/ on the server, and this page lists what is there.
 * A review is user data; it reaches production by being uploaded by its owner, never by a deploy.
 *
 * PRIVATE TO THE VIEWER, like GameReview: the route carries `auth` and the only directory ever read
 * is the signed-in user's own. There is no property to forge, because nothing here is selected by
 * the client.
 */
class GameReviewAnalysis extends Component
{
    /** Where one user's tool output lives on the local (private) disk. */
    public static function directory(int $userId): string
    {
        return "match-review/{$userId}";
    }

    public function mount(): void
    {
        PageViewEvent::log('game_review_analysis');
    }

    /** @return array<int, array{name: string, updated: int, body: string}> newest first */
    public function outputs(): array
    {
        if (! auth()->check()) {
            return [];
        }

        $disk = Storage::disk('local');

        return collect($disk->files(self::directory(auth()->id())))
            ->filter(fn (string $path) => str_ends_with($path, '.txt'))
            ->map(fn (string $path) => [
                'name' => basename($path, '.txt'),
                'updated' => $disk->lastModified($path),
                'body' => (string) $disk->get($path),
            ])
            ->sortByDesc('updated')
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.game-review-analysis', [
            'outputs' => $this->outputs(),
        ])->layout('layouts.app', [
            'title' => 'Match Review — raw analysis | MindCollector',
            'description' => 'The raw output of the match review tools for your own arena games.',
        ]);
    }
}
