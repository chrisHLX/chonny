@props(['anchor', 'notes', 'open' => false, 'label' => 'this section', 'author' => null])

{{-- Reader comments on one section of a published guide.

     PER SECTION, NOT PER STEP (2026-09-18). It was per step first, and the first real reader said
     it was confusing and that he could not tell whether his note was overwriting the guide's own
     step notes. Two different things were both called "notes": the author's annotation on a step,
     and a reader's criticism of it. So readers now comment on the SECTION, the word "note" is gone
     from the reader's side entirely, and the author's own annotations stay where they are and are
     attributed by name. The block anchor still exists in the schema and the export still reads it
     — old notes are not lost — it just is not offered in the UI any more. --}}

@php $notes = collect($notes); @endphp

<div class="mt-3 pt-3 border-t border-line">
    <div class="flex items-center justify-between gap-3">
        <span class="text-[11px] uppercase tracking-[0.13em] text-ink-subtle">
            {{ $notes->isEmpty() ? 'Comments' : 'Comments ('.$notes->count().')' }}
        </span>

        @auth
            <button type="button" wire:click="startNote('{{ $anchor }}')"
                    class="text-[12px] text-violet hover:text-violet-hover transition-colors">
                {{ $open ? 'Cancel' : '+ Comment on this section' }}
            </button>
        @else
            <a href="{{ route('login') }}" class="text-[12px] text-ink-subtle hover:text-gold">Sign in to comment</a>
        @endauth
    </div>

    @if ($notes->isNotEmpty())
        <div class="mt-2 space-y-2">
            @foreach ($notes as $n)
                <div class="text-[12.5px] pl-3 border-l-2 border-violet/40" wire:key="note-{{ $n->id }}">
                    <span class="text-violet">&#64;{{ $n->user?->handle() ?? 'someone' }}</span>
                    <span class="text-ink-muted">{{ $n->body }}</span>
                    <span class="text-ink-subtle text-[11px]">&middot; {{ $n->created_at->diffForHumans() }}</span>
                    @if (auth()->id() === $n->user_id)
                        <button type="button" wire:click="deleteComment({{ $n->id }})"
                                class="text-[11px] text-ink-subtle hover:text-red-400 ml-1">Delete</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($open)
        <div class="mt-2">
            <textarea wire:model="note" rows="3" maxlength="1000"
                      placeholder="What's wrong with {{ $label }}, and what would you do instead?"
                      class="form-textarea w-full text-[13px]"></textarea>
            <div class="flex items-center gap-2 mt-1.5">
                <button type="button" wire:click="postNote" class="btn-secondary text-[12px]">Post comment</button>
                <span class="text-[11px] text-ink-subtle">
                    Yours &mdash; it sits under this section and changes nothing
                    {{ $author ? 'in '.$author.'\'s plan' : 'in the guide' }}.
                </span>
            </div>
        </div>
    @endif
</div>
