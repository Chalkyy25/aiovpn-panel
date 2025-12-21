@props([
    'href'          => '#',
    'active'        => false,
    'icon'          => null,
    'collapseAware' => true,
])

@php
  // Plain, neutral defaults that work in light + dark via your CSS vars
  $base = "flex items-center gap-2 w-full px-3 py-2 rounded-md border border-transparent
           transition-colors duration-150";

  $inactive = "text-[color-mix(in_srgb,var(--aio-ink)_70%,transparent)]
               hover:bg-[var(--aio-hover)]
               hover:border-[var(--aio-border)]
               hover:text-[var(--aio-ink)]";

  $activeCls = "bg-[var(--aio-accent)] text-white border-transparent";

  $classes = $active ? "{$base} {$activeCls} font-semibold" : "{$base} {$inactive}";
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
  @if ($icon)
    <x-icon :name="$icon"
            class="w-5 h-5 shrink-0 {{ $active ? 'text-white' : 'text-[var(--aio-sub)]' }}" />
  @endif

  @if ($collapseAware)
    <span class="truncate" x-show="!$root.sidebarCollapsed">{{ $slot }}</span>
  @else
    <span class="truncate">{{ $slot }}</span>
  @endif
</a>