@props([
    'type' => 'mark',
    'alt'  => 'AIO VPN',
])

@php
    $src = asset('images/logo.svg');
    $base = [
        'alt'      => $alt,
        'loading'  => 'eager',
        'decoding' => 'async',
        'class'    => 'select-none object-contain',
    ];

    // h-10 = 40px tall (perfect balance)
    $classes = $type === 'mark'
        ? 'h-10 w-auto'
        : 'h-14 sm:h-16 w-auto';
@endphp

<img src="{{ $src }}" {{ $attributes->merge($base)->class($classes) }}>