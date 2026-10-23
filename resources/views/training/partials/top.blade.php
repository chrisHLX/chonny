{{-- Training's stylesheet and its own tab strip, at the top of every Training page. The stylesheet
     is a plain file (public/css/training.css) so a lesson change never needs a front-end build. --}}
<link rel="stylesheet" href="/css/training.css?v={{ filemtime(public_path('css/training.css')) }}">
@auth
    @php
        $trTabs = [
            ['training', 'Today', ['training']],
            ['training.reading:steering-by-feedback', 'Field manual', ['training.reading']],
            ['training.notebook', 'Notebook', ['training.notebook']],
            ['training.claims.index', 'Claims', ['training.claims.*']],
            ['training.progress', 'Progress', ['training.progress', 'training.check']],
        ];
    @endphp
    <nav class="flex flex-wrap gap-2 mb-6" aria-label="Training">
        @foreach ($trTabs as [$r, $label, $active])
            <a href="{{ str_contains($r, ':') ? route(...explode(':', $r)) : route($r) }}" class="tab-btn {{ request()->routeIs(...$active) ? 'tab-active' : 'tab-inactive' }}">{{ $label }}</a>
        @endforeach
        @can('admin')
            <a href="{{ route('training.feedback') }}" class="tab-btn {{ request()->routeIs('training.feedback') ? 'tab-active' : 'tab-inactive' }}">Course feedback</a>
        @endcan
    </nav>
@endauth
@if (session('training_status'))
    <p class="mb-5 rounded-lg bg-emerald-500/10 text-emerald-300 px-4 py-2.5 text-[14px]" role="status">{{ session('training_status') }}</p>
@endif
