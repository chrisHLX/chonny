<x-guest-layout>
    <form id="register-form" method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-register">

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1.5" />
        </div>

        <x-input-error :messages="$errors->get('g-recaptcha-response')" class="mt-1.5" />

        <div class="flex items-center justify-between pt-1">
            <a href="{{ route('login') }}"
               class="text-[12px] text-ink-subtle hover:text-ink-muted transition-colors">
                Already registered?
            </a>
            <x-primary-button>Register</x-primary-button>
        </div>
    </form>

    <script src="https://www.google.com/recaptcha/api.js?render={{ config('services.recaptcha.site_key') }}"></script>
    <script>
        document.getElementById('register-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var form = this;
            grecaptcha.ready(function () {
                grecaptcha.execute('{{ config('services.recaptcha.site_key') }}', {action: 'register'}).then(function (token) {
                    document.getElementById('g-recaptcha-response-register').value = token;
                    form.submit();
                });
            });
        });
    </script>
</x-guest-layout>
