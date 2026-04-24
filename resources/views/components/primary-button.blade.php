<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-accent/40 disabled:opacity-50 disabled:cursor-not-allowed']) }}>
    {{ $slot }}
</button>
