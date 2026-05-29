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
            grecaptcha.ready(function () {
                grecaptcha.execute('{{ config('services.recaptcha.site_key') }}', {action: 'forgot_password'}).then(function (token) {
                    document.getElementById('g-recaptcha-response-forgot').value = token;
                    form.submit();
                });
            });
        });
    </script>
</x-guest-layout>
