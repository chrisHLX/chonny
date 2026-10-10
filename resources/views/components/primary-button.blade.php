<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-primary focus:outline-none focus:ring-2 focus:ring-gold/40 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:scale-100']) }}>
    {{ $slot }}
</button>
