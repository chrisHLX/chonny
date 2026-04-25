<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form id="login-form" method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-login">

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <div class="flex items-center gap-2">
            <input id="remember_me" type="checkbox" name="remember"
                   class="w-3.5 h-3.5 rounded border-line bg-surface-2 text-accent focus:ring-accent/30 focus:ring-1">
            <label for="remember_me" class="text-[12px] text-ink-muted cursor-pointer">Remember me</label>
        </div>

        <div class="flex items-center justify-between pt-1">
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}"
                   class="text-[12px] text-ink-subtle hover:text-ink-muted transition-colors">
                    Forgot password?
                </a>
            @endif

            <x-primary-button>
                Sign in
            </x-primary-button>
        </div>
    </form>

    <script src="https://www.google.com/recaptcha/api.js?render={{ config('services.recaptcha.site_key') }}"></script>
    <script>
        document.getElementById('login-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var form = this;
            grecaptcha.ready(function () {
                grecaptcha.execute('{{ config('services.recaptcha.site_key') }}', {action: 'login'}).then(function (token) {
                    document.getElementById('g-recaptcha-response-login').value = token;
                    form.submit();
                });
            });
        });
    </script>
</x-guest-layout>
