@props([
    'type' => 'mark', // 'mark' | 'full'
    'alt'  => 'AIO VPN',
])

@if ($type === 'mark')
    <img
        src="{{ asset('images/branding/logo-mark.svg') }}"
        alt="{{ $alt }}"
        {{ $attributes->merge(['class' => 'h-9 w-9 rounded-md ring-1 ring-black/5 dark:ring-white/10']) }}
        loading="eager"
        decoding="async"
    >
@else
    <img
        src="{{ asset('images/branding/logo-full.svg') }}"
        alt="{{ $alt }}"
        {{ $attributes->merge(['class' => 'h-8 w-auto']) }}
        loading="eager"
        decoding="async"
    >
@endif