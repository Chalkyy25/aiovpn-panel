@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'black',
    'size' => 'base',
])

@php
    $baseClasses = 'inline-flex items-center justify-center rounded text-sm font-medium focus:outline-none transition';

    $variants = [
        'black' => 'bg-black text-white hover:bg-gray-800',
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        'light' => 'bg-white text-black border border-gray-300 hover:bg-gray-100',
    ];

    $sizes = [
        'base' => 'px-4 py-2',
        'sm' => 'px-3 py-1 text-sm',
        'lg' => 'px-5 py-3 text-base',
    ];

    $variantClass = $variants[$variant] ?? $variants['black'];
    $sizeClass = $sizes[$size] ?? $sizes['base'];
    $finalClass = "$baseClasses $variantClass $sizeClass";
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class([$finalClass]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class([$finalClass]) }}>
        {{ $slot }}
    </button>
@endif
