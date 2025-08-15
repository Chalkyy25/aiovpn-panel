@props([
    'active'  => false,
    'icon'    => null,
    // neon | mag | pup | cya | slate
    'variant' => 'pup',
    // when true, you can hide labels in the sidebar via x-show on the parent
    'collapseAware' => true,
])

@php
$map = [
  'neon'  => ['pill' => 'pill-neon',  'ring' => 'ring-[rgba(61,255,127,.30)]'],
  'mag'   => ['pill' => 'pill-mag',   'ring' => 'ring-[rgba(255,47,185,.30)]'],
  'pup'   => ['pill' => 'pill-pup',   'ring' => 'ring-[rgba(124,77,255,.30)]'],
  'cya'   => ['pill' => 'pill-cya',   'ring' => 'ring-[rgba(59,167,240,.30)]'],
  'slate' => ['pill' => 'bg-white/10 text-[var(--aio-ink)]', 'ring' => 'ring-white/10'],
];
$c = $map[$variant] ?? $map['pup'];

$base = "aio-pill w-full justify-start flex items-center gap-2 px-3 py-2 transition ring-1 {$c['ring']}";
$state = $active
    ? "{$c['pill']} shadow-glow font-semibold"
    : "text-[var(--aio-ink)] hover:shadow-glow hover:{$c['pill']}";
@endphp

<a {{ $attributes->merge(['class' => "$base $state"]) }}>
  @if($icon)
    <x-icon :name="$icon" class="w-5 h-5 shrink-0" />
  @endif
  @if($collapseAware)
    <span class="truncate" x-show="!$root.sidebarCollapsed">{{ $slot }}</span>
  @else
    <span class="truncate">{{ $slot }}</span>
  @endif
</a>