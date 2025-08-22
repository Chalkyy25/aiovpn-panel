@props([
    'active' => false,
    'icon'   => null,
])

@php
$classes = $active
    ? 'flex items-center gap-3 w-full px-4 py-2 rounded-md text-sm font-medium text-blue-700 bg-blue-50'
    : 'flex items-center gap-3 w-full px-4 py-2 rounded-md text-sm text-gray-700 hover:bg-gray-50';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    @if($icon)
        <x-icon :name="$icon" class="w-5 h-5 shrink-0 {{ $active ? 'text-blue-600' : 'text-gray-500' }}" />
    @endif
    <span class="truncate">{{ $slot }}</span>
</a>
