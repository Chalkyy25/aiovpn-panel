<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center px-4 py-2 bg-[var(--aio-accent)] text-white border border-transparent rounded-md font-semibold text-xs uppercase tracking-widest hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-[var(--aio-accent-weak)] transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
