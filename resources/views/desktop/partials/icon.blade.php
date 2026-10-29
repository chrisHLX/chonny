{{-- A small line icon, drawn inline in the text's colour (2026-10-29, the dashboard layout): no file to
     fetch, so a card stays one document. $n names it; an unknown name draws nothing. --}}
@php
    $paths = [
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'shield' => '<path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6z"/>',
        'heart' => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>',
        'flame' => '<path d="M12 3c1 3 4 5 4 9a4 4 0 0 1-8 0c0-2 1-3 2-4 0 2 1 3 2 3 0-3-1-5 0-8z"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'swords' => '<path d="M4 4l10 10M14 18l4-4M16 16l4 4M20 4L10 14M6 14l4 4M8 16l-4 4"/>',
        'ban' => '<circle cx="12" cy="12" r="9"/><path d="M6 6l12 12"/>',
        'skull' => '<path d="M12 3a7 7 0 0 0-7 7c0 2.5 1.3 4 3 5v3h8v-3c1.7-1 3-2.5 3-5a7 7 0 0 0-7-7z"/><circle cx="9.5" cy="10.5" r="1.3"/><circle cx="14.5" cy="10.5" r="1.3"/>',
        'chart' => '<path d="M5 19V11M12 19V5M19 19v-6"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14c3 0 5 1.5 5 4.5"/>',
        'note' => '<rect x="5" y="4" width="14" height="16" rx="2"/><path d="M9 9h6M9 13h6M9 17h3"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'trophy' => '<path d="M8 4h8v5a4 4 0 0 1-8 0zM8 6H5a3 3 0 0 0 3 3M16 6h3a3 3 0 0 1-3 3M12 13v4M9 20h6"/>',
        'bolt' => '<path d="M13 3L5 14h6l-1 7 8-11h-6z"/>',
        'play' => '<path d="M8 5l11 7-11 7z"/>',
    ];
@endphp
@isset($paths[$n])<svg class="ico{{ isset($cls) ? ' '.$cls : '' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $paths[$n] !!}</svg>@endisset
