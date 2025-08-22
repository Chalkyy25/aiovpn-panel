@props(['for'])

<label {{ $attributes->merge(['for' => $for, 'class' => 'block font-medium text-sm text-gray-700']) }}>
    {{ $slot }}
</label>
