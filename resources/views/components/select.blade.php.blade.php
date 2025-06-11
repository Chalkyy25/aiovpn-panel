<select {{ $attributes->merge(['class' => 'border border-gray-300 rounded w-full px-3 py-2 focus:outline-none focus:ring focus:ring-black/30']) }}>
    {{ $slot }}
</select>
