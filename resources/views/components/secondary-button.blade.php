<button {{ $attributes->merge([
    'type' => 'button',
    'class' => 'inline-flex items-center px-4 py-2 rounded-xl font-semibold text-xs uppercase tracking-widest '
        .'bg-white/10 text-white border border-white/10 '
        .'hover:bg-white/20 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-[#070b14] '
        .'disabled:opacity-25 transition ease-in-out duration-150'
]) }}>
    {{ $slot }}
</button>
