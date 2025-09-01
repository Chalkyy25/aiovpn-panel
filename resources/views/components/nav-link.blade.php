@props([
    'href'           => '#',
    'active'         => false,
    'icon'           => null,
    // neon | mag | pup | cya | slate
    'variant'        => 'pup',
    // when true, you can hide labels in the sidebar via x-show on the parent
    'collapseAware'  => true,
])

@php
  $tones = [
    'neon'  => [
      'ring'   => 'ring-[rgba(61,255,127,.28)]',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'pill-neon text-[#0b0f1a]',
      'icon'   => 'text-[var(--aio-neon)]',
    ],
    'mag'   => [
      'ring'   => 'ring-[rgba(255,47,185,.28)]',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'pill-mag text-[#0b0f1a]',
      'icon'   => 'text-[var(--aio-mag)]',
    ],
    'pup'   => [
      'ring'   => 'ring-[rgba(124,77,255,.28)]',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'pill-pup text-[#0b0f1a]',
      'icon'   => 'text-[var(--aio-pup)]',
    ],
    'cya'   => [
      'ring'   => 'ring-[rgba(59,167,240,.28)]',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'pill-cya text-[#0b0f1a]',
      'icon'   => 'text-[var(--aio-cya)]',
    ],
    'slate' => [
      'ring'   => 'ring-white/10',
      'idle'   => 'bg-white/6 text-[var(--aio-ink)] hover:bg-white/10',
      'active' => 'bg-white/20 text-[var(--aio-ink)]',
      'icon'   => 'text-[var(--aio-sub)]',
    ],
  ];
  $t = $tones[$variant] ?? $tones['pup'];

  $base = "nav-pill flex items-center gap-2 w-full px-3 py-2 rounded-xl
           ring-1 {$t['ring']} shadow-[inset_0_0_0_1px_rgba(255,255,255,.06)]
           transition-colors duration-150";

  $state = $active ? "{$t['active']} shadow-glow font-semibold" : "{$t['idle']}";
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => "$base $state"]) }}>
  @if($icon)
    <x-icon :name="$icon" class="w-5 h-5 shrink-0 {{ $t['icon'] }}" />
  @endif

  @if($collapseAware)
    <span class="truncate" x-show="!$root.sidebarCollapsed">{{ $slot }}</span>
  @else
    <span class="truncate">{{ $slot }}</span>
  @endif
</a>