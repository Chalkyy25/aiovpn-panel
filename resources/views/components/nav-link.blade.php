@props([
  'href'          => '#',
  'active'        => false,
  'icon'          => null,
  'collapseAware' => true,
  'title'         => null,   // optional tooltip text when collapsed
])

@php
  $base = "group flex items-center gap-2 w-full px-3 py-2 rounded-md border
           transition-colors duration-150 focus:outline-none focus:ring-2
           focus:ring-[var(--aio-accent-weak)]";

  $inactive = "border-transparent
               text-[color-mix(in_srgb,var(--aio-ink)_72%,transparent)]
               hover:bg-[var(--aio-hover)]
               hover:border-[var(--aio-border)]
               hover:text-[var(--aio-ink)]";

  // Active: subtle, not a big blue slab.
  $activeCls = "bg-[var(--aio-accent-weak)]
                border-[color-mix(in_srgb,var(--aio-accent)_35%,transparent)]
                text-[var(--aio-ink)] font-semibold";

  $classes = $active ? "{$base} {$activeCls}" : "{$base} {$inactive}";

  // Tooltip when collapsed (desktop sidebar)
  $tooltip = $title ?? trim((string) $slot);
@endphp

<a href="{{ $href }}"
   title="{{ $collapseAware ? $tooltip : '' }}"
   @if($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => $classes]) }}>

  @if ($icon)
    <x-icon :name="$icon" class="w-5 h-5 shrink-0 text-current opacity-80 group-hover:opacity-100" />
  @endif

  @if ($collapseAware)
    <span class="truncate" x-show="!$root.sidebarCollapsed">{{ $slot }}</span>
  @else
    <span class="truncate">{{ $slot }}</span>
  @endif
</a>