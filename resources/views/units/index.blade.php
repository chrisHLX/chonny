<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Units Guide') }}
        </h2>
    </x-slot>

    <div class="container mx-auto p-4">
        @foreach($units as $unit)
            <div class="bg-white shadow rounded p-4 mb-6">
                <h2 class="text-2xl font-semibold mb-2">{{ $unit->name }} <span class="text-sm text-gray-500">({{ $unit->race }})</span></h2>
                <p class="mb-4">{{ $unit->description }}</p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                    @foreach($unit->attributes as $attr)
                        <div class="border p-2 rounded">
                            <strong>{{ $attr->attribute_name }}</strong><br>
                            {{ $attr->value }}
                        </div>
                    @endforeach
                </div>

                @if($unit->abilities->count())
                    <div class="mb-4">
                        <span class="text-sm text-gray-500">({{ $unit->type }})</span>
                        <h3 class="font-semibold">Abilities:</h3>
                        <ul class="list-disc list-inside">
                            @foreach($unit->abilities as $ability)
                                <li>
                                    <strong>{{ $ability->name }}</strong>: {{ $ability->description }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($unit->counters->count())
                    <div>
                        <h3 class="font-semibold">Counters:</h3>
                        <ul class="list-disc list-inside">
                            @foreach($unit->counters as $counter)
                                <li>
                                    <strong>{{ $counter->counterUnit->name }}</strong>
                                    ({{ $counter->effectiveness }} effectiveness)
                                    <br>
                                    <span class="text-gray-600">{{ $counter->notes }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-app-layout>
