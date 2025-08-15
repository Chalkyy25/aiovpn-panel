@props([
  'title'    => null,
  'actions'  => null,    // right side header actions (buttons, tabs, etc.)
  'flush'    => false,   // no inner padding (for tables)
  'subtitle' => null,    // small helper text
])

<div {{ $attributes->merge(['class' => 'aio-card']) }}>
  @if($title || $actions || $subtitle)
    <div class="px-4 py-3 border-b aio-divider flex items-center justify-between">
      <div class="min-w-0">
        @if($title)
          <h2 class="font-semibold truncate">{{ $title }}</h2>
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