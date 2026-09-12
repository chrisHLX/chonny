@props(['equipment' => []])

{{-- A character's equipped items. Icons are self-hosted (storage/app/public/item-icons/, see
     BattlenetCharacterSyncService::withItemIcons()) and referenced host-relative, same as spell
     icons; an item whose icon could not be fetched gets a plain placeholder, never a broken image.
     Quality colours are Blizzard's own, unchanged since launch. --}}
@php
    $qualityColors = [
        'POOR' => '#9D9D9D', 'COMMON' => '#FFFFFF', 'UNCOMMON' => '#1EFF00', 'RARE' => '#0070DD',
        'EPIC' => '#A335EE', 'LEGENDARY' => '#FF8000', 'ARTIFACT' => '#E6CC80', 'HEIRLOOM' => '#00CCFF',
    ];
@endphp

@if (empty($equipment))
    <p class="text-[13px] text-ink-subtle">No gear on file yet.</p>
@else
    <div {{ $attributes->merge(['class' => 'grid sm:grid-cols-2 gap-x-6 gap-y-2']) }}>
        @foreach ($equipment as $item)
            @php $color = $qualityColors[$item['quality']] ?? '#FFFFFF'; @endphp
            <div class="flex items-start gap-2.5 min-w-0" wire:key="gear-{{ $item['slot'] }}">
                @if (! empty($item['icon']))
                    <img src="/storage/item-icons/{{ $item['icon'] }}" alt="" loading="lazy"
                         class="w-9 h-9 rounded border object-cover shrink-0" style="border-color: {{ $color }}">
                @else
                    <div class="w-9 h-9 rounded border bg-surface-2 shrink-0" style="border-color: {{ $color }}"></div>
                @endif

                <div class="min-w-0 flex-1">
                    <p class="text-[10px] uppercase tracking-[0.12em] text-ink-subtle leading-none mb-0.5">
                        {{ $item['slot_name'] }}
                        @if ($item['level'])
                            <span class="text-ink-muted tabular-nums">&middot; {{ $item['level'] }}</span>
                        @endif
                    </p>
                    <p class="text-[13px] leading-tight truncate" style="color: {{ $color }}" title="{{ $item['name'] }}">{{ $item['name'] }}</p>
                    @foreach (array_merge($item['enchantments'] ?? [], $item['gems'] ?? []) as $extra)
                        <p class="text-[11px] text-ink-subtle leading-snug truncate" title="{{ $extra }}">{{ $extra }}</p>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
