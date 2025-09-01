@props([
    'href'    => '#',
    'active'  => false,
    'icon'    => null,
    'variant' => 'pup',
])

<x-nav-link
    :href="$href"
    :active="$active"
    :icon="$icon"
    :variant="$variant"
    :collapse-aware="false"
    {{ $attributes->class('block') }}
>
    {{ $slot }}
</x-nav-link>