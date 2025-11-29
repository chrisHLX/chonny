<x-app-layout>
    <div class="max-w-3xl mx-auto p-6">
    <h1 class="text-xl font-bold mb-4">Suggested Next Modules</h1>

    <div class="space-y-4">
        @foreach ($suggestions as $s)
            <div class="bg-gray-800 p-4 rounded shadow">
                <h3 class="text-lg font-semibold">{{ $s['name'] }}</h3>
                <p class="text-gray-300">{{ $s['description'] }}</p>
                <small class="text-gray-500">{{ $s['subject'] }} — {{ $s['proficiency'] }}</small>

                <div class="mt-3">
                    @if ($s['exists'])
                        {{-- Existing module → allow user to assign it --}}
                        @if ($s['assigned'])
                            <p>You Done this Module</p>
                        @else
                        <form action="{{ route('modules.assign', $s['module_id']) }}" method="POST">
                            @csrf
                            <button class="bg-blue-500 px-3 py-1 rounded text-white hover:bg-blue-600">
                                Add Module
                            </button>
                        </form>
                        @endif
                    @else
                        {{-- Suggestion not created yet → allow user to create it --}}
                        <form action="{{ route('modules.create-suggested') }}" method="POST">
                            @csrf
                            <input type="hidden" name="suggestion" value="{{ json_encode($s) }}">
                            <button class="bg-green-500 px-3 py-1 rounded text-white hover:bg-green-600">
                                Create Module
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
</x-app-layout>
