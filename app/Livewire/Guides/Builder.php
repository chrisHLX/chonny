<?php

namespace App\Livewire\Guides;

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideMemberSide;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Http\Services\TalentSelectionService;
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

    /**
     * Which SIDE that picker is filling — your comp or the enemy team. Held separately from the
     * slot because slot 0 exists on both sides and is a different slot on each.
     */
    public string $pickingSide = 'team';

    /** Which section's opponent picker is open, or null when closed. */
    public ?int $pickingOpponentFor = null;

    /** Which comp slot's talent tree is open, or null when closed. */
    public ?int $editingTalentsFor = null;

    /** Which side that slot belongs to — both teams have a slot 0. */
    public string $editingTalentsSide = 'team';

    /** Whether the class guide's single-opponent picker is open. */
    public bool $pickingGuideOpponent = false;

    /**
     * Which section's ability palette is open, or null when none is.
     *
     * SERVER-SIDE ON PURPOSE, and it used to be an Alpine-only `x-show`. Building one palette
     * costs ~270ms and ~50 queries per request (it is memoised per request, but every Livewire
     * round trip is a fresh request), and the old markup built one for EVERY section and then
     * hid all but one with CSS. So a three-section guide paid ~810ms and ~150 queries of palette
     * work on every single interaction — including ones with nothing to do with palettes.
     * Measured 2026-09-08: renaming the guide, which does no data work at all, cost 1,575ms and
     * 195 queries. That is the "lag when you do things" report, and it scaled with section count.
     *
     * Same fix and same reasoning as the WoW Comps spell modal (2026-09-06), which rendered one
     * hidden block per spell and dropped 87% of its payload by rendering only the open one.
     *
     * The trade is deliberate: opening a palette now costs a round trip instead of being
     * instant. That request is doing the real work, it happens far less often than typing or
     * reordering, and it is the one interaction where a brief wait reads as loading rather than
     * as lag. Searching WITHIN an open palette stays purely client-side — see the blade.
     */
    public ?int $openPaletteFor = null;

    /** Open a section's palette, or close it if it is already the open one. */
    public function togglePalette(int $sectionId): void
    {
        $this->openPaletteFor = $this->openPaletteFor === $sectionId
            ? null
            : ($this->ownedSection($sectionId) ? $sectionId : null);
    }

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

    /** The comp being played against, if the author has named one. */
    #[Computed]
    public function enemies()
    {
        return $this->guide->enemies()->with('specialization.gameClass')->get();
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

        // $this->rows is already loaded with its relations; re-querying sections here meant a
        // second identical read on every render.
        foreach ($this->rows->flatten(1) as $section) {
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

    /** @var array<int, \Illuminate\Support\Collection> section id => palette, this request only */
    private array $paletteMemo = [];

    /**
     * Memoised because one click asks for the same palette several times over: addSpell()
     * validates against it, then the re-render asks again for every open section. Measured
     * 2026-09-08 at 376ms and 87 queries per build, so a 3-section guide paid ~1.5s of duplicate
     * work per ability added — a large part of the "I click it three times and then three
     * abilities appear" report.
     *
     * NOT a #[Computed]: those key on nothing but the property name, and this takes an argument.
     * Per-request only, which is the correct lifetime — a palette must reflect a talent change
     * made moments ago in the same session.
     */
    public function paletteFor(int $sectionId)
    {
        if (isset($this->paletteMemo[$sectionId])) {
            return $this->paletteMemo[$sectionId];
        }

        $section = $this->ownedSection($sectionId);

        return $this->paletteMemo[$sectionId] = $section
            ? app(UserGuideChainService::class)->palette($section)
            : collect();
    }

    /** The roster row whose talent tree is currently open, on whichever side it belongs to. */
    #[Computed]
    public function editingTalentsMember()
    {
        if ($this->editingTalentsFor === null) {
            return null;
        }

        $rows = $this->editingTalentsSide === UserGuideMemberSide::Enemy->value
            ? $this->enemies
            : $this->members;

        return $rows->firstWhere('position', $this->editingTalentsFor);
    }

    /** The author's own guilds, for the sharing picker. */
    #[Computed]
    public function myGuilds()
    {
        return auth()->user()->guilds()->get();
    }

    #[Computed]
    public function viewers()
    {
        return $this->guide->viewers()->orderBy('name')->get();
    }

    // ---------------------------------------------------------------- the comp

    public function openMemberPicker(int $slot, string $side = 'team'): void
    {
        $sideEnum = UserGuideMemberSide::tryFrom($side);
        if ($sideEnum === null) {
            return;
        }

        // Bounded by THIS guide's own cap FOR THIS SIDE, not the table's maximum: a class guide
        // has one comp slot and no enemy slots at all, and accepting slot 1 or 2 there would let a
        // tampered request build a comp inside it.
        if ($slot >= 0 && $slot < $this->guide->maxSlotsFor($sideEnum)) {
            $this->pickingSlot = $slot;
            $this->pickingSide = $sideEnum->value;
        }
    }

    /** Open the picker for a class guide's single opponent ("Rogue vs Disc"). */
    public function openGuideOpponentPicker(): void
    {
        if ($this->guide->type->hasGuideOpponent()) {
            $this->pickingGuideOpponent = true;
        }
    }

    public function closeGuideOpponentPicker(): void
    {
        $this->pickingGuideOpponent = false;
    }

    /**
     * Name the opponent this class guide is written against.
     *
     * Steps already added are deliberately kept, same as every other roster edit here — they name
     * real abilities, and a corrected opponent should not silently delete authored work.
     */
    public function setGuideOpponent(int $specId): void
    {
        if ($this->guide->type->hasGuideOpponent() && Specialization::whereKey($specId)->exists()) {
            $this->guide->update(['opponent_spec_id' => $specId]);
        }

        $this->pickingGuideOpponent = false;
        $this->refreshGuide();
    }

    public function clearGuideOpponent(): void
    {
        $this->guide->update(['opponent_spec_id' => null]);
        $this->refreshGuide();
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
        $side = UserGuideMemberSide::tryFrom($this->pickingSide) ?? UserGuideMemberSide::Team;

        if ($slot === null || $slot >= $this->guide->maxSlotsFor($side) || ! Specialization::whereKey($specId)->exists()) {
            return;
        }

        UserGuideMember::updateOrCreate(
            ['user_guide_id' => $this->guide->id, 'side' => $side->value, 'position' => $slot],
            ['spec_id' => $specId],
        );

        $this->pickingSlot = null;
        $this->pickingSide = UserGuideMemberSide::Team->value;
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
    public function removeMember(int $position, string $side = 'team'): void
    {
        $this->rosterQuery($side)->where('position', $position)->delete();
        $this->refreshGuide();
    }

    /** One side's roster rows, or an always-empty query for a side this guide does not have. */
    private function rosterQuery(string $side)
    {
        $sideEnum = UserGuideMemberSide::tryFrom($side);

        if ($sideEnum === null || $this->guide->maxSlotsFor($sideEnum) === 0) {
            return $this->guide->members()->whereRaw('1 = 0');
        }

        return $sideEnum === UserGuideMemberSide::Enemy
            ? $this->guide->enemies()
            : $this->guide->members();
    }

    /**
     * Open the talent tree for one comp slot, creating that slot's own build on first use.
     *
     * The build is created here rather than when the member is added, so a guide that never
     * touches talents never accumulates a build row it does not use — and, more importantly, so
     * an untouched slot keeps resolving through the spec's admin default, which is the honest
     * answer for "the author did not say".
     */
    public function openTalents(int $slot, string $side = 'team'): void
    {
        $member = $this->rosterQuery($side)->where('position', $slot)->first();

        if (! $member || ! $member->spec_id) {
            return;
        }

        app(TalentSelectionService::class)->getOrCreateGuideMemberBuild($member);

        $this->editingTalentsFor = $slot;
        $this->editingTalentsSide = $member->side->value;
        $this->refreshGuide();
    }

    public function closeTalents(): void
    {
        $this->editingTalentsFor = null;

        // The tree wrote straight to the build, so every cached palette and every resolved step
        // for this guide is now stale. Dropping the computed properties is what makes the change
        // visible the moment the tree closes rather than on the next full page load.
        $this->refreshGuide();
    }

    /**
     * Drop a slot back to the spec's admin-curated default build.
     *
     * Deletes the guide's own build row rather than emptying it: an empty build and "no build" are
     * different states, and an empty one would resolve every ability to its untalented numbers
     * instead of the meta default, which is not what "reset" should mean.
     */
    public function resetTalents(int $slot, string $side = 'team'): void
    {
        $member = $this->rosterQuery($side)->where('position', $slot)->first();
        $build = $member?->talentBuild;

        if ($build === null) {
            return;
        }

        // Order matters: clear the reference first, so a failure deleting the build cannot leave
        // the member pointing at a row that no longer exists.
        $member->forceFill(['talent_build_id' => null])->save();
        $build->delete();

        $this->editingTalentsFor = null;
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
            'opponent_spec_id' => $this->inheritedOpponentFor($sectionKind),
        ]);

        $this->refreshGuide();
    }

    /**
     * A Defensives section in a class guide starts pointed at the guide's own opponent, since the
     * author has already said who they are writing against. Null everywhere else — a comp guide's
     * VS columns are per-section on purpose, and a section may not carry an opponent at all.
     */
    private function inheritedOpponentFor(UserGuideSectionKind $kind): ?int
    {
        return $kind->usesOpponent() && $this->guide->type->hasGuideOpponent()
            ? $this->guide->opponent_spec_id
            : null;
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
            'opponent_spec_id' => $this->inheritedOpponentFor($sectionKind),
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
        $offered = $this->paletteFor($section->id)
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
    /**
     * Publish, and on the FIRST publish only, give the guide a slug that says what it is.
     *
     * The slug is generated at row-creation time, before there is a title or a comp, so a real
     * guide would otherwise keep living at /g/chris/untitled-guide forever — the slug deliberately
     * never regenerates on rename, because moving a URL people already hold breaks their links.
     * That rule protects a PUBLISHED guide; a draft has no such links to protect, which is exactly
     * why this is the last safe moment to rebuild it. published_at is what marks the difference,
     * so a later unpublish/republish leaves the URL alone.
     *
     * REDIRECTS AFTERWARDS, and that is not optional: this component is route-bound on the slug
     * (/guides/{guide}/edit), so changing it without moving the browser leaves the address bar
     * pointing at a slug that no longer resolves — the page would look fine until the author hit
     * refresh and got a 404.
     */
    public function publish(): void
    {
        if (! $this->guide->hasRoster()) {
            return;
        }

        auth()->user()->resolveUsername();

        $firstPublish = $this->guide->published_at === null;

        $this->guide->update([
            'status' => UserGuideStatus::Published,
            'published_at' => $this->guide->published_at ?? now(),
        ]);

        if ($firstPublish) {
            $newSlug = $this->guide->descriptiveSlug();

            if ($newSlug !== $this->guide->slug) {
                $this->guide->update(['slug' => $newSlug]);
                $this->redirect(route('guides.edit', $this->guide->fresh()), navigate: true);

                return;
            }
        }

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

        // Guild visibility needs a guild to be visible TO. Rather than refuse the click, adopt
        // the author's only guild when there is exactly one (the overwhelmingly common case), and
        // otherwise leave guild_id for setGuild() to fill — the picker appears alongside.
        if ($value === UserGuideVisibility::Guild && $this->guide->guild_id === null) {
            $guilds = auth()->user()->guilds()->get();

            if ($guilds->count() === 1) {
                $this->guide->guild_id = $guilds->first()->id;
            } elseif ($guilds->isEmpty()) {
                // Nothing to share with, so this would silently make the guide unreadable by
                // anyone but its author. Say so instead of accepting it.
                return;
            }
        }

        $this->guide->visibility = $value;
        $this->guide->save();
        $this->refreshGuide();
    }

    /** Point a guild-visible guide at one of YOUR guilds. Never one you are not in. */
    public function setGuild(int $guildId): void
    {
        if (! auth()->user()->guilds()->whereKey($guildId)->exists()) {
            return;
        }

        $this->guide->update(['guild_id' => $guildId]);
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
            // Deliberately generic and obviously a placeholder. A sequence's title is now the
            // thing that says what it is (see UserGuideSectionKind), so seeding it with a
            // confident-sounding "The go" would invite authors to leave it alone.
            UserGuideSectionKind::Sequence => 'Untitled sequence',
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

        // The comp key is denormalised from the roster, so it has to be rebuilt wherever the
        // roster can have changed. Doing it here rather than at each call site means a future
        // roster action cannot forget — and it is a no-op when nothing moved.
        $this->guide->syncCompKey();

        $this->guide->refresh();

        unset(
            $this->rows,
            $this->resolved,
            $this->members,
            $this->enemies,
            $this->editingTalentsMember,
            $this->viewers,
            $this->health,
        );

        // The palette memo is keyed by section and lives for the request, so a roster or talent
        // change made earlier in THIS request must not be served from it afterwards.
        $this->paletteMemo = [];
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
