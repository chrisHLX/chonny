@props(['milestones' => []])

{{--
    Standalone roadmap/stepper component — visually related to (but deliberately not extracted
    from) the "Your Path" timeline on the Progress page. That timeline renders a chronological
    record of past events only (no status concept); this component renders a forward-looking
    plan with three states per item. Forcing them through one shared component would mean
    bolting a status prop onto a component that has never needed one, for two call sites with
    different data shapes (timestamped events vs ordered milestones) — kept separate instead,
    matching the same vertical-line/icon-node visual language by hand.

    Each $milestones entry: ['title' => string, 'detail' => string, 'status' => 'complete'|'next'|'future']
--}}
<div class="linear-card p-5">
    @foreach ($milestones as $milestone)
        @php
            $status = $milestone['status'] ?? 'future';
        @endphp
        <div class="relative pl-10 {{ !$loop->last ? 'pb-6' : '' }}">
            @unless ($loop->last)
                <div class="absolute left-4 top-8 bottom-0 w-px {{ $status === 'complete' ? 'bg-gold/40' : 'bg-line' }}"></div>
            @endunless

            <div class="absolute left-0 top-0 w-8 h-8 rounded-full flex items-center justify-center border
                {{ match($status) {
                    'complete' => 'bg-gold-gradient border-gold text-surface-0',
                    'next'     => 'bg-gold-subtle border-gold text-gold',
                    default    => 'bg-surface-2 border-line text-ink-subtle',
                } }}">
                @if ($status === 'complete')
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                @elseif ($status === 'next')
                    <x-mc-icon name="icon-lightning-circle" class="w-4 h-4"/>
                @else
                    <span class="text-[11px] font-semibold tabular-nums">{{ $loop->iteration }}</span>
                @endif
            </div>

            <div class="flex items-center gap-2 mb-0.5">
                <p class="text-[13px] font-medium {{ $status === 'future' ? 'text-ink-subtle' : 'text-ink' }}">
                    {{ $milestone['title'] }}
                </p>
                @if ($status === 'next')
                    <span class="text-[9px] font-semibold uppercase tracking-widest text-gold px-1.5 py-0.5 rounded-full bg-gold-subtle border border-gold/20">Next</span>
                @endif
            </div>
            @if (!empty($milestone['detail']))
                <p class="text-[12px] leading-relaxed {{ $status === 'future' ? 'text-ink-subtle' : 'text-ink-muted' }}">
                    {{ $milestone['detail'] }}
                </p>
            @endif
        </div>
    @endforeach
</div>
