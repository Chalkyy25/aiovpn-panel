@props(['active'])

@php
$classes = ($active ?? false)
            ? 'xui-mobile-nav-link xui-mobile-nav-link-active'
            : 'xui-mobile-nav-link';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
