{{-- A record's win rate, marked when it rests on too few games to read much into. --}}
<span class="{{ $r['rate'] >= 50 ? 'rate-up' : 'rate-down' }}">{{ (int) $r['rate'] }}%</span>@if ($r['lead'])<span class="few" title="Fewer than 10 games"> small sample</span>@endif
