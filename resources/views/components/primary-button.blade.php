<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-gold/40 disabled:opacity-50 disabled:cursor-not-allowed']) }}>
    {{ $slot }}
</button>
