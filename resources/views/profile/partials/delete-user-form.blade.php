<section>
    <header class="mb-5">
        <h2 class="page-section-title">Delete Account</h2>
        <p class="page-section-desc">Once deleted, all your data will be permanently removed.</p>
    </header>

    <x-danger-button x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
        Delete Account
    </x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-[14px] font-semibold text-ink mb-1">Delete your account?</h2>
            <p class="text-[12px] text-ink-muted mb-5">
                This action is permanent. Enter your password to confirm.
            </p>

            <div class="mb-5">
                <x-input-label for="password" value="Password" class="sr-only" />
                <x-text-input id="password" name="password" type="password" placeholder="Password" class="w-full" />
                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-1.5" />
            </div>

            <div class="flex justify-end gap-2">
                <x-secondary-button x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button>Delete Account</x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
