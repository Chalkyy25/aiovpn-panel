@props(['label' => '', 'name' => '', 'options' => []])

<div>
    @if($label)
<label {{ $attributes->merge(['class' => 'block text-sm font-medium mb-1']) }}>
    {{ $slot ?? $value }}
</label>
    @endif
    <select
        name="{{ $name }}"
        {{ $attributes->merge(['class' => 'block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500']) }}
    >
        @foreach($options as $value => $display)
            <option value="{{ $value }}">{{ $display }}</option>
        @endforeach
    </select>
</div>
