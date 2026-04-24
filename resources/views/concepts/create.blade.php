<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-xl mx-auto">

            <div class="mb-6">
                <h1 class="text-[17px] font-semibold text-ink">Add Concept</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Create a new concept for tagging questions.</p>
            </div>

            @if (session('success'))
                <div class="flex items-center gap-2 px-3 py-2 bg-emerald-500/10 border border-emerald-500/20 rounded-md mb-4">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 shrink-0"></span>
                    <p class="text-[12px] text-emerald-400">{{ session('success') }}</p>
                </div>
            @endif

            <div class="linear-card p-6">
                <form method="POST" action="{{ route('concepts.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label :value="'Name'" />
                        <x-text-input type="text" name="name" :value="old('name')" required />
                    </div>

                    <div>
                        <x-input-label :value="'Type'" />
                        <select name="type" required class="form-select mt-1.5">
                            @foreach ($types as $type)
                                <option value="{{ $type }}" @selected(old('type') == $type)>
                                    {{ ucfirst($type) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input-label :value="'Description'" />
                        <textarea name="description" rows="4" class="form-textarea mt-1.5">{{ old('description') }}</textarea>
                    </div>

                    <x-primary-button>Add Concept</x-primary-button>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
