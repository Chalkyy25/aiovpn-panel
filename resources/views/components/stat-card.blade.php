@props([
  'title'    => '',
  'value'    => '',
  'icon'     => 'o-chart-bar',
  'hint'     => null,
  // neon | mag | pup | cya | slate
  'variant'  => 'pup',
  // extras
  'href'     => null,          // make the whole card a link
  'target'   => null,          // optional target for link
  'compact'  => false,         // tighter padding/size
  'stripe'   => true,          // show left accent bar
  'delta'    => null,          // e.g. +12% or -5
  'deltaUp'  => null,          // true/false; if null auto by sign of delta
  'tooltip'  => null,          // title attr
  'aria'     => null,          // aria-label override
])

@php
$map = [
  'neon'  => ['pill'=>'pill-neon','accent'=>'accent-neon','ring'=>'ring-[rgba(61,255,127,.30)]','txt'=>'text-[var(--aio-neon)]'],
  'mag'   => ['pill'=>'pill-mag', 'accent'=>'accent-mag', 'ring'=>'ring-[rgba(255,47,185,.30)]','txt'=>'text-[var(--aio-mag)]'],
  'pup'   => ['pill'=>'pill-pup', 'accent'=>'accent-pup', 'ring'=>'ring-[rgba(124,77,255,.30)]','txt'=>'text-[var(--aio-pup)]'],
  'cya'   => ['pill'=>'pill-cya', 'accent'=>'accent-cya', 'ring'=>'ring-[rgba(59,167,240,.30)]','txt'=>'text-[var(--aio-cya)]'],
  'slate' => ['pill'=>'bg-white/10 text-[var(--aio-ink)]','accent'=>'bg-white/20','ring'=>'ring-white/10','txt'=>'text-[var(--aio-ink)]'],
];
$c = $map[$variant] ?? $map['pup'];

$pad   = $compact ? 'px-3 py-2' : 'px-4 py-3';
$iconS = $compact ? 'h-8 w-8' : 'h-10 w-10';
$valS  = $compact ? 'text-xl' : 'text-2xl';

$delta = is_null($delta) ? null : (string)$delta;
if (!is_null($delta) && is_null($deltaUp)) {
  $deltaUp = !str_starts_with(trim($delta), '-') && trim($delta) !== '0';
}
$DeltaIcon = $deltaUp ? 'o-arrow-up-right' : 'o-arrow-down-right';

$base = "relative flex items-center gap-4 aio-card $pad ring-1 {$c['ring']} hover:shadow-glow transition";
$attrs = $attributes->merge([
  'class'     => $base,
  'title'     => $tooltip,
  'role'      => $href ? 'link' : 'group',
  'aria-label'=> $aria ?? ($title ? "{$title}: {$value}" : null),
]);
@endphp

@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} {{ $attrs }} @if($href) href="{{ $href }}" @endif @if($target) target="{{ $target }}" @endif>
  @if($stripe)
    <span class="absolute left-0 top-0 h-full w-1 rounded-l-xl {{ $c['accent'] }}"></span>
  @endif

  <div class="flex {{ $iconS }} items-center justify-center rounded-full {{ $c['pill'] }}">
    <x-icon :name="$icon" class="w-5 h-5" />
  </div>

  <div class="flex-1 min-w-0">
    <div class="{{ $valS }} font-semibold leading-none truncate">{{ $value }}</div>
    <div class="text-sm text-[var(--aio-sub)] truncate">{{ $title }}</div>
  </div>

  @if(!is_null($delta))
    <span class="inline-flex items-center gap-1 text-xs font-semibold {{ $deltaUp ? 'text-[var(--aio-neon)]' : 'text-[var(--aio-mag)]' }}">
      <x-icon :name="$DeltaIcon" class="w-4 h-4" />
      {{ $delta }}
    </span>
  @elseif($hint)
    <span class="text-xs text-[var(--aio-sub)] whitespace-nowrap">{{ $hint }}</span>
  @endif
</{{ $tag }}>