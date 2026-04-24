<section>
    <header class="mb-5">
        <h2 class="page-section-title">Profile Information</h2>
        <p class="page-section-desc">Update your account's profile information and email address.</p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="space-y-4">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-1.5" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-1.5" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="mt-2">
                    <p class="text-[12px] text-ink-muted">
                        Your email is unverified.
                        <button form="send-verification"
                                class="text-accent hover:text-accent-hover underline transition-colors">
                            Resend verification email
                        </button>
                    </p>
                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-1 text-[12px] text-emerald-400">Verification link sent.</p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3 pt-1">
            <x-primary-button>Save</x-primary-button>
            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }" x-show="show" x-transition
                   x-init="setTimeout(() => show = false, 2000)"
                   class="text-[12px] text-emerald-400">Saved.</p>
            @endif
        </div>
    </form>
</section>
