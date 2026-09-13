@props(['section', 'ownerId' => null])

{{-- Who last changed a section, when it was not the guide's author. Shared by the builder and the
     read view so both credit collaborators the same way.

     Silent when the author made the last change: on a guide one person wrote, "edited by you" on
     every section is noise. It appears exactly when a friend or guildmate touched the section —
     which is the case a reader and the author both want to know about. --}}
@php
    $editor = $section->relationLoaded('updatedBy') ? $section->updatedBy : null;
@endphp

@if ($editor && $ownerId !== null && $editor->id !== $ownerId)
    <p class="text-[11px] text-ink-subtle mt-1">
        Last edited by <span class="text-violet">{{ $editor->id === auth()->id() ? 'you' : '@'.$editor->handle() }}</span>
        &middot; {{ $section->updated_at?->diffForHumans() }}
    </p>
@endif
