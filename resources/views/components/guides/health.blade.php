@props(['health', 'editable' => false])

{{-- What has drifted under this guide since it was written. Renders nothing at all when there is
     nothing to say, so a healthy guide carries no banner — see
     UserGuideChainService::health() for what each of these means and why steps are re-resolved
     against the current patch on every render rather than frozen at authoring time. --}}

@if ($health['unresolved'] > 0)
    <div class="linear-card p-4 mb-6 border-l-2 border-red-400/60">
        <p class="text-[13px] text-ink font-medium">
            {{ $health['unresolved'] }}
            {{ \Illuminate\Support\Str::plural('step', $health['unresolved']) }}
            no longer {{ $health['unresolved'] === 1 ? 'resolves' : 'resolve' }} to a real ability
        </p>
        <p class="text-[12px] text-ink-muted mt-1">
            @if ($health['sections'])
                In {{ implode(', ', $health['sections']) }}.
            @endif
            The {{ \Illuminate\Support\Str::plural('ability', $health['unresolved']) }}
            {{ $health['unresolved'] === 1 ? 'was' : 'were' }} removed or renamed in a later patch.
            {{ $editable
                ? 'Your steps were kept rather than deleted, so you can see what they were and replace them.'
                : 'The author has not updated this guide since.' }}
        </p>
    </div>
@elseif ($health['patch_changed'])
    <div class="linear-card p-3 mb-6">
        <p class="text-[11.5px] text-ink-subtle">
            Written on patch {{ $health['authored_patch'] }} &middot; you're on {{ $health['current_patch'] }}.
            Every ability here still resolves, and its cooldowns and durations are read live from the
            current patch &mdash; but the plan itself hasn't been reviewed since.
        </p>
    </div>
@endif
