{{-- resources/views/components/responsive-nav-link.blade.php --}}
@props([
    'href'    => '#',
    'active'  => false,
    'icon'    => null,
    'variant' => 'pup',
])

@php
    // Normalize icon names (same rules as nav-link)
    // - o-home        -> heroicon-o-home
    // - s-user        -> heroicon-s-user
    // - heroicon-*    -> unchanged
    $iconName = null;

    if (!empty($icon)) {
        $icon = (string) $icon;

        if (str_starts_with($icon, 'heroicon-')) {
            $iconName = $icon;
        } elseif (str_starts_with($icon, 'o-') || str_starts_with($icon, 's-')) {
            $iconName = 'heroicon-' . $icon;
        } else {
            $iconName = $icon;
        }
    }

    // Force mobile behaviour
    $classes = $attributes->class('block');
@endphp

<x-nav-link
    :href="$href"
    :active="$active"
    :icon="$iconName"
    :variant="$variant"
    :collapse-aware="false"
    {{ $classes }}
>
    {{ $slot }}
</x-nav-link>