@props(['label' => '', 'name' => ''])

<div>
    @if($label)
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
    @endif
    <textarea
        name="{{ $name }}"
        rows="4"
        {{ $attributes->merge(['class' => 'block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500']) }}
    ></textarea>
</div>
