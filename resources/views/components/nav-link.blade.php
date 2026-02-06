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

  $activeCls = "bg-[var(--aio-accent)]
                border-[var(--aio-accent)]
                text-white font-semibold
                shadow-lg shadow-[var(--aio-accent)]/25";

  $classes = $active ? "{$base} {$activeCls}" : "{$base} {$inactive}";
  $tooltip = $title ?? trim((string) $slot);

  // ✅ Normalize icon names:
  // - "o-home"  -> "heroicon-o-home"
  // - "s-user"  -> "heroicon-s-user"
  // - "heroicon-o-home" stays as-is
  $iconName = null;
  if (!empty($icon)) {
      $icon = (string) $icon;

      if (str_starts_with($icon, 'heroicon-')) {
          $iconName = $icon;
      } elseif (str_starts_with($icon, 'o-') || str_starts_with($icon, 's-')) {
          $iconName = 'heroicon-' . $icon;
      } else {
          // last resort: use as provided
          $iconName = $icon;
      }
  }
@endphp

<a href="{{ $href }}"
   title="{{ $collapseAware ? $tooltip : '' }}"
   @if($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => $classes]) }}>

  @if ($iconName)
    <x-icon :name="$iconName" class="w-5 h-5 shrink-0 text-current opacity-80 group-hover:opacity-100" />
  @endif

  @if ($collapseAware)
    <span class="truncate" x-show="!$root.sidebarCollapsed">{{ $slot }}</span>
  @else
    <span class="truncate">{{ $slot }}</span>
  @endif
</a>