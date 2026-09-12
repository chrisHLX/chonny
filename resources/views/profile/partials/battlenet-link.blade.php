@php $battlenet = $user->battlenetAccount; @endphp

<section>
    <header>
        <h2 class="text-[15px] font-semibold text-ink">Battle.net</h2>
        <p class="mt-1 text-[13px] text-ink-muted">
            Link your Battle.net account to show your arena exp, ratings, gear and talents, and to sign
            guides with one of your characters.
        </p>
    </header>

    <div class="mt-4 flex items-center justify-between gap-4">
        @if ($battlenet)
            <p class="text-[13px] text-ink">
                Linked as <span class="font-semibold">{{ $battlenet->battletag }}</span>
                <span class="text-ink-subtle">&middot; {{ $battlenet->characters()->count() }} characters</span>
            </p>
            <a href="{{ route('characters.index') }}" class="btn-ghost shrink-0">Manage characters</a>
        @else
            <p class="text-[13px] text-ink-subtle">Not linked.</p>
            <a href="{{ route('battlenet.redirect') }}" class="btn-primary shrink-0">Link Battle.net</a>
        @endif
    </div>
</section>
