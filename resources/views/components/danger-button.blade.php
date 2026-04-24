<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-red-400 bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-red-500/30 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
