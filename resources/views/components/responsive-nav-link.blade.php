{{-- resources/views/components/responsive-nav-link.blade.php --}}
@props([
    'active' => false,
    'icon' => null,
    'variant' => 'pup',      {{-- neon | mag | pup | cya | slate --}}
    'collapseAware' => false, {{-- mobile drawer never collapses, so false --}}
])

<x-nav-link
    :href="$attributes->get('href')"
    :active="$active"
    :icon="$icon"
    :variant="$variant"
    :collapse-aware="$collapseAware"
    {{ $attributes->except(['href']) }}
>
    {{ $slot }}
</x-nav-link>