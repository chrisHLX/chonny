<x-guest-layout>
    <p class="text-[12px] text-ink-muted mb-5">
        Enter your email and we'll send you a password reset link.
    </p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form id="forgot-password-form" method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-forgot">

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <div class="flex justify-end">
            <x-primary-button>Send Reset Link</x-primary-button>
        </div>
    </form>

    <script src="https://www.google.com/recaptcha/api.js?render={{ config('services.recaptcha.site_key') }}"></script>
    <script>
        document.getElementById('forgot-password-form').addEventListener('submit', function (e) {
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
                grecaptcha.execute('{{ config('services.recaptcha.site_key') }}', {action: 'forgot_password'}).then(function (token) {
                    document.getElementById('g-recaptcha-response-forgot').value = token;
                    form.submit();
                });
            });
        });
        // Back to this page from the browser's cache: the form can be sent again.
        window.addEventListener('pageshow', function () {
            var f = document.getElementById('forgot-password-form');
            delete f.dataset.sending;
            var b = f.querySelector('button[type="submit"], button:not([type])');
            if (b) { b.disabled = false; b.style.opacity = ''; }
        });
    </script>
</x-guest-layout>
