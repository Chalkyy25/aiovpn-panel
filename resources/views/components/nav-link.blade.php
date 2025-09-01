@props([
    'href'          => '#',
    'active'        => false,   // route match overrides this
    'icon'          => null,
    // neon | mag | pup | cya | slate
    'variant'       => 'pup',
    'collapseAware' => true,
])

@php
  $tones = [
    'neon' => [
      'ring'   => 'ring-[rgba(61,255,127,.28)]',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'pill-neon active-neon',
      'icon'   => 'text-[var(--aio-neon)]',
    ],
    'mag' => [
      'ring'   => 'ring-[rgba(255,47,185,.28)]',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'pill-mag active-mag',
      'icon'   => 'text-[var(--aio-mag)]',
    ],
    'pup' => [
      'ring'   => 'ring-[rgba(124,77,255,.28)]',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'pill-pup active-pup',
      'icon'   => 'text-[var(--aio-pup)]',
    ],
    'cya' => [
      'ring'   => 'ring-[rgba(59,167,240,.28)]',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'pill-cya active-cya',
      'icon'   => 'text-[var(--aio-cya)]',
    ],
    'slate' => [
      'ring'   => 'ring-white/10',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'bg-white/20 text-[var(--aio-ink)] active-slate',
      'icon'   => 'text-[var(--aio-sub)]',
    ],
  ];

  $tone = $tones[$variant] ?? $tones['pup'];

  // Shared base
  $base = "nav-pill flex items-center gap-2 w-full px-3 py-2 rounded-xl
           ring-1 {$tone['ring']} shadow-[inset_0_0_0_1px_rgba(255,255,255,.06)]
           transition-all duration-200";

  // Active adds glow helpers
  $classes = $active
      ? "$base {$tone['active']} active font-semibold"
      : "$base {$tone['idle']}";
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
  @if ($icon)
    <x-icon :name="$icon" class="w-5 h-5 shrink-0 {{ $tone['icon'] }}" />
  @endif

  @if ($collapseAware)
    <span class="truncate" x-show="!$root.sidebarCollapsed">{{ $slot }}</span>
  @else
    <span class="truncate">{{ $slot }}</span>
  @endif
</a>