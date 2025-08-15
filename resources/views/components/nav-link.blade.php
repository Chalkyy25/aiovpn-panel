@props(['active' => false, 'icon' => null])

@php
$base = 'group flex items-center gap-3 px-3 py-2 rounded-md transition';
$classes = $active
    ? $base.' bg-white/10 text-white shadow-glow'
    : $base.' text-[var(--aio-ink)]/70 hover:text-white hover:bg-white/10';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
  @if($icon)
    <x-icon :name="$icon" class="w-5 h-5 shrink-0 {{ $active ? 'text-[var(--aio-neon)]' : 'text-[var(--aio-sub)] group-hover:text-[var(--aio-cya)]' }}" />
  @endif
  <span class="truncate">{{ $slot }}</span>
</a>