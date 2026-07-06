{{-- resources/views/components/dashboard/evidence-panel.blade.php --}}
@props(['profile' => null])

@php
    if (!$profile) return;

    $evidence        = $profile['evidence'] ?? [];
    $selfReportCheck = $profile['self_report_check'] ?? null;

    // Filter out self-reported evidence for main panel; show observed evidence only
    $observedEvidence = array_values(array_filter($evidence, fn($e) => !str_starts_with($e['signal'] ?? '', 'Self-reported')));
@endphp

@if (!empty($observedEvidence) || ($selfReportCheck && !empty($selfReportCheck['comment'])))
    <div class="linear-card relative overflow-hidden" x-data="{ open: false }">
        <button @click="open = !open"
                class="w-full flex items-center justify-between px-5 py-4 text-left">
            <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide">Why This Was Chosen</p>
            <svg class="w-4 h-4 text-ink-subtle transition-transform duration-200"
                 :class="open ? 'rotate-180' : ''"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-collapse class="px-5 pb-5 space-y-4">
            {{-- Observed evidence --}}
            @foreach ($observedEvidence as $item)
                <div class="{{ !$loop->first ? 'border-t border-line pt-4' : '' }}">
                    <p class="text-[12px] font-semibold text-ink mb-1">{{ $item['signal'] ?? '' }}</p>
                    <p class="text-[12px] text-ink-muted leading-relaxed">{{ $item['interpretation'] ?? '' }}</p>
                    @if (!empty($item['score']))
                        <p class="text-[10px] text-ink-subtle mt-2">
                            Signal: <span class="font-mono text-ink-muted">{{ $item['score'] }}</span>
                        </p>
                    @endif
                </div>
            @endforeach

            {{-- Self-report alignment --}}
            @if ($selfReportCheck && !empty($selfReportCheck['comment']))
                <div class="border-t border-line pt-4">
                    @php
                        $alignment = $selfReportCheck['alignment'] ?? 'insufficient_data';
                        $alignmentLabel = match($alignment) {
                            'aligned'           => 'Profile-matched',
                            'conflicting'       => 'Conflicting signal',
                            'partially_aligned' => 'Partially aligned',
                            default             => 'Insufficient data',
                        };
                    @endphp
                    <p class="text-[12px] font-semibold text-ink-subtle mb-1">
                        {{ $alignmentLabel }}
                    </p>
                    <p class="text-[12px] text-ink-muted leading-relaxed">{{ $selfReportCheck['comment'] }}</p>
                </div>
            @endif
        </div>
    </div>
@endif
