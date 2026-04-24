@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'text-[12px] font-medium text-emerald-400']) }}>
        {{ $status }}
    </div>
@endif
