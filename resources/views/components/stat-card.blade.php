@props([
  'title'    => '',
  'value'    => '',
  'icon'     => 'o-chart-bar',
  'hint'     => null,
  // neon | mag | pup | cya | slate
  'variant'  => 'pup',
  // extras
  'href'     => null,          
  'target'   => null,          
  'compact'  => false,         
  'stripe'   => true,          
  'delta'    => null,          
  'deltaUp'  => null,          
  'tooltip'  => null,          
  'aria'     => null,          
])

@php
$map = [
  'neon'  => [
    'pill'=>'bg-gradient-to-r from-green-400/30 to-emerald-300/30 text-[var(--aio-neon)]',
    'accent'=>'accent-neon',
    'ring'=>'ring-[rgba(61,255,127,.30)]',
    'txt'=>'text-[var(--aio-neon)]'
  ],
  'mag'   => [
    'pill'=>'bg-gradient-to-r from-pink-400/30 to-rose-300/30 text-[var(--aio-mag)]',
    'accent'=>'accent-mag',
    'ring'=>'ring-[rgba(255,47,185,.30)]',
    'txt'=>'text-[var(--aio-mag)]'
  ],
  'pup'   => [
    'pill'=>'bg-gradient-to-r from-purple-400/30 to-indigo-300/30 text-[var(--aio-pup)]',
    'accent'=>'accent-pup',
    'ring'=>'ring-[rgba(124,77,255,.30)]',
    'txt'=>'text-[var(--aio-pup)]'
  ],
  'cya'   => [
    'pill'=>'bg-gradient-to-r from-cyan-400/30 to-sky-300/30 text-[var(--aio-cya)]',
    'accent'=>'accent-cya',
    'ring'=>'ring-[rgba(59,167,240,.30)]',
    'txt'=>'text-[var(--aio-cya)]'
  ],
  'slate' => [
    'pill'=>'bg-gradient-to-r from-white/10 to-white/5 text-[var(--aio-ink)]',
    'accent'=>'bg-white/20',
    'ring'=>'ring-white/10',
    'txt'=>'text-[var(--aio-ink)]'
  ],
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