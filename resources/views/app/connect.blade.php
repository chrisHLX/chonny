{{-- Connecting the desktop app to an account (/wow/app/connect). See AppDownloadController. --}}
<x-app-layout title="Connect the MindCollector app">
<div class="max-w-xl mx-auto px-4 py-10 sm:py-16">
    <div class="page-hero text-center">
        @if ($done)
            <div class="w-14 h-14 mx-auto rounded-full bg-emerald-500/15 text-emerald-300 flex items-center justify-center">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h1 class="page-title !text-3xl mt-5">You're connected</h1>
            <p class="text-[15px] text-ink-muted mt-3">
                Go back to the app. It picks this up in a few seconds, and you can close this tab.
            </p>
        @elseif ($code === null)
            <h1 class="page-title !text-3xl">This link has expired</h1>
            <p class="text-[15px] text-ink-muted mt-3">
                Open the MindCollector app and click Connect again. It opens a fresh link.
            </p>
        @else
            {{-- One button, the caution under it (2026-10-27): a new player read "Connect the app on this
                 PC?" and "Only do this if...", never pressed Connect, and their app gave up waiting. --}}
            <p class="page-eyebrow">One last step</p>
            <h1 class="page-title !text-3xl">Connect the app to your account</h1>
            <p class="text-[15px] text-ink-muted mt-3">
                Signed in as <span class="text-ink font-semibold">{{ auth()->user()->name }}</span>. Once connected,
                each arena game you play shows up in the app and on Your games about a minute after it ends.
            </p>
            <form method="POST" action="{{ route('app.connect.approve') }}" class="mt-7">
                @csrf
                <input type="hidden" name="code" value="{{ $code }}">
                <button type="submit" class="btn-primary w-full sm:w-auto !px-12 !py-4 !text-[17px]">Connect this PC</button>
            </form>
            <p class="text-[12.5px] text-ink-subtle mt-5">
                Only if you opened this from the MindCollector app on your own PC. It replaces any app key you made before.
            </p>
        @endif
    </div>
</div>
</x-app-layout>
