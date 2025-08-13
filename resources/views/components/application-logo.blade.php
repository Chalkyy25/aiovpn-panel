@props(['class' => 'h-10 w-auto'])

<img
    src="{{ asset('images/logo.svg') }}"
    alt="AIO VPN"
    {{ $attributes->merge(['class' => $class]) }}
/>