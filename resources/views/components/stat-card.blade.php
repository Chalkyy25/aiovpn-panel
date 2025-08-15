@props([
  'title'   => '',
  'value'   => '',
  'icon'    => 'o-chart-bar',
  'hint'    => null,
  // variants: neon | mag | pup | cya | slate
  'variant' => 'pup',
])

@php
$map = [
  'neon'  => ['pill' => 'pill-neon',  'accent' => 'accent-neon',  'ring' => 'ring-[rgba(61,255,127,.30)]'],
  'mag'   => ['pill' => 'pill-mag',   'accent' => 'accent-mag',   'ring' => 'ring-[rgba(255,47,185,.30)]'],
  'pup'   => ['pill' => 'pill-pup',   'accent' => 'accent-pup',   'ring' => 'ring-[rgba(124,77,255,.30)]'],
  'cya'   => ['pill' => 'pill-cya',   'accent' => 'accent-cya',   'ring' => 'ring-[rgba(59,167,240,.30)]'],
  'slate' => ['pill' => 'bg-white/10 text-aio-ink', 'accent' => 'bg-white/20', 'ring' => 'ring-white/10'],
];
$c = $map[$variant] ?? $map['pup'];
@endphp

<div {{ $attributes->merge(['class' => 'relative flex items-center gap-4 aio-card px-4 py-3 ring-1 '.$c['ring'].' hover:shadow-glow transition']) }}>
  <span class="absolute left-0 top-0 h-full w-1 rounded-l-xl {{ $c['accent'] }}"></span>

  <div class="flex h-10 w-10 items-center justify-center rounded-full {{ $c['pill'] }}">
    <x-icon :name="$icon" class="w-5 h-5" />
  </div>

  <div class="flex-1 min-w-0">
    <div class="text-2xl font-semibold leading-none truncate">{{ $value }}</div>
    <div class="text-sm text-[var(--aio-sub)] truncate">{{ $title }}</div>
  </div>

  @if($hint)
    <span class="text-xs text-[var(--aio-sub)] whitespace-nowrap">{{ $hint }}</span>
  @endif
</div>