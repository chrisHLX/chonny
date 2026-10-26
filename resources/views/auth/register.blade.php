<x-guest-layout>
    {{-- Says what the account is FOR before asking for anything. The dev notice below used to be
         the first thing here and said features "may change or reset" — the wrong thing to tell
         someone about to spend an evening writing a plan, and not true of saved guides. --}}
    <div class="mb-5">
        <h1 class="font-display text-[22px] text-ink leading-tight">Plan your arena games</h1>
        <p class="text-[13px] text-ink-muted mt-1.5 leading-snug">
            Build your opener and your go from real spell data, see what survives diminishing
            returns, and share the plan with the people you queue with. Free.
        </p>
    </div>

    <div class="flex items-start gap-2.5 mb-5 px-3 py-2 rounded-md bg-gold-subtle border border-line-gold">
        <x-mc-icon name="icon-flask" class="w-4 h-4 text-gold shrink-0 mt-0.5"/>
        <p class="text-[12px] text-ink-muted leading-snug">
            <span class="text-gold font-semibold">Early days.</span>
            Expect rough edges, and tell us what's missing.
        </p>
    </div>

    <x-auth.social-buttons/>

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
            // One submit (2026-10-26): reCAPTCHA takes about a second before the form goes, and a second
            // press in that time sent it twice; the first signed in and replaced the token, and the
            // second showed "419 Page Expired" over a sign-in that had worked.
            if (form.dataset.sending) { return; }
            form.dataset.sending = '1';
            var button = form.querySelector('button[type="submit"], button:not([type])');
            if (button) { button.disabled = true; button.style.opacity = '.6'; }
            grecaptcha.ready(function () {
                grecaptcha.execute('{{ config('services.recaptcha.site_key') }}', {action: 'register'}).then(function (token) {
                    document.getElementById('g-recaptcha-response-register').value = token;
                    form.submit();
                });
            });
        });
        // Back to this page from the browser's cache: the form can be sent again.
        window.addEventListener('pageshow', function () {
            var f = document.getElementById('register-form');
            delete f.dataset.sending;
            var b = f.querySelector('button[type="submit"], button:not([type])');
            if (b) { b.disabled = false; b.style.opacity = ''; }
        });
    </script>
</x-guest-layout>
