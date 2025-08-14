@props([
    // 'mark' = compact (navbar), 'full' = larger (auth pages, headers)
    'type' => 'mark',
    'alt'  => 'AIO VPN',
])

@php
    $src = asset('images/logo.svg');

    // Base attributes (avoid duplicating "alt"/"loading"/"decoding")
    $base = [
        'alt'      => $alt,
        'loading'  => 'eager',
        'decoding' => 'async',
        'class'    => 'select-none object-contain', // no drag ghost; keep aspect
    ];

    $classes = $type === 'mark'
        // ~40–44px tall works well in a navbar
        ? 'h-11 w-auto'
        // Slightly larger for hero/auth screens
        : 'h-14 sm:h-16 w-auto';
@endphp

<img src="{{ $src }}" {{ $attributes->merge($base)->class($classes) }}>