{{--
    One habit by session (ImprovementService::spark): a point per session, oldest first, the last
    one marked, and the other players' level dashed. Inline SVG, which the app's IE11 control draws.
--}}
<svg width="{{ $s['w'] }}" height="{{ $s['h'] }}" xmlns="http://www.w3.org/2000/svg" style="display: block">
    @if ($s['ref'] !== null)
        <line x1="0" y1="{{ $s['ref'] }}" x2="{{ $s['w'] }}" y2="{{ $s['ref'] }}" stroke="#52525F" stroke-width="1" stroke-dasharray="3,3"/>
    @endif
    <polyline fill="none" stroke="#C8952C" stroke-width="1.5" points="{{ $s['points'] }}"/>
    <circle cx="{{ $s['last'][0] }}" cy="{{ $s['last'][1] }}" r="3" fill="#E8B84B"/>
</svg>
