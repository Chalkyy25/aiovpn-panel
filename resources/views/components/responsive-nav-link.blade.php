{{-- resources/views/components/responsive-nav-link.blade.php --}}
@props([
    'href'    => '#',
    'active'  => false,
    'icon'    => null,
    'variant' => 'pup',
])

@php
    // Forward props into nav-link, ensuring collapseAware is false for mobile
    $classes = $attributes->class('block'); 
@endphp

<x-nav-link
    :href="$href"
    :active="$active"
    :icon="$icon"
    :variant="$variant"
    :collapse-aware="false"
    {{ $classes }}
>
    {{ $slot }}
</x-nav-link>