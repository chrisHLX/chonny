{{-- Last step of a brand-new Battle.net sign-up. Battle.net shares no email address, so this is the
     one thing we still need. An email already in use is refused rather than merged — nothing here
     proves the person typing it owns that inbox (see BattlenetController). --}}
<x-guest-layout>
    <div class="mb-5">
        <p class="text-[11px] uppercase tracking-[0.12em] text-ink-subtle">Almost done</p>
        <h1 class="mt-1 text-[18px] font-semibold text-ink">Signed in as {{ $battletag }}</h1>
        <p class="mt-1.5 text-[13px] text-ink-muted leading-snug">
            @if ($characterCount > 0)
                We found {{ $characterCount }} {{ Str::plural('character', $characterCount) }} on your account.
            @endif
            Add an email so we can reach you about your account. Your characters are linked as soon as you continue.
        </p>
    </div>

    <form method="POST" action="{{ route('battlenet.finish.store') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="email" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
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

        <div class="flex items-center justify-between pt-1">
            <a href="{{ route('login') }}"
               class="text-[12px] text-ink-subtle hover:text-ink-muted transition-colors">
                Already have an account?
            </a>
            <x-primary-button>Create account</x-primary-button>
        </div>
    </form>
</x-guest-layout>
