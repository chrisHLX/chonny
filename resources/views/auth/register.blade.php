<x-guest-layout>
    <div class="flex items-start gap-2.5 mb-5 px-3 py-2.5 rounded-md bg-gold-subtle border border-line-gold">
        <x-mc-icon name="icon-flask" class="w-4 h-4 text-gold shrink-0 mt-0.5"/>
        <p class="text-[12px] text-ink-muted leading-snug">
            <span class="text-gold font-semibold">Actively in development.</span>
            Expect rough edges, and features may change or reset while we build.
        </p>
    </div>

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

        <div class="flex items-start gap-2 pt-1">
            <input id="terms" type="checkbox" name="terms" value="1" @checked(old('terms'))
                   class="form-checkbox mt-0.5" required>
            <label for="terms" class="text-[13px] text-ink-muted leading-snug">
                I agree to the
                <a href="{{ route('terms') }}" target="_blank" rel="noopener noreferrer" class="text-gold hover:text-gold-light transition-colors">Terms of Service</a>
                and
                <a href="{{ route('privacy') }}" target="_blank" rel="noopener noreferrer" class="text-gold hover:text-gold-light transition-colors">Privacy Policy</a>.
            </label>
        </div>
        <x-input-error :messages="$errors->get('terms')" class="mt-1.5" />

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
