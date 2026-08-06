@props(['spell', 'size' => 'w-8 h-8'])

@php
    // Deliberately a host-relative path ('/storage/...'), not Storage::disk('public')->url()
    // — that builds an ABSOLUTE URL from the static APP_URL config value, which doesn't
    // necessarily match whatever domain Herd is actually serving this project on in the
    // browser (e.g. APP_URL=http://localhost but the real site is on a Herd .test domain).
    // A relative path resolves against whatever host the page itself was loaded from, so it
    // can't point at the wrong origin regardless of local dev domain setup.
    $url = $spell->icon_name ? '/storage/spell-icons/'.$spell->icon_name : null;
@endphp

@if ($url)
    <img
        src="{{ $url }}"
        alt="{{ $spell->name }}"
        loading="lazy"
        {{ $attributes->merge(['class' => "{$size} rounded border border-line object-cover flex-shrink-0"]) }}
    >
@else
    {{-- No icon fetched for this spell (e.g. a hidden/internal sub-spell record with no real
         Blizzard-side icon — see data/spelldata/fetch-spell-icons.php's "not_found" cases) —
         a plain placeholder box, never a broken <img>. --}}
    <div {{ $attributes->merge(['class' => "{$size} rounded border border-line-strong bg-surface-2 flex-shrink-0"]) }}></div>
@endif
