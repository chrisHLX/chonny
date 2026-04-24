<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-line disabled:opacity-50 disabled:cursor-not-allowed']) }}>
    {{ $slot }}
</button>
