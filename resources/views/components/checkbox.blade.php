@props(['label' => '', 'name' => ''])

<div class="flex items-center space-x-2">
    <input
        type="checkbox"
        name="{{ $name }}"
        {{ $attributes->merge(['class' => 'rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500']) }}
    >
    <label class="text-sm text-gray-700">{{ $label }}</label>
</div>
