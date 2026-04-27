@props(['card'])

@php
$tiers = [
    1 => [
        'border' => 'border-white',
        'text' => 'text-gray-800',
        'accent' => 'text-gray-400',
        'glow' => '',
        'accentHex' => '#9CA3AF',
    ],
    2 => [
        'border' => 'border-green-300',
        'text' => 'text-gray-700',
        'accent' => 'text-gray-500',
        'glow' => '',
        'accentHex' => '#86EFAC',
    ],
    3 => [
        'border' => 'border-yellow-400',
        'text' => 'text-yellow-600',
        'accent' => 'text-yellow-500',
        'glow' => 'shadow-yellow-200/50',
        'accentHex' => '#FACC15',
    ],
    4 => [
        'border' => 'border-emerald-400',
        'text' => 'text-emerald-600',
        'accent' => 'text-emerald-500',
        'glow' => 'shadow-emerald-200/50',
        'accentHex' => '#34D399',
    ],
    5 => [
        'border' => 'border-cyan-300',
        'text' => 'text-cyan-600',
        'accent' => 'text-cyan-500',
        'glow' => 'shadow-cyan-200/50',
        'accentHex' => '#67E8F9',
    ],
    6 => [
        'border' => 'border-red-500 border-double',
        'text' => 'text-red-600',
        'accent' => 'text-red-500',
        'glow' => 'shadow-red-300/60',
        'accentHex' => '#EF4444',
    ],
    7 => [
        'border' => 'border-yellow-400 border-emerald-400 border-double',
        'text' => 'text-emerald-600',
        'accent' => 'text-yellow-500',
        'glow' => 'shadow-emerald-300/60',
        'accentHex' => '#34D399',
    ],
    8 => [
        'border' => 'border-yellow-400 border-emerald-400 border-cyan-300 border-double',
        'text' => 'text-cyan-600',
        'accent' => 'text-yellow-400',
        'glow' => 'shadow-cyan-300/70',
        'accentHex' => '#67E8F9',
    ],
];

$editionStyles = [
    'First Edition' => [
        'badge' => 'bg-gradient-to-r from-yellow-400 via-amber-300 to-yellow-500 text-black',
        'text' => 'text-yellow-600',
        'glow' => 'shadow-[0_0_25px_rgba(234,179,8,0.6)]',
        'icon' => '★',
    ],
    'Limited' => [
        'badge' => 'bg-purple-100 text-purple-700 border border-purple-300',
        'text' => 'text-purple-600',
        'glow' => 'shadow-purple-200/40',
        'icon' => '◆',
    ],
    'Common' => [
        'badge' => 'bg-gray-100 text-gray-600 border border-gray-200',
        'text' => 'text-gray-500',
        'glow' => '',
        'icon' => '',
    ],
];

$edition = $card->edition ?? 'Common';
$editionStyle = $editionStyles[$edition] ?? $editionStyles['Common'];

// Proficiencies come from the SUBJECT (not module) need to update this to do it by index when I do a fresh migrate on laptop
/* UPDATED VERSION WHEN I FRESH MIGRATE TO USE INDEX INSTEAD OF ID
$tier = $card->proficiency->index + 1;
$style = $tiers[$tier] ?? $tiers[1];
*/

$proficiencies = $card->module->subject->proficiencies
    ->sortBy('id')
    ->values();

$currentIndex = $proficiencies->search(fn ($p) => $p->id === $card->proficiency_id);

$tier = $currentIndex !== false ? $currentIndex + 1 : 1;

$style = $tiers[$tier] ?? $tiers[1];


@endphp

<div {{ $attributes->merge([
    'class' => 'rounded-xl border-4 h-full w-full flex flex-col ' . $style['border'] . '
                shadow-lg transition-transform duration-200 hover:-translate-y-1'
        ]) }}>

    {{-- HEADER --}}
    <div class="p-4 border-b">
        <div class="text-lg font-bold {{ $style['text'] }}
                    {{ $edition === 'First Edition' ? 'drop-shadow-sm' : '' }}">
            {{ $card->module->name }}
        </div>
    </div>

    {{-- IMAGE --}}
    <div class="h-40 overflow-hidden flex items-center justify-center bg-surface-2">
        @if($card->image_path && $card->image_path !== 'cards/default.svg')
            <img
                src="{{ asset($card->image_path) }}"
                alt="{{ $card->module->name }}"
                class="h-full w-full object-cover"
            >
        @else
            @php
                $svgId = 'card-' . $card->id;
                $accent = $style['accentHex'];
            @endphp
            <svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 240 160" preserveAspectRatio="xMidYMid slice">
                <defs>
                    <linearGradient id="grad-{{ $svgId }}" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#1C1C1F"/>
                        <stop offset="100%" stop-color="#242428"/>
                    </linearGradient>
                    <radialGradient id="glow-{{ $svgId }}" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="{{ $accent }}" stop-opacity="0.08"/>
                        <stop offset="100%" stop-color="{{ $accent }}" stop-opacity="0"/>
                    </radialGradient>
                    <pattern id="dots-{{ $svgId }}" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">
                        <circle cx="1" cy="1" r="0.8" fill="{{ $accent }}" opacity="0.15"/>
                    </pattern>
                </defs>
                <rect width="240" height="160" fill="url(#grad-{{ $svgId }})"/>
                <rect width="240" height="160" fill="url(#dots-{{ $svgId }})"/>
                <rect width="240" height="160" fill="url(#glow-{{ $svgId }})"/>
                <x-concept-polygon :module="$card->module" :id="$svgId" :accent="$accent" :width="240" :height="160" />
            </svg>
        @endif
    </div>

    {{-- BODY --}}
    <div class="p-4">

        {{-- PROFICIENCY BADGE --}}
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold uppercase tracking-wide {{ $style['accent'] }}">
                Proficiency
            </span>

            <span class="px-2 py-0.5 text-xs rounded-full border {{ $style['border'] }} {{ $style['text'] }}">
                {{ $card->proficiency->name ?? '—' }}
            </span>
        </div>

        {{-- STATS --}}
        <div class="text-sm text-gray-700 mb-3">
            @forelse($card->stats as $stat => $value)
                <div>+{{ $value }} {{ ucfirst($stat) }}</div>
            @empty
                <div class="text-gray-400">No stats</div>
            @endforelse
        </div>

        {{-- FOOTER --}}
        <div class="text-xs text-gray-600">
            <div>
                <strong>Mint #:</strong>
                {{ str_pad($card->mint_number, 5, '0', STR_PAD_LEFT) }}
            </div>
            <div class="mt-2 flex items-center gap-2">
                <span class="px-2 py-0.5 text-xs font-bold rounded-full {{ $editionStyle['badge'] }}">
                    {{ $editionStyle['icon'] }} {{ strtoupper($edition) }}
                </span>
            </div>
        </div>

    </div>
</div>
