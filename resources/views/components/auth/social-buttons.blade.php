{{-- One-click sign in / sign up, shared by the login and register pages. Each button only renders
     when its provider is configured, so a missing key hides a button rather than breaking one. The
     terms line is what records acceptance for these paths (see SocialSignupService). --}}
@php
    $google = \App\Http\Controllers\Auth\GoogleAuthController::isConfigured();
    $battlenet = app(\App\Http\Services\BattlenetClient::class)->isConfigured();
@endphp

@if ($google || $battlenet)
    <div class="space-y-2.5">
        @if ($google)
            <a href="{{ route('google.redirect') }}"
               class="flex items-center justify-center gap-2.5 w-full px-4 py-2.5 rounded-md border border-line-strong bg-surface-2 hover:border-ink-subtle text-[13.5px] font-medium text-ink transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                    <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/>
                    <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0124 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/>
                    <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 01-4.087 5.571l.003-.002 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
                </svg>
                Continue with Google
            </a>
        @endif

        @if ($battlenet)
            <a href="{{ route('battlenet.redirect') }}"
               class="flex items-center justify-center gap-2.5 w-full px-4 py-2.5 rounded-md border border-[#148EFF]/50 bg-[#148EFF]/10 hover:bg-[#148EFF]/20 text-[13.5px] font-medium text-ink transition-colors">
                <span class="w-2 h-2 rounded-full bg-[#148EFF]" aria-hidden="true"></span>
                Continue with Battle.net
            </a>
        @endif

        <p class="text-[11px] text-ink-subtle text-center leading-snug">
            By continuing you agree to the
            <a href="{{ route('terms') }}" target="_blank" rel="noopener noreferrer" class="text-ink-muted hover:text-gold transition-colors">Terms of Service</a>
            and
            <a href="{{ route('privacy') }}" target="_blank" rel="noopener noreferrer" class="text-ink-muted hover:text-gold transition-colors">Privacy Policy</a>.
        </p>
    </div>

    <div class="flex items-center gap-3 my-5">
        <span class="flex-1 h-px bg-line-strong"></span>
        <span class="text-[11px] uppercase tracking-[0.12em] text-ink-subtle">or with email</span>
        <span class="flex-1 h-px bg-line-strong"></span>
    </div>
@endif
