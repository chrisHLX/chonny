@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-[12px] font-medium text-ink-muted mb-1.5']) }}>
    {{ $value ?? $slot }}
</label>
