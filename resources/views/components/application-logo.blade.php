@props([
    // 'mark' = small square for navbar, 'full' = wider version for auth pages
    'type' => 'mark',
    'alt'  => 'AIO VPN',
])

@php
    // Using the same file for both variants. If you later add two files,
    // change these to branding/logo-mark.svg and branding/logo-full.svg.
    $src = asset('images/logo.svg');

    $common = $attributes->merge([
        'alt'      => $alt,
        'loading'  => 'eager',
        'decoding' => 'async',
    ]);
@endphp

@if ($type === 'mark')
    <img
        src="{{ $src }}"
        {{ $common->merge(['class' => 'h-9 w-9 rounded-md ring-1 ring-black/5 dark:ring-white/10 object-contain bg-white']) }}
    >
@else
    <img
        src="{{ $src }}"
        {{ $common->merge(['class' => 'h-12 sm:h-14 w-auto object-contain']) }}
    >
@endif