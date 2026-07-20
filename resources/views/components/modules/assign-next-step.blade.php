@props(['module', 'users'])

<div class="linear-card p-6">
    <h3 class="page-section-title mb-1">Assign as Next Step</h3>
    <p class="page-section-desc mb-4">
        Directly recommend this module to a specific user as their next active step — replaces whatever they currently have pending.
        Only users who've completed the {{ $module->subject->name }} diagnostic are listed — without one, the recommendation would never show up on their dashboard.
    </p>

    @if($users->isEmpty())
        <p class="text-[13px] text-ink-subtle">No users have completed the {{ $module->subject->name }} diagnostic yet.</p>
    @else
        <form method="POST" action="{{ route('modules.assign-next-step', $module) }}" class="space-y-3">
            @csrf

            <select name="user_id" required class="form-select w-full">
                <option value="" disabled selected>Select a user…</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </select>

            <div>
                <textarea name="why" rows="2" maxlength="1000" class="form-textarea w-full"
                          placeholder="Why this module, for them specifically? (optional — shown in the email they get. Leave blank for a generic message.)"></textarea>
                <x-input-error :messages="$errors->get('why')" class="mt-1.5" />
            </div>

            <x-primary-button type="submit">Assign &amp; Email</x-primary-button>
        </form>
    @endif
    <x-input-error :messages="$errors->get('user_id')" class="mt-1.5" />
</div>
