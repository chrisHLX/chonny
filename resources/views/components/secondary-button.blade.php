<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center gap-1.5 px-4 py-1.5 text-[13px] font-semibold text-ink border border-ink-subtle hover:border-ink rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-line disabled:opacity-50 disabled:cursor-not-allowed']) }}>
    {{ $slot }}
</button>
