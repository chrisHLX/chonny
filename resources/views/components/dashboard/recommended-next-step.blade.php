{{-- resources/views/components/dashboard/recommended-next-step.blade.php --}}
@props(['type' => 'module', 'data' => null, 'reason' => null])

@php
    $module      = null;
    $legacyTitle = null;

    if ($type === 'module' && $data) {
        if ($data instanceof \App\Models\Module) {
            $module = $data;
        } elseif (is_string($data)) {
            $legacyTitle = $data;
        }
    }
@endphp

@if ($type === 'module' && ($module || $legacyTitle))
    <div class="linear-card p-5 relative overflow-hidden border border-gold/20">
        <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/20"/>
        <x-mc-icon name="icon-compass" class="w-6 h-6 text-gold mb-2"/>
        <p class="text-[10px] font-semibold text-gold uppercase tracking-widest mb-2">Recommended Next Step</p>
        <p class="text-[13px] font-medium text-ink mb-1">{{ $module?->name ?? $legacyTitle }}</p>
        @if ($reason)
            <p class="text-[12px] text-ink-muted leading-relaxed mb-4">{{ $reason }}</p>
        @elseif ($module?->description)
            <p class="text-[12px] text-ink-muted leading-relaxed mb-4">{{ $module->description }}</p>
        @endif
        @if ($module)
            <a href="{{ route('modules.quiz', $module) }}"
               class="btn-primary text-[12px] w-full">
                Start Module
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        @endif
    </div>
@endif
