<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <x-auth.social-buttons/>

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

        <x-input-error :messages="$errors->get('g-recaptcha-response')" class="mt-1.5" />

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

    {{-- A new player sent here by the app's Connect button has no account yet (2026-10-11). The
         intended URL survives the detour, so signing up brings them back to the same place. --}}
    <p class="mt-6 pt-5 border-t border-line text-center text-[13.5px] text-ink-muted">
        New here? <a href="{{ route('register') }}" class="text-gold font-semibold hover:text-gold-light">Make an account</a>
    </p>

    <script src="https://www.google.com/recaptcha/api.js?render={{ config('services.recaptcha.site_key') }}"></script>
    <script>
        document.getElementById('login-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var form = this;
            // One submit (2026-10-26): reCAPTCHA takes about a second before the form goes, and a second
            // press in that time sent it twice; the first signed in and replaced the token, and the
            // second showed "419 Page Expired" over a sign-in that had worked.
            if (form.dataset.sending) { return; }
            form.dataset.sending = '1';
            var button = form.querySelector('button[type="submit"], button:not([type])');
            if (button) { button.disabled = true; button.style.opacity = '.6'; }
            grecaptcha.ready(function () {
                grecaptcha.execute('{{ config('services.recaptcha.site_key') }}', {action: 'login'}).then(function (token) {
                    document.getElementById('g-recaptcha-response-login').value = token;
                    form.submit();
                });
            });
        });
        // Back to this page from the browser's cache: the form can be sent again.
        window.addEventListener('pageshow', function () {
            var f = document.getElementById('login-form');
            delete f.dataset.sending;
            var b = f.querySelector('button[type="submit"], button:not([type])');
            if (b) { b.disabled = false; b.style.opacity = ''; }
        });
    </script>
</x-guest-layout>
