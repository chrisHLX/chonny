@props(['metrics', 'tracksControl' => true])

@php
    $m = $metrics;
    $fmt = fn ($v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
    $showControl = $tracksControl && $m['control_steps'] > 0;
    $showFrequency = $m['frequency_seconds'] !== null;
@endphp

@if ($showControl || $showFrequency)
    <div class="flex flex-wrap gap-x-8 gap-y-3 px-3 py-2.5 rounded border border-line bg-surface-2 mb-3">
        @if ($showControl)
            <div>
                <p class="text-[10px] uppercase tracking-[0.13em] text-ink-subtle font-semibold">Control after DR</p>
                @if ($m['control_seconds'] !== null)
                    @php
                        // Assembled in PHP rather than with inline @if directives: a Blade directive
                        // glued to the end of a word is not compiled as a directive at all, which
                        // silently unbalances the template (see CLAUDE.md, 2026-09-08).
                        $detail = $m['control_steps'].' control '.Str::plural('step', $m['control_steps'])
                            .($m['diminished_steps'] > 0 ? ', '.$m['diminished_steps'].' diminished' : '');
                    @endphp
                    <p class="text-[20px] font-semibold text-ink tabular-nums leading-tight">
                        {{ $fmt($m['control_seconds']) }}<span class="text-[13px] text-ink-muted">s</span>
                    </p>
                    <p class="text-[11px] text-ink-subtle">{{ $detail }} &middot; total spent, not one lock</p>
                @else
                    <p class="text-[14px] text-ink-muted leading-tight">Not known</p>
                    <p class="text-[11px] text-ink-subtle">No verified PvP duration on file yet</p>
                @endif

                @if ($m['unknown_duration_steps'] > 0 && $m['control_seconds'] !== null)
                    <p class="text-[11px] text-amber-400">
                        {{ $m['unknown_duration_steps'] }} {{ Str::plural('step', $m['unknown_duration_steps']) }} not counted &mdash; no verified duration
                    </p>
                @endif
            </div>
        @endif

        @if ($showFrequency)
            <div>
                <p class="text-[10px] uppercase tracking-[0.13em] text-ink-subtle font-semibold">Available every</p>
                <p class="text-[20px] font-semibold text-ink tabular-nums leading-tight">
                    {{ $fmt($m['frequency_seconds']) }}<span class="text-[13px] text-ink-muted">s</span>
                </p>
                <p class="text-[11px] text-ink-subtle">Gated by {{ $m['frequency_spell'] }}</p>
            </div>
        @endif
    </div>
@endif
