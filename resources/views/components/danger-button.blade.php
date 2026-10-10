<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-danger focus:outline-none focus:ring-2 focus:ring-red-500/30 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
