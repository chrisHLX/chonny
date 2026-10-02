{{-- Notes written in the desktop app during or just after this game, by round time. --}}
<div class="section">
    <div class="label">Your notes</div>
    <div class="box">
        @if ($notes)
            @include('desktop.partials.note-rows', ['notes' => $notes])
        @else
            <span class="muted">None. During a game the note box takes notes for that round, and Ctrl+Shift+M marks a moment to write about later.</span>
        @endif
    </div>
</div>
