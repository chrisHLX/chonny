<?php

namespace App\Livewire\Guides;

use App\Http\Services\CharacterTalentResolver;
use App\Http\Services\FriendshipService;
use App\Http\Services\UserGuideChainService;
use App\Models\PageViewEvent;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideComment;
use App\Models\UserGuideLike;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The read view for a shared guide, at /g/{username}/{slug}.
 *
 * DELIBERATELY ITS OWN ROUTE NAMESPACE, not alongside the site's derived guides. Everything else on
 * this site earns its credibility from being derived from real match evidence and never guessed —
 * curation files, verified overrides, the standing flag-don't-guess rule. A player-written guide is
 * the opposite trust tier by construction, and if it rendered at the same URL shape with the same
 * framing as a corpus-derived guide it would spend that credibility. So the URL names its author,
 * the page names its author, and the page says plainly that this is one player's plan rather than
 * measured data. That separation is cheap now and expensive to retrofit once guides are linked.
 *
 * Access is decided entirely by UserGuide::isReadableBy() — the author and anyone they let edit
 * it, anyone for a public published guide, and only explicitly invited accounts for a private one.
 * A draft is visible to nobody but the people working on it, so an unfinished guide cannot leak
 * through a guessed URL.
 */
class Show extends Component
{
    public UserGuide $guide;

    public function mount(string $username, UserGuide $guide): void
    {
        // The username in the URL must actually be this guide's author. Without this check the
        // slug alone would resolve, and any username would serve any author's guide — a broken
        // canonical URL, and a confusing one to share.
        $author = User::where('username', $username)->first();
        abort_unless($author && $author->id === $guide->user_id, 404);

        // 404 rather than 403 for a guide the viewer may not read: a 403 confirms the guide exists,
        // which for a private guide leaks the fact that this person wrote something at this URL.
        abort_unless($guide->isReadableBy(auth()->user()), 404);

        $this->guide = $guide;

        PageViewEvent::log('guide_show');

        // Counted once per reader per day, and never for the author — see recordView().
        $this->guide->recordView(auth()->user(), session()->getId());

        $this->liked = $this->guide->likedBy(auth()->user());
    }

    #[Computed]
    public function rows()
    {
        return $this->guide->sections()
            ->with(['opponentSpec.gameClass', 'updatedBy:id,name,username'])
            ->get()
            ->groupBy('row');
    }

    /** Everyone who put something into the guide — see UserGuide::contributors(). */
    #[Computed]
    public function contributors()
    {
        return $this->guide->contributors();
    }

    /** What happened when the reader asked to befriend the author, for the confirmation line. */
    #[Locked]
    public ?string $friendRequestSent = null;

    /** Ask the author to be friends — the reader's way to connect from the guide itself. */
    public function addAuthorAsFriend(FriendshipService $service): void
    {
        $author = $this->guide->user;

        if (! auth()->check() || ! $author) {
            return;
        }

        $this->friendRequestSent = match ($service->request(auth()->user(), $author)) {
            'accepted' => 'You\'re friends now',
            'sent', 'already_sent' => 'Friend request sent',
            default => null,
        };

        auth()->user()->forgetFriendCache();
    }

    #[Computed]
    public function members()
    {
        return $this->guide->members()->with('specialization.gameClass')->get();
    }

    /** The comp this guide is written against, when the author named one. */
    #[Computed]
    public function enemies()
    {
        return $this->guide->enemies()->with('specialization.gameClass')->get();
    }

    // ---------------------------------------------------------- the signing character

    /**
     * Whether the author's character card is expanded to gear and talents. Server-side, so the
     * talent calculator — the heaviest thing on this page — is only built for readers who ask.
     */
    public bool $showAuthorBuild = false;

    /** The character the author signed this guide with, if any. Opt-in per guide. */
    #[Computed]
    public function authorCharacter()
    {
        return $this->guide->authorCharacter()->with(['gameClass', 'specialization.gameClass'])->first();
    }

    /**
     * The signing character's talents — for the spec this guide is about when the character has a
     * build for it, otherwise its active spec. A Subtlety guide signed by a Rogue should show the
     * Subtlety build even if the character logged out as Assassination.
     */
    #[Computed]
    public function authorTalentView(): ?array
    {
        $character = $this->authorCharacter;

        if (! $character || ! $this->showAuthorBuild) {
            return null;
        }

        $guideSpecs = $this->members->map(fn ($m) => $m->specialization?->external_spec_id)->filter();
        $match = collect($character->talents ?? [])->first(fn ($t) => $guideSpecs->contains($t['spec_external_id']));

        return app(CharacterTalentResolver::class)->forCharacter($character, $match['spec_external_id'] ?? null);
    }

    public function toggleAuthorBuild(): void
    {
        $this->showAuthorBuild = ! $this->showAuthorBuild;
        unset($this->authorTalentView);
    }

    /** Resolved steps + metrics per section — the same service the builder renders through. */
    #[Computed]
    public function resolved(): array
    {
        $svc = app(UserGuideChainService::class);
        $out = [];

        foreach ($this->guide->sections()->get() as $section) {
            if (! $section->kind->isSequence()) {
                continue;
            }

            $out[$section->id] = [
                'steps' => $svc->resolve($section),
                'metrics' => $svc->metrics($section),
            ];
        }

        return $out;
    }

    /** What has drifted under this guide since it was written — see UserGuideChainService::health(). */
    #[Computed]
    public function health(): array
    {
        return app(UserGuideChainService::class)->health($this->guide, $this->resolved);
    }

    // ---------------------------------------------------------------- likes

    public bool $liked = false;

    public string $comment = '';

    public ?string $feedbackError = null;

    /**
     * Like this guide, or take it back.
     *
     * You cannot like your own guide. That is not politeness — the listing on /wow-comps ranks by
     * this number, so self-liking is the cheapest possible way to game which guides other people
     * are shown, and an author endorsing their own work carries no information anyway.
     *
     * A toggle rather than a one-way vote: a reader who changes their mind should be able to
     * withdraw it, and the alternative is a number that can only ever go up.
     */
    public function toggleLike(): void
    {
        $this->feedbackError = null;

        if (! auth()->check()) {
            $this->feedbackError = 'Sign in to like this guide.';

            return;
        }

        if ($this->guide->isOwnedBy(auth()->user())) {
            $this->feedbackError = 'You cannot like your own guide.';

            return;
        }

        $existing = UserGuideLike::where('user_guide_id', $this->guide->id)
            ->where('user_id', auth()->id())
            ->first();

        if ($existing) {
            $existing->delete();
            $this->liked = false;
        } else {
            // firstOrCreate, not create: the (guide, user) unique key is the real guard, and a
            // double-submitted click should be a no-op rather than an integrity-constraint error.
            UserGuideLike::firstOrCreate([
                'user_guide_id' => $this->guide->id,
                'user_id' => auth()->id(),
            ]);
            $this->liked = true;
        }

        $this->guide->syncLikeCount();
        $this->guide->refresh();
    }

    /** Post a comment. Plain text, never Markdown — see UserGuideComment. */
    public function postComment(): void
    {
        $this->feedbackError = null;
        $body = trim($this->comment);

        if (! auth()->check()) {
            $this->feedbackError = 'Sign in to comment.';

            return;
        }

        if ($body === '') {
            return;
        }

        UserGuideComment::create([
            'user_guide_id' => $this->guide->id,
            'user_id' => auth()->id(),
            'body' => mb_substr($body, 0, UserGuideComment::MAX_LENGTH),
        ]);

        $this->comment = '';
        unset($this->comments);
    }

    /** Delete a comment. Its author, or the guide's author moderating their own page. */
    public function deleteComment(int $commentId): void
    {
        $comment = $this->guide->comments()->whereKey($commentId)->first();

        if (! $comment || ! auth()->check()) {
            return;
        }

        if ($comment->user_id === auth()->id() || $this->guide->isOwnedBy(auth()->user())) {
            $comment->delete();
            unset($this->comments);
        }
    }

    #[Computed]
    public function comments()
    {
        return $this->guide->comments()
            ->with('user')
            ->whereNull('user_guide_block_id')
            ->whereNull('user_guide_section_id')
            ->get();
    }

    // ------------------------------------------------- notes anchored to one step or section

    /**
     * Which step or section the reader is writing a note on, as "block:12" or "section:3".
     *
     * One at a time, and server-held rather than Alpine, because posting the note is a round trip
     * anyway and two half-written notes on one page is a state nobody asked for.
     */
    public ?string $notingOn = null;

    public string $note = '';

    public function startNote(string $anchor): void
    {
        $this->feedbackError = null;
        $this->notingOn = $this->notingOn === $anchor ? null : $anchor;
        $this->note = '';
    }

    /**
     * Post a note against one step or section.
     *
     * The anchor is re-derived from the guide's own rows rather than trusted from the client, so a
     * tampered id cannot attach a note to another guide — the same ownership-through-the-guide
     * rule the builder applies to every mutation.
     */
    public function postNote(): void
    {
        $this->feedbackError = null;
        $body = trim($this->note);

        if (! auth()->check()) {
            $this->feedbackError = 'Sign in to add a note.';

            return;
        }

        [$kind, $id] = array_pad(explode(':', (string) $this->notingOn, 2), 2, null);
        $id = (int) $id;

        if ($body === '' || $id <= 0) {
            return;
        }

        $sectionId = null;
        $blockId = null;

        if ($kind === 'section') {
            $sectionId = $this->guide->sections()->whereKey($id)->value('id');
        } elseif ($kind === 'block') {
            $block = UserGuideBlock::whereKey($id)
                ->whereIn('user_guide_section_id', $this->guide->sections()->select('id'))
                ->first();
            $blockId = $block?->id;
            $sectionId = $block?->user_guide_section_id;
        }

        if ($sectionId === null) {
            return;
        }

        UserGuideComment::create([
            'user_guide_id' => $this->guide->id,
            'user_guide_section_id' => $sectionId,
            'user_guide_block_id' => $blockId,
            'user_id' => auth()->id(),
            'body' => mb_substr($body, 0, UserGuideComment::MAX_LENGTH),
        ]);

        $this->note = '';
        $this->notingOn = null;
        unset($this->comments, $this->notesByAnchor);
    }

    /** Every anchored note on this guide, keyed "block:12" / "section:3" for the view. */
    #[Computed]
    public function notesByAnchor()
    {
        return $this->guide->comments()
            ->with('user')
            ->where(function ($q) {
                $q->whereNotNull('user_guide_block_id')->orWhereNotNull('user_guide_section_id');
            })
            ->get()
            ->groupBy(function ($c) {
                return $c->user_guide_block_id
                    ? 'block:'.$c->user_guide_block_id
                    : 'section:'.$c->user_guide_section_id;
            });
    }

    public function render()
    {
        $name = fn ($m) => trim($m->specialization?->name.' '.$m->specialization?->gameClass?->name);
        $comp = $this->members->map($name)->filter()->implode(' / ');
        $versus = $this->enemies->map($name)->filter()->implode(' / ');

        // A matchup guide says so in its own title and meta description — that is what someone
        // searches for, and it is the difference between "an RMD guide" and "RMD vs TSG".
        $subject = trim($comp.($versus !== '' ? " vs {$versus}" : ''));

        return view('livewire.guides.show')->layout('layouts.app', [
            'title' => "{$this->guide->title} by {$this->guide->user?->username} | MindCollector",
            'description' => $this->guide->summary
                ?: trim(($this->guide->isMachineAuthored() ? 'A machine-drafted arena guide' : 'A player-written arena guide')
                    .($subject !== '' ? " for {$subject}" : '').'.'),
        ]);
    }
}
