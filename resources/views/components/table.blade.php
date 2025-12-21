@props([
  'title' => null,
  'subtitle' => null,
  'actions' => null, // slot name
])

<div {{ $attributes->merge(['class' => 'aio-card overflow-hidden']) }}>
  @if($title || $subtitle || $actions)
    <div class="px-4 py-3 border-b border-[var(--aio-border)] flex items-center justify-between gap-3">
      <div class="min-w-0">
        @if($title)
          <h2 class="text-sm font-semibold text-[var(--aio-ink)] truncate">{{ $title }}</h2>
        @endif
        @if($subtitle)
          <p class="text-xs text-[var(--aio-sub)] truncate">{{ $subtitle }}</p>
        @endif
      </div>

      @if($actions)
        <div class="shrink-0">
          {{ $actions }}
        </div>
      @endif
    </div>
  @endif

  <div class="overflow-x-auto">
    <table class="aio-table">
      {{ $slot }}
    </table>
  </div>
</div>