@props(['active'])

@php
$classes = ($active ?? false)
            ? 'xui-nav-link xui-nav-link-active'
            : 'xui-nav-link';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
