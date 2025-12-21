@props([
    'href'    => null,
    'type'    => 'button',
    'variant' => 'primary',  // primary | secondary | danger | ghost
    'size'    => 'base',     // sm | base | lg
])

@php
    $base = 'inline-flex items-center justify-center rounded-md font-medium transition
             focus:outline-none focus:ring-2 focus:ring-offset-0
             disabled:opacity-50 disabled:cursor-not-allowed';

    $sizes = [
        'sm'   => 'px-3 py-1.5 text-xs',
        'base' => 'px-4 py-2 text-sm',
        'lg'   => 'px-5 py-3 text-base',
    ];

    $variants = [
        'primary' => 'bg-[var(--aio-accent)] text-white hover:opacity-95
                      focus:ring-[var(--aio-accent)]',

        'secondary' => 'bg-[var(--aio-card)] text-[var(--aio-ink)] border border-[var(--aio-border)]
                        hover:bg-[var(--aio-hover)]
                        focus:ring-[var(--aio-accent)]',

        'danger' => 'bg-[var(--aio-danger)] text-white hover:brightness-95
                     focus:ring-[var(--aio-danger)]',

        'ghost' => 'bg-transparent text-[var(--aio-ink)]
                    hover:bg-[var(--aio-hover)]
                    focus:ring-[var(--aio-accent)]',
    ];

    $sizeClass    = $sizes[$size] ?? $sizes['base'];
    $variantClass = $variants[$variant] ?? $variants['primary'];

    $class = trim("$base $sizeClass $variantClass");
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $class]) }}>
        {{ $slot }}
    </button>
@endif