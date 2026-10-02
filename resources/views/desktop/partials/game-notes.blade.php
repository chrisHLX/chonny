{{-- Notes on the whole game: written against it in the app, or before it as "for the next game". --}}
@if ($notes)
    <div class="section">
        <div class="label">Notes on this game</div>
        <div class="box">
            @foreach ($notes as $n)
                <div class="note">
                    @if ($n['next'])<span class="flag">Before it</span>@endif
                    {{ $n['text'] }}
                    <span class="small subtle">&nbsp;{{ $n['when'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif
