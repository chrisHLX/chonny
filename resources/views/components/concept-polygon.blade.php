@props([
    'module',
    'id',
    'accent',
    'width'  => 240,
    'height' => 160,
])

@php
// Two queries: one for the pivot, one for concepts
$questions = $module->questions()->with('concepts')->get();

$conceptCounts = [];
foreach ($questions as $question) {
    foreach ($question->concepts as $concept) {
        $conceptCounts[$concept->name] = ($conceptCounts[$concept->name] ?? 0) + 1;
    }
}

// Sort descending, cap at 8
arsort($conceptCounts);
$conceptCounts = array_slice($conceptCounts, 0, 8, true);
$n = count($conceptCounts);

$cx        = $width  / 2;
$cy        = $height / 2;
$maxRadius = min($width, $height) / 2 * 0.65;
$minRadius = $maxRadius * 0.2;

if ($n >= 3) {
    // Interleave heaviest/lightest alternately to avoid clustering:
    // [sorted desc] → heaviest, lightest, 2nd heaviest, 2nd lightest, ...
    $sorted      = array_values($conceptCounts);
    $left        = 0;
    $right       = $n - 1;
    $interleaved = [];
    $takeFront   = true;
    while ($left <= $right) {
        $interleaved[] = $takeFront ? $sorted[$left++] : $sorted[$right--];
        $takeFront = !$takeFront;
    }

    $maxCount = $sorted[0];
    $points   = [];
    foreach ($interleaved as $i => $count) {
        $angleRad = deg2rad(-90 + ($i * 360 / $n));
        $weight   = $maxCount > 0 ? $count / $maxCount : 0;
        $r        = $minRadius + $weight * ($maxRadius - $minRadius);
        $points[] = round($cx + $r * cos($angleRad), 2) . ',' . round($cy + $r * sin($angleRad), 2);
    }
    $pointsAttr = implode(' ', $points);
}
@endphp

@if($n >= 3)
    <polygon
        id="{{ $id }}"
        points="{{ $pointsAttr }}"
        fill="{{ $accent }}"
        fill-opacity="0.08"
        stroke="{{ $accent }}"
        stroke-width="1"
        stroke-opacity="0.3"
    />
@else
    <circle
        id="{{ $id }}"
        cx="{{ $cx }}" cy="{{ $cy }}"
        r="{{ round($maxRadius * 0.55, 2) }}"
        fill="{{ $accent }}"
        fill-opacity="0.08"
        stroke="{{ $accent }}"
        stroke-width="1"
        stroke-opacity="0.3"
    />
@endif
