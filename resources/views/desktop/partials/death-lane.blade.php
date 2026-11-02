{{-- One lane of the death chart: a label and its spans, a row for each that overlaps. --}}
@forelse ($lane['rows'] as $i => $row)
    <div class="dt-row">
        <div class="dt-lab">@if ($i === 0){{ $lane['label'] }}@endif</div>
        <div class="dt-track"><div class="dt-in">
            @foreach ($row as $b)@include('desktop.partials.death-bar', ['b' => $b])@endforeach
        </div></div>
    </div>
@empty
    <div class="dt-row">
        <div class="dt-lab">{{ $lane['label'] }}</div>
        <div class="dt-track"><div class="dt-in"><span class="dt-free">None</span></div></div>
    </div>
@endforelse
