<!-- resources/views/replays/upload.blade.php -->
<x-app-layout>
    <div class="max-w-xl mx-auto p-4 bg-white rounded shadow">
        <h1 class="text-2xl font-bold mb-4">Upload StarCraft II Replay</h1>

        @if(session('success'))
            <div class="bg-green-200 p-2 mb-4 rounded">{{ session('success') }}</div>
        @endif

        <form action="{{ route('replays.store') }}" method="POST" enctype="multipart/form-data">
            @csrf

            <label for="replay" class="block font-semibold mb-1">Replay File (.SC2Replay)</label>
            <input type="file" name="replay" id="replay" accept=".sc2replay,.SC2Replay" required class="border p-2 w-full rounded" />
            @error('replay')
                <p class="text-red-600 mt-1">{{ $message }}</p>
            @enderror

            <button type="submit" class="mt-4 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                Upload & Review
            </button>
        </form>
    </div>
</x-app-layout>
