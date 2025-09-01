@props([
  'title'      => null,
  'actions'    => null,    // right side header actions
  'flush'      => false,   // no inner padding (for tables)
  'subtitle'   => null,    // small helper text
  'headerBlur' => false,   // apply a tiny blur only to the header strip
  'noBlur'     => true,    // keep the whole card non-blurred (recommended)
  'cardClass'  => '',      // extra classes for wrapper
])

@php
  // Solid, non-blurred card surface by default.
  // If you *really* want blur, set noBlur=false (not recommended for large blocks).
  $wrapper = trim('rounded-2xl border ' . $cardClass);

  if ($noBlur) {
      // Solid translucent background, no backdrop filter
      $wrapper .= ' bg-[rgba(17,24,39,0.90)] border-white/10';
  } else {
      // Frosted look (use sparingly!)
      $wrapper .= ' bg-white/5 border-white/10 backdrop-blur-sm';
  }

  // Header strip style; optional tiny blur just on the strip:
  $head = 'px-4 py-3 border-b border-white/10 flex items-center justify-between';
  if ($headerBlur) $head .= ' bg-white/5 backdrop-blur-sm';
@endphp

<div {{ $attributes->merge(['class' => $wrapper]) }}>
  @if($title || $actions || $subtitle)
    <div class="{{ $head }}">
      <div class="min-w-0">
        @if($title)
          <h2 class="font-semibold truncate text-[var(--aio-ink)]">{{ $title }}</h2>
        @endif
        @if($subtitle)
          <p class="text-xs text-[var(--aio-sub)] truncate">{{ $subtitle }}</p>
        @endif
      </div>
      @if($actions)
        <div class="flex items-center gap-2">
          {{ $actions }}
        </div>
      @endif
    </div>
  @endif

  <div class="{{ $flush ? '' : 'p-4' }}">
    {{ $slot }}
  </div>
</div>