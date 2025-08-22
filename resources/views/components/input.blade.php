@props(['label' => '', 'name' => '', 'type' => 'text'])

<div>
    @if($label)
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
    @endif
<input {{ $attributes->merge(['class' => 'border rounded w-full px-3 py-2']) }} />

</div>
