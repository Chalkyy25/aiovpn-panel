@props([
    'href'    => null,
    'type'    => 'button',
    'variant' => 'primary',  // primary | secondary | danger | ghost
    'size'    => 'base',     // sm | base | lg
])

@php
    $base = 'inline-flex items-center justify-center rounded-md font-medium transition
             focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed';

    $sizes = [
        'sm'   => 'px-3 py-1.5 text-xs',
        'base' => 'px-4 py-2 text-sm',
        'lg'   => 'px-5 py-3 text-base',
    ];

    // Uses your CSS variables (works in light + dark)
    $variants = [
        'primary' => 'bg-[var(--aio-accent)] text-white hover:opacity-95
                      focus:ring-[var(--aio-accent)] focus:ring-offset-[var(--aio-bg)]',

        'secondary' => 'bg-[var(--aio-card)] text-[var(--aio-ink)] border border-[var(--aio-border)]
                        hover:bg-[var(--aio-hover)]
                        focus:ring-[var(--aio-accent)] focus:ring-offset-[var(--aio-bg)]',

        'danger' => 'bg-red-600 text-white hover:bg-red-700
                     focus:ring-red-500 focus:ring-offset-[var(--aio-bg)]',

        'ghost' => 'bg-transparent text-[var(--aio-ink)]
                    hover:bg-[var(--aio-hover)]
                    focus:ring-[var(--aio-accent)] focus:ring-offset-[var(--aio-bg)]',
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