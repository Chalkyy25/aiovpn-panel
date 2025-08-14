@props([
    'active' => false,
    'icon'   => null,   // e.g. "o-home" if you use <x-icon>
])

@php
$classes = $active
    ? 'group flex items-center gap-3 px-3 py-2 rounded-md bg-blue-50 text-blue-700 font-medium'
    : 'group flex items-center gap-3 px-3 py-2 rounded-md text-gray-700 hover:bg-gray-50';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    @if($icon)
        <x-icon :name="$icon" class="w-5 h-5 shrink-0 {{ $active ? 'text-blue-600' : 'text-gray-500 group-hover:text-gray-700' }}" />
    @endif

    {{-- Hide label when the parent layout is collapsed --}}
    <span class="truncate" x-show="!$root.sidebarCollapsed">
        {{ $slot }}
    </span>
</a>
