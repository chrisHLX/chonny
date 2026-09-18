@props(['anchor', 'notes', 'open' => false, 'label' => 'this step'])

{{-- Criticism attached to one step or section, on a published guide.

     WHY THIS EXISTS SEPARATELY FROM THE GUIDE'S COMMENT THREAD. A flat comment gets "this is
     wrong". A note on step 3 gets "this doesn't force Pain Suppression, Disc just shields it" —
     which is the one input the game data cannot supply (arena-structure.md Part 16.3). The thread
     at the bottom of the page is for the guide as a whole and is unchanged. --}}

@php $notes = collect($notes); @endphp

@if ($notes->isNotEmpty() || $open)
    <div class="mt-2 pl-4 border-l-2 border-violet/40 space-y-2">
        @foreach ($notes as $n)
            <div class="text-[12.5px]" wire:key="note-{{ $n->id }}">
                <span class="text-violet">&#64;{{ $n->user?->handle() ?? 'someone' }}</span>
                <span class="text-ink-muted">{{ $n->body }}</span>
                <span class="text-ink-subtle text-[11px]">&middot; {{ $n->created_at->diffForHumans() }}</span>
                @if (auth()->id() === $n->user_id)
                    <button type="button" wire:click="deleteComment({{ $n->id }})"
                            class="text-[11px] text-ink-subtle hover:text-red-400 ml-1">Delete</button>
                @endif
            </div>
        @endforeach

        @if ($open)
            <div class="pt-1">
                <textarea wire:model="note" rows="2" maxlength="1000"
                          placeholder="What's wrong with {{ $label }}, and why?"
                          class="form-textarea w-full text-[13px]"></textarea>
                <div class="flex items-center gap-2 mt-1.5">
                    <button type="button" wire:click="postNote" class="btn-secondary text-[12px]">Post note</button>
                    <button type="button" wire:click="startNote('{{ $anchor }}')"
                            class="text-[12px] text-ink-subtle hover:text-gold">Cancel</button>
                </div>
            </div>
        @endif
    </div>
@endif
