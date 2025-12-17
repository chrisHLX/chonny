@props(['card'])

@php
$proficiencyColors = [
    1 => 'border-white',
    2 => 'border-gray-300',               // silver
    3 => 'border-yellow-400',             // gold
    4 => 'border-emerald-400',            // emerald
    5 => 'border-cyan-300',               // diamond
    6 => 'border-red-500 border-double',  // red & diamond
    7 => 'border-yellow-400 border-emerald-400 border-double', 
    8 => 'border-yellow-400 border-emerald-400 border-cyan-300 border-double',
];
@endphp

@php
    // Get proficiencies for THIS module
    $proficiencies = $card->module->proficiencies;

    // If you don't have a difficulty rank column, use 'id' or anything predictable
    $ordered = $proficiencies->sortBy('id')->values();

    // Locate which tier the card's selected proficiency belongs to
    $currentIndex = $ordered->search(fn($p) => $p->id === $card->proficiency_id);

    // Tier becomes 1–8
    $tier = $currentIndex !== false ? $currentIndex + 1 : 1;

    // Pick the CSS class
    $borderClass = $proficiencyColors[$tier] ?? 'border-white';
@endphp


<div class="rounded-xl shadow border-4 {{ $borderClass }}">
    <div class="bg-white shadow rounded-lg overflow-hidden">

        <div class="p-4 border-b">
            <div class="text-lg font-semibold">{{ $card->module->name }}</div>
        </div>

        <div class="h-40 bg-gray-100 flex items-center justify-center">
            <img src="{{ asset($card->image_path) }}" 
                 alt="{{ $card->module->name }}" 
                 class="h-32 object-contain">
        </div>

        <div class="p-4">
            <div class="text-sm font-semibold mb-2">Stats</div>

            <div class="text-sm mb-3">
                @forelse($card->stats as $stat => $value)
                    <div>+{{ $value }} {{ ucfirst($stat) }}</div>
                @empty
                    <div class="text-gray-400">No stats</div>
                @endforelse
            </div>

            <div class="text-sm text-gray-700">
                <div><strong>Proficiency:</strong> {{ $card->proficiency->name ?? '—' }}</div>
                <div><strong>Mint #:</strong> {{ str_pad($card->mint_number, 5, '0', STR_PAD_LEFT) }}</div>
                <div class="text-yellow-500"><strong>{{ $card->edition }}</strong> ★</div>
            </div>
        </div>

    </div>
</div>
