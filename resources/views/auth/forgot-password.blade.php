<x-guest-layout>
    <p class="text-[12px] text-ink-muted mb-5">
        Enter your email and we'll send you a password reset link.
    </p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <div class="flex justify-end">
            <x-primary-button>Send Reset Link</x-primary-button>
        </div>
    </form>
</x-guest-layout>
