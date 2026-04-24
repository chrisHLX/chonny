@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-surface-1 border border-line text-ink placeholder-ink-subtle rounded-md px-3 py-2 text-[13px] focus:outline-none focus:ring-1 focus:ring-accent focus:border-accent transition-colors w-full disabled:opacity-50 disabled:cursor-not-allowed']) }}>
