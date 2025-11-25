
<x-app-layout>
    @foreach ($suggestions as $s)
        <div class="suggestion">
            <h3>{{ $s['name'] }}</h3>
            <p>{{ $s['reason'] }}</p>
            <small>{{ $s['subject'] }} — {{ $s['proficiency'] }}</small>
        </div>
    @endforeach
</x-app-layout>