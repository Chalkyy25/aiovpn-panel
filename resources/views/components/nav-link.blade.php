@props([
    'active'         => false,
    'icon'           => null,
    // neon | mag | pup | cya | slate (slate = neutral ghost when inactive)
    'variant'        => 'pup',
    // when true, label can be hidden by a parent x-data { sidebarCollapsed: true }
    'collapseAware'  => true,
])

@php
    // Brand → ring color + active pill class
    $map = [
        'neon'  => ['pill' => 'pill-neon',  'ring' => 'ring-[rgba(61,255,127,.30)]'],
        'mag'   => ['pill' => 'pill-mag',   'ring' => 'ring-[rgba(255,47,185,.30)]'],
        'pup'   => ['pill' => 'pill-pup',   'ring' => 'ring-[rgba(124,77,255,.30)]'],
        'cya'   => ['pill' => 'pill-cya',   'ring' => 'ring-[rgba(59,167,240,.30)]'],
        'slate' => ['pill' => 'bg-white/10 text-[var(--aio-ink)]', 'ring' => 'ring-white/10'],
    ];
    $c = $map[$variant] ?? $map['pup'];

    // Shared ghost look (works everywhere, no dynamic Tailwind needed)
    $base = 'aio-pill w-full justify-start flex items-center gap-2 px-3 py-2
             rounded-lg transition focus:outline-none focus:ring-2 focus:ring-offset-0
             border border-white/10 bg-white/5 text-[var(--aio-ink)]';

    // Active vs inactive
    $classes = $active
        // Active: brand pill + subtle ring + glow
        ? "{$base} {$c['pill']} shadow-glow ring-1 {$c['ring']}"
        // Inactive: neutral ghost; brighten on hover
        : "{$base} hover:bg-white/10";
@endphp

<a {{ $attributes->merge([
        'class' => $classes,
        'aria-current' => $active ? 'page' : null,
    ]) }}>
    @if($icon)
        <x-icon :name="$icon" class="w-5 h-5 shrink-0" />
    @endif

    @if ($collapseAware)
        <span class="truncate" x-show="!$root.sidebarCollapsed">{{ $slot }}</span>
    @else
        <span class="truncate">{{ $slot }}</span>
    @endif
</a>