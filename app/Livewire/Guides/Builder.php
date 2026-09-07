<?php

namespace App\Livewire\Guides;

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Http\Services\UserGuideChainService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideMember;
use App\Models\UserGuideSection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The authoring canvas: a comp, and any number of named sections — chains, gos, prose, and the
 * defensives you are trying to force out of a named opponent.
 *
 * WHY THERE IS NO `wire:ignore` ON THE SORTABLE LISTS, unlike QuizRunner's ordering question. That
 * list is CLIENT-authoritative between renders — the browser holds the answer until submit, so
 * Livewire re-diffing it would destroy the user's arrangement, and `wire:ignore` is what stops
 * that. These lists are SERVER-authoritative: every drop immediately calls reorder(), positions are
 * written to the database, and the re-render that follows produces the order the DOM is already in.
 * Adding `wire:ignore` here would instead freeze a list against add/remove, which is the common way
 * this pattern gets copied wrong. The rule the two share is the one that matters: never read the
 * DOM to decide what to persist — reorder() takes block ids and validates them against the
 * section's own rows.
 *
 * EVERY MUTATION IS OWNERSHIP-SCOPED THROUGH THE GUIDE. Sections, blocks and viewers are all
 * reached via ownedSection()/ownedBlock(), which filter on the guide being edited rather than
 * trusting an id from the client. Blocks no longer carry a guide id of their own (they hang off a
 * section), so that check is a join — deliberately, since one path to a fact cannot disagree with
 * itself.
 */
class Builder extends Component
{
    public UserGuide $guide;

    public string $title = '';

    public string $summary = '';

    public ?string $savedAt = null;

    /** Which comp slot the "add a member" picker is filling, or null when closed. */
    public ?int $pickingSlot = null;

    /** Which section's opponent picker is open, or null when closed. */
    public ?int $pickingOpponentFor = null;

    public string $shareEmail = '';

    public ?string $shareError = null;

    public function mount(UserGuide $guide): void
    {
        abort_unless($guide->isOwnedBy(auth()->user()), 403);

        $this->guide = $guide;
        $this->title = $guide->title;
        $this->summary = $guide->summary ?? '';

        PageViewEvent::log('guide_builder');
    }

    #[Computed]
    public function classes()
    {
        return GameClass::with(['specializations' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function members()
    {
        return $this->guide->members()->with('specialization.gameClass')->get();
    }

    /**
     * Sections grouped into rows, so the view can render a row's two columns side by side.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, UserGuideSection>>
     */
    #[Computed]
    public function rows()
    {
        return $this->guide->sections()->with('opponentSpec.gameClass')->get()->groupBy('row');
    }

    /** Resolved steps + metrics per section id, so the view asks the service once per section. */
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

    public function paletteFor(int $sectionId)
    {
        $section = $this->ownedSection($sectionId);

        return $section ? app(UserGuideChainService::class)->palette($section) : collect();
    }

    #[Computed]
    public function viewers()
    {
        return $this->guide->viewers()->orderBy('name')->get();
    }

    // ---------------------------------------------------------------- the comp

    public function openMemberPicker(int $slot): void
    {
        if ($slot >= 0 && $slot < UserGuideMember::MAX_MEMBERS) {
            $this->pickingSlot = $slot;
        }
    }

    public function closeMemberPicker(): void
    {
        $this->pickingSlot = null;
    }

    /**
     * Put a spec in a comp slot. Slots are filled by assignment rather than appended, so choosing a
     * spec for a slot that already holds one replaces it — the same "pick one" behaviour the WoW
     * Comps slot pickers have.
     */
    public function setMember(int $specId): void
    {
        $slot = $this->pickingSlot;
        if ($slot === null || ! Specialization::whereKey($specId)->exists()) {
            return;
        }

        UserGuideMember::updateOrCreate(
            ['user_guide_id' => $this->guide->id, 'position' => $slot],
            ['spec_id' => $specId],
        );

        $this->pickingSlot = null;
        $this->refreshGuide();
    }

    /**
     * Remove a comp slot.
     *
     * Steps already added from that member are deliberately KEPT. They still name a real ability
     * belonging to a real spec, and deleting somebody's authored steps as a side effect of editing
     * the roster would be a destructive surprise — the same reasoning that keeps unresolvable
     * blocks rather than dropping them.
     */
    public function removeMember(int $position): void
    {
        $this->guide->members()->where('position', $position)->delete();
        $this->refreshGuide();
    }

    // ------------------------------------------------------------- the sections

    /** Append a new full-width section at the bottom of the page. */
    public function addSection(string $kind): void
    {
        $sectionKind = UserGuideSectionKind::tryFrom($kind);
        if ($sectionKind === null) {
            return;
        }

        $row = (int) $this->guide->sections()->max('row');
        $row = $this->guide->sections()->exists() ? $row + 1 : 0;

        UserGuideSection::create([
            'user_guide_id' => $this->guide->id,
            'kind' => $sectionKind,
            'title' => $this->defaultTitleFor($sectionKind),
            'row' => $row,
            'column' => 0,
        ]);

        $this->refreshGuide();
    }

    /**
     * Add the parallel section beside an existing one — the VS layout. Silently does nothing when
     * the row's second column is already taken; a row holds two sections, not more.
     */
    public function addParallelSection(int $row, string $kind): void
    {
        $sectionKind = UserGuideSectionKind::tryFrom($kind);
        if ($sectionKind === null) {
            return;
        }

        $exists = $this->guide->sections()->where('row', $row)->where('column', 1)->exists();
        $rowExists = $this->guide->sections()->where('row', $row)->exists();

        if ($exists || ! $rowExists) {
            return;
        }

        UserGuideSection::create([
            'user_guide_id' => $this->guide->id,
            'kind' => $sectionKind,
            'title' => $this->defaultTitleFor($sectionKind),
            'row' => $row,
            'column' => 1,
        ]);

        $this->refreshGuide();
    }

    public function renameSection(int $sectionId, string $title): void
    {
        $section = $this->ownedSection($sectionId);
        $title = trim($title);

        if (! $section || $title === '') {
            return;
        }

        $section->update(['title' => mb_substr($title, 0, 120)]);
        $this->refreshGuide();
    }

    public function setSectionBody(int $sectionId, string $body): void
    {
        $section = $this->ownedSection($sectionId);
        if (! $section || $section->kind !== UserGuideSectionKind::Text) {
            return;
        }

        $section->update(['body' => mb_substr($body, 0, 20000) ?: null]);
        $this->refreshGuide();
    }

    public function openOpponentPicker(int $sectionId): void
    {
        if ($this->ownedSection($sectionId)?->kind->usesOpponent()) {
            $this->pickingOpponentFor = $sectionId;
        }
    }

    public function closeOpponentPicker(): void
    {
        $this->pickingOpponentFor = null;
    }

    /**
     * Name the opponent a Defensives section is about. Changing it deliberately keeps any steps
     * already added — they name real abilities, and silently deleting authored steps because the
     * opponent was corrected would be the same destructive surprise as clearing a comp slot.
     */
    public function setOpponent(int $specId): void
    {
        $section = $this->pickingOpponentFor ? $this->ownedSection($this->pickingOpponentFor) : null;

        if ($section && Specialization::whereKey($specId)->exists()) {
            $section->update(['opponent_spec_id' => $specId]);
        }

        $this->pickingOpponentFor = null;
        $this->refreshGuide();
    }

    /**
     * Move a section up or down the page, swapping it with the neighbouring row.
     *
     * The whole row moves, both columns together — a VS pair is one moment in the guide, and
     * separating the go from the defensives it is trying to force would be meaningless. Rows are
     * renumbered through a temporary out-of-range value because (guide, row, column) is uniquely
     * constrained and a direct swap would collide mid-update.
     */
    public function moveSection(int $sectionId, int $direction): void
    {
        $section = $this->ownedSection($sectionId);
        if (! $section || ! in_array($direction, [-1, 1], true)) {
            return;
        }

        $target = $this->guide->sections()
            ->where('row', $direction < 0 ? '<' : '>', $section->row)
            ->orderBy('row', $direction < 0 ? 'desc' : 'asc')
            ->value('row');

        if ($target === null) {
            return;
        }

        $from = $section->row;
        $parking = (int) $this->guide->sections()->max('row') + 1;

        DB::transaction(function () use ($from, $target, $parking) {
            $this->guide->sections()->where('row', $from)->update(['row' => $parking]);
            $this->guide->sections()->where('row', $target)->update(['row' => $from]);
            $this->guide->sections()->where('row', $parking)->update(['row' => $target]);
        });

        $this->refreshGuide();
    }

    public function deleteSection(int $sectionId): void
    {
        $this->ownedSection($sectionId)?->delete();
        $this->refreshGuide();
    }

    // --------------------------------------------------------------- the steps

    /**
     * Append an ability to a section.
     *
     * The block records the spec it came from, because a guide spans a comp and each step has to
     * resolve its cooldowns against its OWN caster's build — resolving every step against one spec
     * would produce confident, wrong numbers. Stored as a reference (a specializations row id,
     * stable reference data and not patch-scoped), never a resolved class or spec name.
     */
    public function addSpell(int $sectionId, int $externalSpellId, int $specId): void
    {
        $section = $this->ownedSection($sectionId);
        if (! $section) {
            return;
        }

        // Only ever from the palette this section actually offers — a hand-crafted request must
        // not be able to attach an arbitrary spell id, one belonging to a spec outside the comp,
        // an offensive cooldown to a plain chain, or one of your own abilities to the opponent's
        // defensives.
        $offered = app(UserGuideChainService::class)->palette($section)
            ->filter(fn (array $s) => $s['spec']->id === $specId)
            ->flatMap(fn (array $s) => $s['groups']->flatten(1))
            ->contains(fn ($entry) => (int) $entry['spell']->spell_id === $externalSpellId);

        if (! $offered) {
            return;
        }

        UserGuideBlock::create([
            'user_guide_section_id' => $section->id,
            'position' => (int) $section->blocks()->max('position') + 1,
            'block_type' => UserGuideBlockType::Spell,
            'payload' => [
                'external_spell_id' => $externalSpellId,
                'source_spec_id' => $specId,
            ],
        ]);

        $this->refreshGuide();
    }

    public function removeBlock(int $blockId): void
    {
        $this->ownedBlock($blockId)?->delete();
        $this->refreshGuide();
    }

    /**
     * Persist a drag result within one section.
     *
     * Takes block ids from the client and re-derives order from them, rather than trusting any
     * positional payload: ids are validated against that section's own blocks, so a tampered or
     * stale list can only ever reorder rows the author already owns. Anything the client omitted
     * keeps its relative order after the ones it did send, so a partial list degrades to a partial
     * reorder instead of silently deleting steps.
     */
    public function reorder(int $sectionId, array $blockIds): void
    {
        $section = $this->ownedSection($sectionId);
        if (! $section) {
            return;
        }

        $blocks = $section->blocks()->get()->keyBy('id');
        $ordered = collect($blockIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $blocks->has($id))
            ->values();

        $final = $ordered->merge($blocks->keys()->diff($ordered)->values());

        DB::transaction(function () use ($final, $section) {
            foreach ($final as $position => $id) {
                UserGuideBlock::where('id', $id)
                    ->where('user_guide_section_id', $section->id)
                    ->update(['position' => $position]);
            }
        });

        $this->refreshGuide();
    }

    /** An author's annotation on one step ("only if they trinket the first stun"). */
    public function setNote(int $blockId, string $note): void
    {
        $block = $this->ownedBlock($blockId);
        if (! $block) {
            return;
        }

        $payload = $block->payload;
        $note = trim($note);

        if ($note === '') {
            unset($payload['note']);
        } else {
            $payload['note'] = mb_substr($note, 0, 280);
        }

        $block->update(['payload' => $payload]);
        $this->refreshGuide();
    }

    // ------------------------------------------------------ publishing & access

    public function updatedTitle(): void
    {
        $title = trim($this->title);
        if ($title === '') {
            return;
        }

        // Slug is intentionally not regenerated — see UserGuide::booted().
        $this->guide->update(['title' => mb_substr($title, 0, 120)]);
        $this->markSaved();
    }

    public function updatedSummary(): void
    {
        $this->guide->update(['summary' => mb_substr(trim($this->summary), 0, 500) ?: null]);
        $this->markSaved();
    }

    /**
     * A guide with no comp has no kit to draw from and is necessarily empty, so it cannot be
     * published — the one rule the schema cannot express ("at least one row in a related table").
     *
     * Publishing assigns the author a username if they have none, because the shareable URL is
     * built from it. Doing it here rather than at signup means no existing account is blocked
     * behind a profile step nobody has been asked to complete.
     */
    public function publish(): void
    {
        if (! $this->guide->hasRoster()) {
            return;
        }

        auth()->user()->resolveUsername();

        $this->guide->update(['status' => UserGuideStatus::Published]);
        $this->refreshGuide();
    }

    public function unpublish(): void
    {
        $this->guide->update(['status' => UserGuideStatus::Draft]);
        $this->refreshGuide();
    }

    public function setVisibility(string $visibility): void
    {
        $value = UserGuideVisibility::tryFrom($visibility);
        if ($value === null) {
            return;
        }

        $this->guide->update(['visibility' => $value]);
        $this->refreshGuide();
    }

    /**
     * Give somebody read access to a private guide.
     *
     * Matches an existing account by email. Sharing with somebody who has not signed up is
     * deliberately not supported — a pending invite would be access granted to an address rather
     * than to a person, and would need its own claim flow to be safe. The form says so instead of
     * silently accepting an address that will never resolve.
     */
    public function shareWith(): void
    {
        $this->shareError = null;
        $email = trim($this->shareEmail);

        if ($email === '') {
            return;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->shareError = 'No MindCollector account uses that email address yet.';

            return;
        }

        if ($user->id === $this->guide->user_id) {
            $this->shareError = 'You already have access to your own guide.';

            return;
        }

        $this->guide->viewers()->syncWithoutDetaching([$user->id]);
        $this->shareEmail = '';
        $this->refreshGuide();
    }

    public function unshare(int $userId): void
    {
        $this->guide->viewers()->detach($userId);
        $this->refreshGuide();
    }

    // ------------------------------------------------------------------ helpers

    private function defaultTitleFor(UserGuideSectionKind $kind): string
    {
        return match ($kind) {
            UserGuideSectionKind::Chain => 'CC chain',
            UserGuideSectionKind::Go => 'The go',
            UserGuideSectionKind::Defensives => 'Defensives to force',
            UserGuideSectionKind::Text => 'Notes',
        };
    }

    /** A section id is only ever acted on after confirming it belongs to the guide being edited. */
    private function ownedSection(int $sectionId): ?UserGuideSection
    {
        return UserGuideSection::where('id', $sectionId)
            ->where('user_guide_id', $this->guide->id)
            ->first();
    }

    /** Blocks hang off sections, so ownership is checked through the section's own guide. */
    private function ownedBlock(int $blockId): ?UserGuideBlock
    {
        return UserGuideBlock::where('id', $blockId)
            ->whereHas('section', fn ($q) => $q->where('user_guide_id', $this->guide->id))
            ->first();
    }

    private function refreshGuide(): void
    {
        // patch_id records what the author was looking at, and is set on first real edit rather
        // than at creation so an abandoned empty draft never claims to describe a patch.
        if ($this->guide->patch_id === null) {
            $this->guide->patch_id = Patch::where('is_current', true)->value('id');
        }

        $this->guide->save();
        $this->guide->refresh();

        unset($this->rows, $this->resolved, $this->members, $this->viewers);
        $this->markSaved();
    }

    private function markSaved(): void
    {
        $this->savedAt = now()->format('H:i');
    }

    public function render()
    {
        return view('livewire.guides.builder', [
            'sectionKinds' => UserGuideSectionKind::cases(),
        ])->layout('layouts.app', [
            'title' => "Editing: {$this->guide->title} | MindCollector",
            'description' => 'Build and share your own crowd-control chains and go setups.',
        ]);
    }
}
