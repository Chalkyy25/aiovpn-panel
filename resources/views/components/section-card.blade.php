@props([
  'title'      => null,
  'actions'    => null,   // right side header actions
  'flush'      => false,  // no inner padding (for tables)
  'subtitle'   => null,   // helper text
  'cardClass'  => '',     // extra classes for wrapper
])

@php
  $wrapper = trim("rounded-2xl border bg-[var(--aio-card)] border-[var(--aio-border)] {$cardClass}");
  $head    = "px-4 py-3 border-b border-[var(--aio-border)] flex items-center justify-between";
@endphp

<div {{ $attributes->merge(['class' => $wrapper]) }}>
  @if($title || $actions || $subtitle)
    <div class="{{ $head }}">
      <div class="min-w-0">
        @if($title)
          <h2 class="font-semibold truncate text-[var(--aio-ink)]">{{ $title }}</h2>
        @endif
        @if($subtitle)
          <p class="text-xs truncate text-[var(--aio-sub)]">{{ $subtitle }}</p>
        @endif
      </div>

      @if($actions)
        <div class="flex items-center gap-2 shrink-0">
          {{ $actions }}
        </div>
      @endif
    </div>
  @endif

  <div class="{{ $flush ? '' : 'p-4' }}">
    {{ $slot }}
  </div>
</div>