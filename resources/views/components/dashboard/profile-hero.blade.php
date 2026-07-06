{{-- resources/views/components/dashboard/profile-hero.blade.php --}}
@props(['profile' => null])

@php
    if (!$profile) return;

    $profileTitle    = $profile['profile_title'] ?? ($profile['player_type'] ?? null);
    $confidence      = $profile['confidence_level'] ?? null;
    $summary         = $profile['summary'] ?? ($profile['narrative'] ?? '');
    $strength        = $profile['primary_strength'] ?? null;
    $growthArea      = $profile['primary_growth_area'] ?? null;

    // Legacy profiles have no primary_strength object — fall back to the flat top_traits list
    if (!$strength && !empty($profile['top_traits'])) {
        $strength = [
            'name'     => 'Key Traits',
            'concepts' => array_map(fn ($trait) => (string) str($trait)->headline(), $profile['top_traits']),
        ];
    }
@endphp

@if ($profileTitle)
    <div class="linear-card p-6 relative overflow-hidden">
        <x-ornament.corner position="tl" class="top-0 left-0 w-10 h-10 text-gold/50"/>
        <x-ornament.corner position="tr" class="top-0 right-0 w-10 h-10 text-gold/50"/>
        <x-ornament.corner position="bl" class="bottom-0 left-0 w-10 h-10 text-gold/50"/>
        <x-ornament.corner position="br" class="bottom-0 right-0 w-10 h-10 text-gold/50"/>

        <x-mc-icon name="icon-compass" class="w-10 h-10 text-gold mb-4"/>

        <h2 class="text-[16px] font-semibold text-ink-muted uppercase tracking-[0.15em] mb-1">Development Focus</h2>
        <p class="text-[20px] font-display italic text-gold-light mb-4">{{ $profileTitle }}</p>

        @if ($confidence)
            <span class="inline-block mb-4 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-widest rounded-full
                {{ $confidence === 'high' ? 'bg-gold-subtle text-gold border border-gold/20' : 'bg-surface-3 text-ink-subtle border border-line' }}">
                {{ $confidence }} confidence
            </span>
        @endif

        @if ($summary)
            <p class="text-[13px] text-ink-muted leading-relaxed mb-5">{{ $summary }}</p>
        @endif

        <div class="grid grid-cols-2 gap-3">
            @if ($strength && !empty($strength['name']))
                <div class="bg-surface-2 border border-line rounded-lg p-3">
                    <p class="text-[10px] font-semibold text-gold uppercase tracking-widest mb-1.5">Strength</p>
                    <p class="text-[12px] font-medium text-ink mb-2">{{ $strength['name'] }}</p>
                    @if (!empty($strength['concepts']))
                        <div class="flex flex-wrap gap-1">
                            @foreach (array_slice($strength['concepts'], 0, 2) as $concept)
                                <span class="text-[10px] text-ink-subtle px-1.5 py-0.5 bg-surface-3 rounded">{{ $concept }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if ($growthArea && !empty($growthArea['name']))
                <div class="bg-surface-2 border border-violet/20 rounded-lg p-3">
                    <p class="text-[10px] font-semibold text-violet uppercase tracking-widest mb-1.5">Growth Area</p>
                    <p class="text-[12px] font-medium text-ink mb-2">{{ $growthArea['name'] }}</p>
                    @if (!empty($growthArea['concepts']))
                        <div class="flex flex-wrap gap-1">
                            @foreach (array_slice($growthArea['concepts'], 0, 2) as $concept)
                                <span class="text-[10px] text-ink-subtle px-1.5 py-0.5 bg-surface-3 rounded">{{ $concept }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endif
