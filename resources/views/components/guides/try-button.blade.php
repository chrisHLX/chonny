{{-- Start a plan without an account (see GuestPlanService). A form, not a link, because it creates a
     row and a link would create one every time a browser prefetched it. Signed in, the same route
     just starts a normal draft. --}}
@props(['type' => 'comp', 'label' => 'Try the planner'])

<form method="POST" action="{{ route('guides.try', ['type' => $type]) }}" class="contents">
    @csrf
    <button type="submit" {{ $attributes->has('class') ? $attributes : $attributes->merge(['class' => 'btn-primary']) }}>{{ $label }}</button>
</form>
