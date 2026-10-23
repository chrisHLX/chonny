{{-- Lesson content blocks. Every readable block carries a stable data-block id so notes can anchor to it. --}}
@foreach ($blocks as $i => $block)
    @php($id = $prefix.'-'.$i)
    @if (is_string($block))
        <p data-block="{{ $id }}">{!! \App\Training\Prose::inline($block) !!}</p>
    @elseif (isset($block['p']))
        <p data-block="{{ $id }}">{!! \App\Training\Prose::inline($block['p']) !!}</p>
    @elseif (isset($block['h']))
        <h3 data-block="{{ $id }}">{!! \App\Training\Prose::inline($block['h']) !!}</h3>
    @elseif (isset($block['list']))
        <ul class="t-pts" data-block="{{ $id }}">
            @foreach ($block['list'] as $item)<li><span>{!! \App\Training\Prose::inline($item) !!}</span></li>@endforeach
        </ul>
    @elseif (isset($block['note']))
        <aside class="t-note" data-block="{{ $id }}">{!! \App\Training\Prose::inline($block['note']) !!}</aside>
    @elseif (isset($block['table']))
        <div class="overflow-x-auto"><table class="t-tbl" data-block="{{ $id }}">
            <thead><tr>@foreach ($block['table']['head'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>@foreach ($block['table']['rows'] as $row)<tr>@foreach ($row as $cell)<td>{!! \App\Training\Prose::inline($cell) !!}</td>@endforeach</tr>@endforeach</tbody>
        </table></div>
    @elseif (isset($block['sim']))
        @include('training.sims.'.$block['sim'])
    @endif
@endforeach
