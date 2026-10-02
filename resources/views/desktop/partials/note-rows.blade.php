@foreach ($notes as $n)
    <div class="note">
        <span class="t">{{ $n['clock'] }}</span>
        @if ($n['mark'])<span class="flag">Marked</span>@endif
        {{ $n['text'] }}
    </div>
@endforeach
