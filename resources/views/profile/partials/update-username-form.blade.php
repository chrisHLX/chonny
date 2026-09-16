<section>
    <header class="mb-5">
        <h2 class="page-section-title">Handle</h2>
        <p class="page-section-desc">
            How other players see you and add you as a friend. It's also in your shared guide links &mdash;
            links you've already shared keep working after a change.
        </p>
    </header>

    <form method="post" action="{{ route('profile.username') }}" class="space-y-4">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="username" :value="__('Handle')" />
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[14px] text-ink-subtle pointer-events-none">&#64;</span>
                <x-text-input id="username" name="username" type="text" class="pl-7"
                              :value="old('username', $user->username)" required
                              minlength="3" maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false" />
            </div>
            <p class="mt-1.5 text-[12px] text-ink-subtle">3&ndash;30 characters: letters, numbers, - or _.</p>
            <x-input-error class="mt-1.5" :messages="$errors->get('username')" />
        </div>

        <div class="flex items-center gap-3 pt-1">
            <x-primary-button>Save handle</x-primary-button>
            @if (session('status') === 'username-updated')
                <p x-data="{ show: true }" x-show="show" x-transition
                   x-init="setTimeout(() => show = false, 2000)"
                   class="text-[12px] text-emerald-400">Saved.</p>
            @endif
        </div>
    </form>
</section>
